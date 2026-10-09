<?php
/**
 * Sends leads to the active integrations in the background.
 *
 * @package WPCortex
 */

namespace WPCortex\Integrations;

use WPCortex\Chat\VisitorChatStore;
use WPCortex\Leads\LeadPayload;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to the lead actions and schedules one delivery per active integration on the
 * wp_cortex_deliver_lead cron hook, so the visitor's chat request is never slowed down by
 * a CRM. The lead is read again when the delivery runs, so it is sent as it is then. A
 * failed delivery is retried twice (after 5 and 25 minutes). Every attempt is logged in
 * the wp_cortex_integration_log option (the latest 50).
 */
final class Dispatcher {

	/**
	 * Cron hook of a delivery.
	 */
	public const CRON_HOOK = 'wp_cortex_deliver_lead';

	/**
	 * Option holding the latest deliveries.
	 */
	public const LOG_OPTION = 'wp_cortex_integration_log';

	/**
	 * Deliveries kept in the log.
	 */
	private const MAX_LOG = 50;

	/**
	 * Attempts per delivery.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Minutes before the first retry; each later retry waits five times longer.
	 */
	private const RETRY_MINUTES = 5;

	/**
	 * Hooks the lead actions and the cron hook.
	 */
	public function register(): void {
		add_action( 'wp_cortex_lead_created', array( $this, 'on_created' ), 10, 2 );
		add_action( 'wp_cortex_lead_updated', array( $this, 'on_updated' ), 10, 3 );
		add_action( self::CRON_HOOK, array( $this, 'deliver' ), 10, 5 );
	}

	/**
	 * A lead was created.
	 *
	 * @param array $payload Lead payload.
	 * @param int   $id      Conversation ID.
	 */
	public function on_created( array $payload, int $id ): void {
		self::schedule( $id, 'created', array() );
	}

	/**
	 * A lead changed.
	 *
	 * @param array    $payload Lead payload.
	 * @param string[] $changes What changed.
	 * @param int      $id      Conversation ID.
	 */
	public function on_updated( array $payload, array $changes, int $id ): void {
		self::schedule( $id, 'updated', $changes );
	}

	/**
	 * Schedules a delivery for every active integration that wants the event.
	 *
	 * @param int      $id      Conversation ID.
	 * @param string   $event   "created" or "updated".
	 * @param string[] $changes What changed.
	 */
	private static function schedule( int $id, string $event, array $changes ): void {
		foreach ( IntegrationRegistry::active() as $integration_id => $active ) {
			if ( in_array( $event, (array) $active['settings']['events'], true ) ) {
				wp_schedule_single_event( time(), self::CRON_HOOK, array( $integration_id, $id, $event, array_values( $changes ) ) );
			}
		}
	}

	/**
	 * Runs one delivery (cron). Skipped when leads or the integration were switched off
	 * meanwhile, or the conversation is no longer a lead.
	 *
	 * @param string   $integration_id Integration ID.
	 * @param int      $id             Conversation ID.
	 * @param string   $event          "created" or "updated".
	 * @param string[] $changes        What changed.
	 * @param int      $attempt        Attempt number, from 1.
	 */
	public function deliver( string $integration_id, int $id, string $event, array $changes, int $attempt = 1 ): void {
		$active = IntegrationRegistry::active()[ $integration_id ] ?? null;
		$chat   = $active ? ( new VisitorChatStore() )->get( $id ) : null;

		if ( ! $active || ! $chat || '' === $chat['lead_at'] ) {
			return;
		}

		unset( $chat['transcript'] );
		$result = self::send( $active['integration'], $event, LeadPayload::build( $chat ), $changes, $active['settings'] );

		self::log( $integration_id, $id, $event, $attempt, $result );

		if ( is_wp_error( $result ) && $attempt < self::MAX_ATTEMPTS ) {
			$delay = self::RETRY_MINUTES * ( 5 ** ( $attempt - 1 ) ) * MINUTE_IN_SECONDS;
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK, array( $integration_id, $id, $event, $changes, $attempt + 1 ) );
		}
	}

	/**
	 * Sends a made-up lead with the given settings (the Send test lead button); the
	 * settings need not be saved yet.
	 *
	 * @param Integration $integration Integration.
	 * @param array       $settings    Sanitized settings.
	 * @return true|WP_Error
	 */
	public static function test( Integration $integration, array $settings ) {
		$valid = $integration->validate( $settings );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! $integration->is_configured( $settings ) ) {
			return new WP_Error( 'wp_cortex_integration_incomplete', __( 'Fill in the required fields first.', 'wp-cortex' ) );
		}

		$result = self::send( $integration, 'test', LeadPayload::sample(), array(), $settings );
		self::log( $integration->id(), 0, 'test', 1, $result );

		return $result;
	}

	/**
	 * Calls the integration; an exception becomes a WP_Error.
	 *
	 * @param Integration $integration Integration.
	 * @param string      $event       Event.
	 * @param array       $payload     Lead payload.
	 * @param array       $changes     What changed.
	 * @param array       $settings    Settings.
	 * @return true|WP_Error
	 */
	private static function send( Integration $integration, string $event, array $payload, array $changes, array $settings ) {
		try {
			$result = $integration->send( $event, $payload, $changes, $settings );
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'wp_cortex_integration_exception', $e->getMessage() );
		}

		return true === $result || is_wp_error( $result ) ? $result : new WP_Error( 'wp_cortex_integration_result', __( 'The integration did not report whether the lead was sent.', 'wp-cortex' ) );
	}

	/**
	 * Adds a delivery to the log.
	 *
	 * @param string        $integration_id Integration ID.
	 * @param int           $id             Conversation ID (0 for a test).
	 * @param string        $event          Event.
	 * @param int           $attempt        Attempt number.
	 * @param true|WP_Error $result         Result.
	 */
	private static function log( string $integration_id, int $id, string $event, int $attempt, $result ): void {
		$log = self::entries();

		array_unshift(
			$log,
			array(
				'at'          => gmdate( 'Y-m-d H:i:s' ),
				'integration' => $integration_id,
				'lead'        => $id,
				'event'       => $event,
				'attempt'     => $attempt,
				'ok'          => ! is_wp_error( $result ),
				'message'     => is_wp_error( $result ) ? mb_substr( $result->get_error_message(), 0, 300 ) : '',
				'final'       => ! is_wp_error( $result ) || $attempt >= self::MAX_ATTEMPTS || 'test' === $event,
			)
		);

		update_option( self::LOG_OPTION, array_slice( $log, 0, self::MAX_LOG ), false );
	}

	/**
	 * Latest deliveries, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries(): array {
		$log = get_option( self::LOG_OPTION, array() );

		return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : array();
	}

	/**
	 * Whether WP-Cron does not run on page loads; deliveries then wait for the server's
	 * cron job.
	 */
	public static function cron_disabled(): bool {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}
}
