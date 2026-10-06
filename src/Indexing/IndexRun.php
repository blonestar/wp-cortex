<?php
/**
 * Full index run state machine.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing;

use WPCortex\Settings;
use WPCortex\Storage\Database;
use WPCortex\Storage\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks a resumable, batch-based run over all eligible posts. State lives in an
 * option so the admin UI, WP-CLI and the background worker (IndexWorker) all see the
 * same progress.
 */
final class IndexRun {

	public const OPTION      = 'wp_cortex_index_run';
	public const LOCK_OPTION = 'wp_cortex_index_lock';

	private const LOCK_TTL  = 300;
	private const MAX_ERROR = 50;

	/**
	 * Current (or last) run state.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function state(): ?array {
		// Always read from the database: another request (e.g. a cancel) may have changed it.
		wp_cache_delete( self::OPTION, 'options' );

		$state = get_option( self::OPTION, null );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Starts a new run.
	 *
	 * @param string $mode "sync" (update changed posts) or "rebuild" (start from empty databases).
	 * @return array<string, mixed> New state.
	 * @throws \InvalidArgumentException On unknown mode.
	 */
	public static function start( string $mode ): array {
		if ( ! in_array( $mode, array( 'sync', 'rebuild' ), true ) ) {
			throw new \InvalidArgumentException( "Unknown index run mode: $mode" );
		}

		if ( 'rebuild' === $mode ) {
			foreach ( Storage::SCOPES as $scope ) {
				Storage::delete_db( $scope );
			}
		}

		$state = array(
			'id'              => wp_generate_uuid4(),
			'mode'            => $mode,
			'status'          => 'running',
			'total'           => Indexer::count_eligible(),
			'processed'       => 0,
			'indexed'         => 0,
			'skipped'         => 0,
			'removed'         => 0,
			'failed'          => 0,
			'embedded_chunks' => 0,
			'tokens'          => 0,
			'cursor'          => 0,
			'errors'          => array(),
			'started_at'      => time(),
			'updated_at'      => time(),
			'paused_at'       => 0,
			'paused_seconds'  => 0,
			'finished_at'     => 0,
		);

		update_option( self::OPTION, $state, false );
		delete_option( self::LOCK_OPTION );

		return $state;
	}

	/**
	 * Processes one batch of the running run.
	 *
	 * @return array<string, mixed>|null State; includes "locked" => true when another request holds the lock.
	 */
	public static function batch(): ?array {
		$state = self::state();

		if ( ! $state || 'running' !== $state['status'] ) {
			return $state;
		}

		if ( ! self::acquire_lock() ) {
			$state['locked'] = true;
			return $state;
		}

		try {
			wp_raise_memory_limit( 'admin' );
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			// Re-read under the lock: the run may have been cancelled or restarted meanwhile.
			$state = self::state();
			if ( ! $state || 'running' !== $state['status'] ) {
				return $state;
			}

			try {
				$ids = Indexer::next_ids( (int) $state['cursor'], max( 1, (int) Settings::get( 'batch_size' ) ) );

				if ( ! $ids ) {
					foreach ( Storage::SCOPES as $scope ) {
						$state['removed'] += Database::get( $scope )->delete_stale( $state['id'] );
					}
					$state['status']      = 'completed';
					$state['finished_at'] = time();
				} else {
					$result = ( new Indexer() )->index_posts( $ids, $state['id'] );

					foreach ( array( 'indexed', 'skipped', 'removed', 'failed', 'embedded_chunks', 'tokens' ) as $key ) {
						$state[ $key ] += $result[ $key ];
					}
					$state['processed'] += count( $ids );
					$state['cursor']     = max( $ids );
					$state['errors']     = self::merge_errors( $state['errors'], $result['errors'] );
				}
			} catch ( \Throwable $e ) {
				$state['status']      = 'failed';
				$state['finished_at'] = time();
				$state['errors']      = array_slice(
					array_merge(
						$state['errors'],
						array(
							array(
								'post_id' => 0,
								'message' => $e->getMessage(),
							),
						)
					),
					-self::MAX_ERROR
				);
			}

			// The total can change while running (posts added or removed).
			$state['total']      = max( (int) $state['total'], (int) $state['processed'] );
			$state['updated_at'] = time();

			// Do not overwrite a cancel (or restart) issued while this batch was running.
			$current = self::state();
			if ( $current && ( $current['id'] !== $state['id'] || 'running' !== $current['status'] ) ) {
				return $current;
			}

			update_option( self::OPTION, $state, false );

			return $state;
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Cancels the running or paused run, if any.
	 */
	public static function cancel(): void {
		$state = self::state();

		if ( ! $state || ! in_array( $state['status'], array( 'running', 'paused' ), true ) ) {
			return;
		}

		$state                = self::end_pause( $state );
		$state['status']      = 'cancelled';
		$state['finished_at'] = time();
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Pauses the running run. A batch in progress finishes, but its result is discarded
	 * and those posts are processed again on resume.
	 */
	public static function pause(): void {
		$state = self::state();

		if ( ! $state || 'running' !== $state['status'] ) {
			return;
		}

		$state['status']    = 'paused';
		$state['paused_at'] = time();
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Resumes the paused run.
	 *
	 * @return bool Whether a paused run was resumed.
	 */
	public static function resume(): bool {
		$state = self::state();

		if ( ! $state || 'paused' !== $state['status'] ) {
			return false;
		}

		$state               = self::end_pause( $state );
		$state['status']     = 'running';
		$state['updated_at'] = time();
		update_option( self::OPTION, $state, false );

		return true;
	}

	/**
	 * Adds the time spent paused to the total and clears the pause start.
	 *
	 * @param array $state Run state.
	 * @return array Updated state.
	 */
	private static function end_pause( array $state ): array {
		if ( ! empty( $state['paused_at'] ) ) {
			$state['paused_seconds'] = (int) ( $state['paused_seconds'] ?? 0 ) + max( 0, time() - (int) $state['paused_at'] );
			$state['paused_at']      = 0;
		}

		return $state;
	}

	/**
	 * Whether a run is in progress.
	 */
	public static function is_running(): bool {
		$state = self::state();

		return $state && 'running' === $state['status'];
	}

	/**
	 * Whether a batch holds the lock (locks older than the TTL do not count).
	 */
	public static function is_locked(): bool {
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$taken = (int) get_option( self::LOCK_OPTION, 0 );

		return $taken > 0 && time() - $taken < self::LOCK_TTL;
	}

	/**
	 * Appends errors, dropping exact repeats (e.g. the same API error reported by every batch).
	 *
	 * @param array $errors Existing errors.
	 * @param array $new    New errors.
	 * @return array Most recent MAX_ERROR unique errors.
	 */
	private static function merge_errors( array $errors, array $new ): array {
		foreach ( $new as $error ) {
			if ( ! in_array( $error, $errors, true ) ) {
				$errors[] = $error;
			}
		}

		return array_slice( $errors, -self::MAX_ERROR );
	}

	/**
	 * Takes the batch lock; locks older than the TTL are considered stale and stolen.
	 */
	private static function acquire_lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		// Read from the database, not the object cache.
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$taken = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $taken > 0 && time() - $taken < self::LOCK_TTL ) {
			return false;
		}

		update_option( self::LOCK_OPTION, time(), false );

		return true;
	}
}
