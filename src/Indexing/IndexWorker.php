<?php
/**
 * Background worker for full index runs.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing;

defined( 'ABSPATH' ) || exit;

/**
 * Processes the running IndexRun on the server, so it continues after the admin page is
 * closed. Each worker request runs batches for a limited time and then starts the next one
 * with a non-blocking loopback request to admin-ajax.php. A WP-Cron watchdog and the status
 * requests of the admin page restart the worker when it stops (for example after a fatal
 * error, or when the host blocks loopback requests).
 */
final class IndexWorker {

	public const ACTION    = 'wp_cortex_index_worker';
	public const CRON_HOOK = 'wp_cortex_index_watchdog';

	/**
	 * Seconds a worker request keeps starting new batches.
	 */
	private const TIME_BUDGET = 20;

	/**
	 * Seconds without a finished batch (and no batch holding the lock) after which the run
	 * counts as stalled.
	 */
	private const STALL_AFTER = 10;

	/**
	 * Seconds between watchdog checks.
	 */
	private const WATCHDOG_INTERVAL = 60;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( self::CRON_HOOK, array( self::class, 'watchdog' ) );
	}

	/**
	 * Starts a worker request in the background and schedules the watchdog.
	 */
	public static function dispatch(): void {
		$state = IndexRun::state();

		if ( ! $state || 'running' !== $state['status'] ) {
			return;
		}

		self::schedule_watchdog();

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
				'body'      => array(
					'action' => self::ACTION,
					'run'    => $state['id'],
					'token'  => self::token( (string) $state['id'] ),
				),
			)
		);
	}

	/**
	 * Restarts the worker when the running run has stalled.
	 *
	 * @param bool $inline Also process one batch in this request, so a page that polls the
	 *                     status keeps the run going where loopback requests do not work.
	 */
	public static function ensure( bool $inline = false ): void {
		if ( ! self::is_stalled() ) {
			return;
		}

		self::dispatch();

		if ( $inline ) {
			IndexRun::batch();
		}
	}

	/**
	 * Cron callback: restarts a stalled run (processing batches in the cron request itself)
	 * and checks again later while the run is in progress.
	 */
	public static function watchdog(): void {
		if ( ! IndexRun::is_running() ) {
			return;
		}

		if ( self::is_stalled() ) {
			self::work();
		}

		self::schedule_watchdog();
	}

	/**
	 * Ajax callback (loopback request): verifies the token and processes batches.
	 */
	public function handle(): void {
		$run   = isset( $_POST['run'] ) ? sanitize_text_field( wp_unslash( $_POST['run'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authenticated by the run token.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authenticated by the run token.
		$state = IndexRun::state();

		if ( ! $state || '' === $run || $run !== $state['id'] || ! hash_equals( self::token( $run ), $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		self::work();

		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Processes batches until the run ends, another request holds the lock or the time budget
	 * is used, then hands the rest of the run to a new worker request.
	 */
	private static function work(): void {
		$started = time();

		do {
			$state = IndexRun::batch();

			if ( ! $state || 'running' !== $state['status'] || ! empty( $state['locked'] ) ) {
				return;
			}
		} while ( time() - $started < self::TIME_BUDGET );

		self::dispatch();
	}

	/**
	 * Whether the run is in progress but nobody is processing it.
	 */
	private static function is_stalled(): bool {
		$state = IndexRun::state();

		if ( ! $state || 'running' !== $state['status'] || IndexRun::is_locked() ) {
			return false;
		}

		$last = (int) ( $state['updated_at'] ?? $state['started_at'] ?? 0 );

		return time() - $last >= self::STALL_AFTER;
	}

	/**
	 * Schedules the next watchdog check unless one is pending.
	 */
	private static function schedule_watchdog(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + self::WATCHDOG_INTERVAL, self::CRON_HOOK );
		}
	}

	/**
	 * Secret that authorizes worker requests for one run.
	 *
	 * @param string $run_id Run ID.
	 */
	private static function token( string $run_id ): string {
		return hash_hmac( 'sha256', self::ACTION . '|' . $run_id, wp_salt( 'nonce' ) );
	}
}
