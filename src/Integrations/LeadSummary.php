<?php
/**
 * A lead as a headline and labelled lines, for integrations read by people.
 *
 * @package WPCortex
 */

namespace WPCortex\Integrations;

use WPCortex\Admin\LeadsPage;
use WPCortex\Leads\Attribution;
use WPCortex\Leads\LeadQualifier;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a lead payload into text for notifications (email, Slack): a headline and the
 * filled-in details as label => value. Values are plain text; the integration escapes
 * them for its own format.
 */
final class LeadSummary {

	/**
	 * Name of the lead: first and last name, else the email address, phone number or
	 * company.
	 *
	 * @param array $payload Lead payload.
	 */
	public static function name( array $payload ): string {
		$contact = (array) ( $payload['contact'] ?? array() );
		$name    = trim( (string) ( $contact['first_name'] ?? '' ) . ' ' . (string) ( $contact['last_name'] ?? '' ) );

		foreach ( array( 'email', 'phone', 'company' ) as $key ) {
			if ( '' === $name ) {
				$name = trim( (string) ( $contact[ $key ] ?? '' ) );
			}
		}

		return $name;
	}

	/**
	 * Headline: what happened and to which lead.
	 *
	 * @param string $event   "created", "updated" or "test".
	 * @param array  $payload Lead payload.
	 */
	public static function headline( string $event, array $payload ): string {
		$name = self::name( $payload );

		if ( 'test' === $event ) {
			/* translators: %s: lead name. */
			return sprintf( __( 'Test lead: %s', 'wp-cortex' ), $name );
		}

		if ( 'updated' === $event ) {
			/* translators: %s: lead name. */
			return '' !== $name ? sprintf( __( 'Lead updated: %s', 'wp-cortex' ), $name ) : __( 'Lead updated', 'wp-cortex' );
		}

		/* translators: %s: lead name. */
		return '' !== $name ? sprintf( __( 'New lead: %s', 'wp-cortex' ), $name ) : __( 'New lead', 'wp-cortex' );
	}

	/**
	 * What changed, as a sentence part ("contact details, status"); empty when unknown.
	 *
	 * @param string[] $changes Changes of an "updated" event.
	 */
	public static function changes( array $changes ): string {
		$labels = array(
			'contact' => __( 'contact details', 'wp-cortex' ),
			'status'  => __( 'status', 'wp-cortex' ),
			'rating'  => __( 'AI rating', 'wp-cortex' ),
		);

		return implode( ', ', array_values( array_intersect_key( $labels, array_flip( array_map( 'strval', $changes ) ) ) ) );
	}

	/**
	 * Details of the lead that are filled in, in reading order: contact details, the
	 * request, status, AI rating and how the visitor found the site.
	 *
	 * @param array $payload Lead payload.
	 * @return array<string, string> Label => value.
	 */
	public static function rows( array $payload ): array {
		$contact = (array) ( $payload['contact'] ?? array() );
		$rows    = array(
			__( 'Name', 'wp-cortex' )    => trim( (string) ( $contact['first_name'] ?? '' ) . ' ' . (string) ( $contact['last_name'] ?? '' ) ),
			__( 'Email', 'wp-cortex' )   => (string) ( $contact['email'] ?? '' ),
			__( 'Phone', 'wp-cortex' )   => (string) ( $contact['phone'] ?? '' ),
			__( 'Company', 'wp-cortex' ) => (string) ( $contact['company'] ?? '' ),
			__( 'Website', 'wp-cortex' ) => (string) ( $contact['website'] ?? '' ),
			__( 'Address', 'wp-cortex' ) => (string) ( $contact['address'] ?? '' ),
			__( 'Request', 'wp-cortex' ) => (string) ( $contact['request'] ?? '' ),
		);

		$status                            = (string) ( $payload['status'] ?? '' );
		$rows[ __( 'Status', 'wp-cortex' ) ] = LeadsPage::status_labels()[ $status ] ?? $status;

		if ( is_array( $payload['rating'] ?? null ) ) {
			$rating  = $payload['rating'];
			$ratings = array(
				'hot'  => __( 'Hot', 'wp-cortex' ),
				'warm' => __( 'Warm', 'wp-cortex' ),
				'cold' => __( 'Cold', 'wp-cortex' ),
			);
			$intent  = (string) ( $rating['intent'] ?? '' );

			$rows[ __( 'AI rating', 'wp-cortex' ) ] = sprintf( '%s (%d)', $ratings[ (string) $rating['rating'] ] ?? (string) $rating['rating'], (int) ( $rating['score'] ?? 0 ) );
			$rows[ __( 'Intent', 'wp-cortex' ) ]    = LeadQualifier::intent_labels()[ $intent ] ?? $intent;
			$rows[ __( 'Interest', 'wp-cortex' ) ]  = (string) ( $rating['interest'] ?? '' );
			$rows[ __( 'Budget', 'wp-cortex' ) ]    = (string) ( $rating['budget'] ?? '' );
			$rows[ __( 'Timeline', 'wp-cortex' ) ]  = (string) ( $rating['timeline'] ?? '' );
			$rows[ __( 'Next step', 'wp-cortex' ) ] = (string) ( $rating['next_step'] ?? '' );
		}

		if ( is_array( $payload['attribution'] ?? null ) ) {
			$visit   = (array) ( $payload['attribution']['last'] ?? array() );
			$channel = (string) ( $visit['channel'] ?? '' );
			$source  = implode( ' / ', array_filter( array( (string) ( $visit['source'] ?? '' ), (string) ( $visit['medium'] ?? '' ) ), 'strlen' ) );
			$channel = Attribution::channel_labels()[ $channel ] ?? $channel;

			$rows[ __( 'Channel', 'wp-cortex' ) ]      = '' !== $source && '' !== $channel ? sprintf( '%s (%s)', $channel, $source ) : $channel . $source;
			$rows[ __( 'Campaign', 'wp-cortex' ) ]     = (string) ( $visit['utm']['utm_campaign'] ?? '' );
			$rows[ __( 'Landing page', 'wp-cortex' ) ] = (string) ( $visit['landing'] ?? '' );
			$rows[ __( 'Referrer', 'wp-cortex' ) ]     = (string) ( $visit['referrer'] ?? '' );
		}

		return array_filter( array_map( 'trim', $rows ), 'strlen' );
	}
}
