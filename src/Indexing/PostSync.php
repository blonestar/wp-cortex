<?php
/**
 * Keeps the index up to date as content changes.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing;

use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Queues changed posts and indexes them in the background via WP-Cron.
 */
final class PostSync {

	public const CRON_HOOK = 'wp_cortex_process_queue';

	public const QUEUE_OPTION     = 'wp_cortex_sync_queue';
	public const LAST_SYNC_OPTION = 'wp_cortex_last_sync';

	private const BATCH = 50;

	/**
	 * Attachment meta keys that feed the media extractor.
	 */
	private const MEDIA_META_KEYS = array( '_wp_attachment_image_alt', '_wp_attachment_metadata', '_wp_attached_file' );

	/**
	 * Post IDs collected during this request.
	 *
	 * @var array<int, int>
	 */
	private array $pending = array();

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'process_queue' ) );

		if ( ! Settings::get( 'auto_sync' ) ) {
			return;
		}

		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 1 );
		add_action( 'deleted_post', array( $this, 'on_post_change' ), 10, 1 );
		add_action( 'trashed_post', array( $this, 'on_post_change' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'on_post_change' ), 10, 1 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'set_object_terms', array( $this, 'on_post_change' ), 10, 1 );
		add_action( 'added_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'wp_media_attach_action', array( $this, 'on_media_attach' ), 10, 2 );
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * Save hook.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_save_post( $post_id ): void {
		$this->queue( (int) $post_id );
		$this->queue_attachments( (int) $post_id );
	}

	/**
	 * Generic post change hook (first argument is the post ID).
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_post_change( $post_id ): void {
		$this->queue( (int) $post_id );
	}

	/**
	 * Status transition hook.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status && $post instanceof \WP_Post ) {
			$this->queue( $post->ID );
			$this->queue_attachments( $post->ID );
		}
	}

	/**
	 * Media library attach/detach hook.
	 *
	 * @param string $action        "attach" or "detach".
	 * @param int    $attachment_id Attachment ID.
	 */
	public function on_media_attach( $action, $attachment_id ): void {
		$this->queue( (int) $attachment_id );
	}

	/**
	 * Post meta hook; only Yoast keys, configured meta keys and (with media indexing)
	 * attachment meta keys matter.
	 *
	 * @param int|int[] $meta_id Meta ID(s).
	 * @param int       $post_id Post ID.
	 * @param string    $key     Meta key.
	 */
	public function on_meta_change( $meta_id, $post_id, $key ): void {
		if ( str_starts_with( (string) $key, '_yoast_wpseo_' )
			|| in_array( $key, (array) Settings::get( 'meta_keys' ), true )
			|| ( Settings::get( 'index_media' ) && in_array( $key, self::MEDIA_META_KEYS, true ) )
		) {
			$this->queue( (int) $post_id );
		}
	}

	/**
	 * Queues the media attached to a post: their inherited status (and so their
	 * eligibility) follows the post's status and password.
	 *
	 * @param int $post_id Parent post ID.
	 */
	private function queue_attachments( int $post_id ): void {
		if ( ! Settings::get( 'index_media' ) || $post_id <= 0 || 'attachment' === get_post_type( $post_id ) ) {
			return;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_parent'    => $post_id,
				'post_status'    => array( 'inherit', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		foreach ( $ids as $id ) {
			$this->queue( (int) $id );
		}
	}

	/**
	 * Adds a post to this request's pending list.
	 *
	 * @param int $post_id Post ID.
	 */
	private function queue( int $post_id ): void {
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Deleted posts no longer have a type; they are queued so index_posts can remove them.
		$type = get_post_type( $post_id );
		if ( false !== $type && ! in_array( $type, Settings::post_types(), true ) ) {
			return;
		}

		$this->pending[ $post_id ] = $post_id;
	}

	/**
	 * Persists pending IDs and schedules the background job. Runs on shutdown.
	 */
	public function flush(): void {
		if ( ! $this->pending ) {
			return;
		}

		$queue         = array_map( 'intval', (array) get_option( self::QUEUE_OPTION, array() ) );
		$queue         = array_values( array_unique( array_merge( $queue, array_values( $this->pending ) ) ) );
		$this->pending = array();

		update_option( self::QUEUE_OPTION, $queue, false );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 15, self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback: indexes a slice of the queue.
	 */
	public function process_queue(): void {
		if ( IndexRun::is_running() ) {
			wp_schedule_single_event( time() + 60, self::CRON_HOOK );
			return;
		}

		$queue = array_values( array_map( 'intval', (array) get_option( self::QUEUE_OPTION, array() ) ) );
		if ( ! $queue ) {
			return;
		}

		$ids       = array_slice( $queue, 0, self::BATCH );
		$remaining = array_slice( $queue, self::BATCH );

		update_option( self::QUEUE_OPTION, $remaining, false );

		try {
			$result = ( new Indexer() )->index_posts( $ids );
			$errors = $result['errors'];
		} catch ( \Throwable $e ) {
			$errors = array(
				array(
					'post_id' => 0,
					'message' => $e->getMessage(),
				),
			);
		}

		update_option(
			self::LAST_SYNC_OPTION,
			array(
				'time'   => time(),
				'count'  => count( $ids ),
				'errors' => array_slice( $errors, 0, 10 ),
			),
			false
		);

		if ( $remaining && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 15, self::CRON_HOOK );
		}
	}
}
