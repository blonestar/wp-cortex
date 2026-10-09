<?php
/**
 * A lead in the format given to CRM integrations and the CSV export.
 *
 * @package WPCortex
 */

namespace WPCortex\Leads;

use WPCortex\Admin\Menu;
use WPCortex\Chat\VisitorChatStore;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the lead payload: contact details, status, AI rating and the first and latest
 * visit with every campaign parameter and ad click ID exactly as the visitor's link had
 * them. Keys are stable (every UTM parameter and click ID is present, empty when not
 * recorded), so integrations can map fields once. Fires the lead hooks once per lead
 * and request.
 */
final class LeadPayload {

	/**
	 * Version of the payload format; raised when keys change incompatibly.
	 */
	public const VERSION = 1;

	/**
	 * Lead events of this request, fired by flush(): ID => created flag and changes.
	 *
	 * @var array<int, array{created: bool, changes: array<string, string>}>
	 */
	private static array $pending = array();

	/**
	 * Builds the payload of a conversation that is a lead.
	 *
	 * @param array $chat Conversation from VisitorChatStore::get() or query_leads() with attribution.
	 * @return array<string, mixed>
	 */
	public static function build( array $chat ): array {
		$contact       = (array) ( $chat['contact'] ?? array() );
		$qualification = (array) ( $chat['qualification'] ?? array() );
		$attribution   = (array) ( $chat['attribution'] ?? array() );
		$id            = (int) $chat['id'];
		$rating        = null;

		if ( '' !== (string) ( $chat['lead_rating'] ?? '' ) ) {
			$rating = array(
				'rating'   => (string) $chat['lead_rating'],
				'score'    => (int) $chat['lead_score'],
				'intent'   => (string) $chat['lead_intent'],
				'rated_at' => (string) $chat['qualified_at'],
			);

			foreach ( array_keys( LeadQualifier::DETAILS ) as $key ) {
				$rating[ $key ] = (string) ( $qualification[ $key ] ?? '' );
			}
		}

		$payload = array(
			'version'          => self::VERSION,
			'id'               => $id,
			'site'             => home_url( '/' ),
			'lead_at'          => (string) $chat['lead_at'],
			'status'           => (string) $chat['lead_status'],
			'contact'          => array_map( static fn( $max ) => '', VisitorChatStore::CONTACT_FIELDS ),
			'rating'           => $rating,
			'attribution'      => null,
			'note'             => (string) ( $chat['admin_note'] ?? '' ),
			'started_at'       => (string) $chat['created_at'],
			'updated_at'       => (string) $chat['updated_at'],
			'message_count'    => (int) $chat['message_count'],
			'start_page'       => isset( $chat['page']['url'] ) ? (string) $chat['page']['url'] : '',
			'conversation_url' => admin_url( 'admin.php?page=' . Menu::SLUG_VISITORS . '#chat=' . $id ),
		);

		foreach ( array_keys( $payload['contact'] ) as $key ) {
			$payload['contact'][ $key ] = (string) ( $contact[ $key ] ?? '' );
		}

		if ( ! empty( $attribution['last'] ) ) {
			$last                   = self::touch( (array) $attribution['last'] );
			$payload['attribution'] = array(
				'first'   => ! empty( $attribution['first'] ) ? self::touch( (array) $attribution['first'] ) : $last,
				'last'    => $last,
				'visits'  => (int) ( $attribution['visits'] ?? 1 ),
				'consent' => (string) ( $attribution['consent'] ?? '' ),
				'device'  => (array) ( $attribution['device'] ?? array() ),
			);
		}

		/**
		 * Filters the lead payload given to CRM integrations and the CSV export.
		 *
		 * @param array $payload Lead payload.
		 * @param array $chat    Conversation.
		 */
		return (array) apply_filters( 'wp_cortex_lead_payload', $payload, $chat );
	}

	/**
	 * One visit: channel, source, medium, landing page and referrer (full URLs) and the
	 * URL parameters as recorded.
	 *
	 * @param array $touch Stored touch.
	 * @return array<string, mixed>
	 */
	private static function touch( array $touch ): array {
		$params  = (array) ( $touch['params'] ?? array() );
		$landing = (string) ( $touch['landing'] ?? '' );
		$utm     = array();
		$clicks  = array();
		$extra   = array();

		foreach ( array_keys( Attribution::UTM_PARAMS ) as $key ) {
			$utm[ $key ] = (string) ( $params[ $key ] ?? '' );
		}

		foreach ( array_keys( Attribution::CLICK_IDS ) as $key ) {
			$clicks[ $key ] = (string) ( $params[ $key ] ?? '' );
		}

		foreach ( $params as $key => $value ) {
			if ( ! isset( $utm[ $key ] ) && ! isset( $clicks[ $key ] ) ) {
				$extra[ $key ] = (string) $value;
			}
		}

		return array(
			'at'        => (string) ( $touch['at'] ?? '' ),
			'channel'   => (string) ( $touch['channel'] ?? '' ),
			'source'    => (string) ( $touch['source'] ?? '' ),
			'medium'    => (string) ( $touch['medium'] ?? '' ),
			'landing'   => '' !== $landing ? home_url( $landing ) : '',
			'referrer'  => (string) ( $touch['referrer'] ?? '' ),
			'utm'       => $utm,
			'click_ids' => $clicks,
			'extra'     => $extra,
		);
	}

	/**
	 * Notes that a conversation has just become a lead; wp_cortex_lead_created fires once,
	 * at the end of the request.
	 *
	 * @param int $id Conversation ID.
	 */
	public static function created( int $id ): void {
		self::queue( $id, '' );
	}

	/**
	 * Notes that a lead changed; wp_cortex_lead_updated fires once per lead at the end of
	 * the request, with every change of the request (none when the lead was also created).
	 *
	 * @param int    $id     Conversation ID.
	 * @param string $change What changed: "contact", "status" or "rating".
	 */
	public static function updated( int $id, string $change ): void {
		self::queue( $id, $change );
	}

	/**
	 * Queues a lead event for flush(), only while leads are on.
	 *
	 * @param int    $id     Conversation ID.
	 * @param string $change What changed, empty when the lead was created.
	 */
	private static function queue( int $id, string $change ): void {
		if ( ! Settings::leads_enabled() ) {
			return;
		}

		if ( ! self::$pending ) {
			add_action( 'shutdown', array( self::class, 'flush' ), 0 );
		}

		$entry = self::$pending[ $id ] ?? array(
			'created' => false,
			'changes' => array(),
		);

		if ( '' === $change ) {
			$entry['created'] = true;
		} else {
			$entry['changes'][ $change ] = $change;
		}

		self::$pending[ $id ] = $entry;
	}

	/**
	 * Fires the queued lead events, one per lead, with the lead as it is at the end of the
	 * request. Hooked to shutdown while events are queued.
	 */
	public static function flush(): void {
		$pending       = self::$pending;
		self::$pending = array();
		$store         = new VisitorChatStore();

		foreach ( $pending as $id => $entry ) {
			$hook = $entry['created'] ? 'wp_cortex_lead_created' : 'wp_cortex_lead_updated';

			if ( ! has_action( $hook ) ) {
				continue;
			}

			$chat = $store->get( $id );

			if ( ! $chat || '' === $chat['lead_at'] ) {
				continue;
			}

			unset( $chat['transcript'] );
			$payload = self::build( $chat );

			if ( $entry['created'] ) {
				/**
				 * Fires when a visitor conversation becomes a lead (the visitor left an email
				 * address, phone number, postal address or website), once per request, after
				 * the visitor's chat request is handled. Send slow requests (for example to a
				 * CRM) from a scheduled event, as the built-in integrations do.
				 *
				 * @param array $payload Lead payload (LeadPayload::build()).
				 * @param int   $id      Conversation ID.
				 */
				do_action( 'wp_cortex_lead_created', $payload, $id );
				continue;
			}

			/**
			 * Fires when a lead really changed, once per request: the visitor added or
			 * corrected contact details, an administrator changed its status or it was rated
			 * with AI. The payload always holds the whole lead, so integrations update the
			 * record by its ID.
			 *
			 * @param array    $payload Lead payload (LeadPayload::build()).
			 * @param string[] $changes What changed: "contact", "status" and/or "rating".
			 * @param int      $id      Conversation ID.
			 */
			do_action( 'wp_cortex_lead_updated', $payload, array_values( $entry['changes'] ), $id );
		}
	}

	/**
	 * A made-up lead for testing integrations, so no visitor's data is sent.
	 *
	 * @return array<string, mixed>
	 */
	public static function sample(): array {
		$now   = gmdate( 'Y-m-d H:i:s' );
		$touch = array(
			'at'       => $now,
			'channel'  => 'paid_search',
			'source'   => 'google',
			'medium'   => 'cpc',
			'landing'  => '/',
			'referrer' => 'https://www.google.com/',
			'params'   => array(
				'utm_source'   => 'google',
				'utm_medium'   => 'cpc',
				'utm_campaign' => 'test-campaign',
				'gclid'        => 'TEST-GCLID',
			),
		);

		$payload = self::build(
			array(
				'id'            => 0,
				'contact'       => array(
					'first_name' => 'Test',
					'last_name'  => 'Lead',
					'email'      => 'test@example.com',
					'phone'      => '+1 555 0100',
					'request'    => 'Test lead sent from WP Cortex.',
				),
				'qualification' => array( 'interest' => 'Testing the integration' ),
				'attribution'   => array(
					'first'   => $touch,
					'last'    => $touch,
					'visits'  => 1,
					'consent' => 'none',
				),
				'lead_at'       => $now,
				'lead_status'   => 'new',
				'lead_rating'   => 'warm',
				'lead_score'    => 60,
				'lead_intent'   => 'information',
				'qualified_at'  => $now,
				'admin_note'    => '',
				'created_at'    => $now,
				'updated_at'    => $now,
				'message_count' => 2,
				'page'          => null,
			)
		);

		$payload['test'] = true;

		return $payload;
	}
}
