<?php
/**
 * Salesforce integration.
 *
 * @package WPCortex
 */

namespace WPCortex\Integrations\Builtin;

use WPCortex\Integrations\Integration;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a Salesforce lead for each new lead.
 *
 * The lead is built once, keyed by the API names of the Salesforce Lead fields (lead()),
 * and a delivery method sends it. The only method so far is Web-to-Lead, which needs
 * nothing but the organization ID: it can create leads but not update them, and
 * Salesforce does not report a lead it dropped later (a validation rule, a duplicate
 * rule, the daily limit).
 *
 * Adding a method (for example the REST API, which can update leads and reports errors):
 * add a "method" select field with "web_to_lead" as its default (so stored settings keep
 * working) and the method's own fields to fields(), return its events from
 * supported_events(), and send lead() from a send_<method>() of its own, chosen in send().
 */
final class Salesforce extends Integration {

	/**
	 * Web-to-Lead endpoint of production organizations.
	 */
	private const WEB_TO_LEAD_URL = 'https://webto.salesforce.com/servlet/servlet.WebToLead?encoding=UTF-8';

	/**
	 * Web-to-Lead endpoint of sandboxes.
	 */
	private const WEB_TO_LEAD_SANDBOX_URL = 'https://test.salesforce.com/servlet/servlet.WebToLead?encoding=UTF-8';

	/**
	 * Seconds to wait for Salesforce.
	 */
	private const TIMEOUT = 15;

	/**
	 * Standard Lead fields that are sent (API names) and their maximum lengths.
	 */
	private const STANDARD_FIELDS = array(
		'FirstName'   => 40,
		'LastName'    => 80,
		'Company'     => 255,
		'Email'       => 80,
		'Phone'       => 40,
		'Website'     => 255,
		'Street'      => 255,
		'Description' => 32000,
		'LeadSource'  => 255,
	);

	/**
	 * Web-to-Lead form names of the standard Lead fields.
	 */
	private const WEB_TO_LEAD_NAMES = array(
		'FirstName'   => 'first_name',
		'LastName'    => 'last_name',
		'Company'     => 'company',
		'Email'       => 'email',
		'Phone'       => 'phone',
		'Website'     => 'URL',
		'Street'      => 'street',
		'Description' => 'description',
		'LeadSource'  => 'lead_source',
	);

	/**
	 * Form names a custom field may not use: they steer the Web-to-Lead request.
	 */
	private const RESERVED_NAMES = array( 'oid', 'returl', 'debug', 'debugemail', 'encoding' );

	/**
	 * Longest value of a custom field.
	 */
	private const MAX_CUSTOM = 255;

	/**
	 * Salesforce requires a last name and a company on every lead.
	 */
	private const NOT_PROVIDED = '[not provided]';

	public function id(): string {
		return 'salesforce';
	}

	public function label(): string {
		return 'Salesforce';
	}

	public function description(): string {
		return __( 'Creates a Salesforce lead for each new lead through Web-to-Lead, with campaign parameters and ad click IDs in the custom fields you map.', 'wp-cortex' );
	}

	/**
	 * Settings fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'org_id'      => array(
				'type'        => 'text',
				'label'       => __( 'Organization ID', 'wp-cortex' ),
				'description' => __( 'Salesforce Setup > Company Information > Salesforce.com Organization ID (starts with 00D). Web-to-Lead must be on (Setup > Web-to-Lead). Send test lead creates a lead named Test Lead (test@example.com), which you can delete.', 'wp-cortex' ),
				'placeholder' => '00D…',
				'required'    => true,
			),
			'sandbox'     => array(
				'type'  => 'checkbox',
				'label' => __( 'This is a sandbox organization', 'wp-cortex' ),
			),
			'lead_source' => array(
				'type'        => 'text',
				'label'       => __( 'Lead source', 'wp-cortex' ),
				'description' => __( 'Value of the Lead Source field of every lead. Leave empty to send none.', 'wp-cortex' ),
				'default'     => 'Web',
			),
			'fields'      => array(
				'type'        => 'textarea',
				'label'       => __( 'Custom fields', 'wp-cortex' ),
				'description' => sprintf(
					/* translators: %s: list of value names. */
					__( 'Optional. One per line: a value of the lead, "=", and the Salesforce field that receives it. Web-to-Lead identifies a custom field by its ID (Setup > Object Manager > Lead > Fields & Relationships > the field; the ID starting with 00N is in the address bar). Values: %s; the campaign parameters (utm_source, utm_medium, utm_campaign, …), the ad click IDs (gclid, msclkid, fbclid, …) and your extra parameters, all from the latest visit; add "first_" for the first visit (first_utm_source).', 'wp-cortex' ),
					'lead_id, status, rating, score, intent, conversation_url, visits, channel, source, medium, landing, referrer'
				),
				'placeholder' => "gclid = 00N5g00000AbCdE\nutm_campaign = 00N5g00000FgHiJ",
			),
			'debug_email' => array(
				'type'        => 'text',
				'label'       => __( 'Debug email', 'wp-cortex' ),
				'description' => __( 'Optional, for setting up. Salesforce emails this address what it received for each lead and why a lead was not created. Salesforce does not tell the site when it drops a lead later (a validation rule, a duplicate rule or the daily Web-to-Lead limit), so the delivery log shows such a lead as sent. Empty it when everything works.', 'wp-cortex' ),
				'placeholder' => 'admin@example.com',
			),
		);
	}

	/**
	 * Web-to-Lead can only create leads: a change would create a second lead.
	 *
	 * @param array $settings This integration's settings.
	 * @return string[]
	 */
	public function supported_events( array $settings ): array {
		return array( 'created' );
	}

	/**
	 * Checks the organization ID, the custom field lines and the debug address.
	 *
	 * @param array $settings Sanitized settings.
	 * @return true|WP_Error
	 */
	public function validate( array $settings ) {
		if ( 1 !== preg_match( '/^00D[A-Za-z0-9]{12}(?:[A-Za-z0-9]{3})?$/', (string) ( $settings['org_id'] ?? '' ) ) ) {
			return new WP_Error( 'wp_cortex_salesforce_org', __( 'Enter the Salesforce organization ID: 15 or 18 letters and digits starting with 00D.', 'wp-cortex' ) );
		}

		$debug = (string) ( $settings['debug_email'] ?? '' );

		if ( '' !== $debug && ! is_email( $debug ) ) {
			return new WP_Error( 'wp_cortex_salesforce_debug', __( 'The debug email is not an email address.', 'wp-cortex' ) );
		}

		$map = $this->custom_fields( $settings );

		return is_wp_error( $map ) ? $map : true;
	}

	/**
	 * Creates the lead.
	 *
	 * @param string $event    "created", "updated" or "test".
	 * @param array  $payload  Lead payload.
	 * @param array  $changes  What changed for "updated".
	 * @param array  $settings Settings.
	 * @return true|WP_Error
	 */
	public function send( string $event, array $payload, array $changes, array $settings ) {
		$valid = $this->validate( $settings );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( 'test' !== $event && ! in_array( $event, $this->supported_events( $settings ), true ) ) {
			return new WP_Error( 'wp_cortex_salesforce_event', __( 'Web-to-Lead can only create leads, not update them.', 'wp-cortex' ) );
		}

		return $this->send_web_to_lead( $this->lead( $payload, $settings ), $settings );
	}

	/**
	 * The Salesforce lead of a payload, whatever the delivery method: the standard fields
	 * by API name and the custom fields as mapped in the settings. Empty values are left
	 * out, except the last name and the company, which Salesforce requires.
	 *
	 * @param array $payload  Lead payload.
	 * @param array $settings Settings.
	 * @return array{standard: array<string, string>, custom: array<string, string>}
	 */
	private function lead( array $payload, array $settings ): array {
		$contact     = (array) ( $payload['contact'] ?? array() );
		$description = trim( (string) ( $contact['request'] ?? '' ) );
		$url         = (string) ( $payload['conversation_url'] ?? '' );

		if ( empty( $payload['test'] ) && '' !== $url ) {
			/* translators: %s: URL. */
			$description = trim( $description . "\n\n" . sprintf( __( 'Conversation: %s', 'wp-cortex' ), $url ) );
		}

		$standard = array(
			'FirstName'   => (string) ( $contact['first_name'] ?? '' ),
			'LastName'    => (string) ( $contact['last_name'] ?? '' ),
			'Company'     => (string) ( $contact['company'] ?? '' ),
			'Email'       => (string) ( $contact['email'] ?? '' ),
			'Phone'       => (string) ( $contact['phone'] ?? '' ),
			'Website'     => (string) ( $contact['website'] ?? '' ),
			'Street'      => (string) ( $contact['address'] ?? '' ),
			'Description' => $description,
			'LeadSource'  => (string) ( $settings['lead_source'] ?? '' ),
		);

		foreach ( self::STANDARD_FIELDS as $field => $max ) {
			$standard[ $field ] = mb_substr( trim( $standard[ $field ] ), 0, $max );
		}

		$standard = array_filter( $standard, 'strlen' );

		foreach ( array( 'LastName', 'Company' ) as $required ) {
			if ( ! isset( $standard[ $required ] ) ) {
				$standard[ $required ] = self::NOT_PROVIDED;
			}
		}

		$custom = array();
		$values = $this->values( $payload );
		$map    = $this->custom_fields( $settings );

		foreach ( is_wp_error( $map ) ? array() : $map as $field => $key ) {
			$value = mb_substr( trim( (string) ( $values[ $key ] ?? '' ) ), 0, self::MAX_CUSTOM );

			if ( '' !== $value ) {
				$custom[ $field ] = $value;
			}
		}

		return array(
			'standard' => $standard,
			'custom'   => $custom,
		);
	}

	/**
	 * Values of a lead that can go into custom fields, by the names used in the "Custom
	 * fields" setting. Extra parameters never replace a built-in name.
	 *
	 * @param array $payload Lead payload.
	 * @return array<string, string>
	 */
	private function values( array $payload ): array {
		$site   = (string) preg_replace( '#^https?://#', '', untrailingslashit( home_url() ) );
		$values = array(
			'lead_id'          => $site . '#' . ( ! empty( $payload['test'] ) ? 'test' : (int) ( $payload['id'] ?? 0 ) ),
			'status'           => (string) ( $payload['status'] ?? '' ),
			'conversation_url' => (string) ( $payload['conversation_url'] ?? '' ),
		);

		if ( is_array( $payload['rating'] ?? null ) ) {
			$values['rating'] = (string) $payload['rating']['rating'];
			$values['score']  = (string) (int) $payload['rating']['score'];
			$values['intent'] = (string) $payload['rating']['intent'];
		}

		if ( ! is_array( $payload['attribution'] ?? null ) ) {
			return $values;
		}

		$values['visits'] = (string) (int) $payload['attribution']['visits'];

		foreach ( array( 'last' => '', 'first' => 'first_' ) as $touch => $prefix ) {
			$visit = (array) ( $payload['attribution'][ $touch ] ?? array() );
			$flat  = array_merge(
				(array) ( $visit['extra'] ?? array() ),
				array(
					'channel'  => (string) ( $visit['channel'] ?? '' ),
					'source'   => (string) ( $visit['source'] ?? '' ),
					'medium'   => (string) ( $visit['medium'] ?? '' ),
					'landing'  => (string) ( $visit['landing'] ?? '' ),
					'referrer' => (string) ( $visit['referrer'] ?? '' ),
				),
				(array) ( $visit['utm'] ?? array() ),
				(array) ( $visit['click_ids'] ?? array() )
			);

			foreach ( $flat as $key => $value ) {
				if ( ! isset( $values[ $prefix . $key ] ) ) {
					$values[ $prefix . $key ] = (string) $value;
				}
			}
		}

		return $values;
	}

	/**
	 * Parses the "Custom fields" setting.
	 *
	 * @param array $settings Settings.
	 * @return array<string, string>|WP_Error Salesforce field => lead value name.
	 */
	private function custom_fields( array $settings ) {
		$map   = array();
		$lines = preg_split( '/\R/', (string) ( $settings['fields'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );

		foreach ( $lines ? $lines : array() as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( 1 !== preg_match( '/^([A-Za-z0-9_.-]{1,60})\s*=\s*([A-Za-z0-9_]{1,80})$/', $line, $match ) || in_array( strtolower( $match[2] ), self::RESERVED_NAMES, true ) ) {
				/* translators: %s: the line as entered. */
				return new WP_Error( 'wp_cortex_salesforce_fields', sprintf( __( 'Custom fields: "%s" is not "value = Salesforce field" (for example gclid = 00N5g00000AbCdE).', 'wp-cortex' ), $line ) );
			}

			$map[ $match[2] ] = $match[1];
		}

		return $map;
	}

	/**
	 * Posts the lead to the Web-to-Lead endpoint.
	 *
	 * @param array $lead     Lead from lead().
	 * @param array $settings Settings.
	 * @return true|WP_Error
	 */
	private function send_web_to_lead( array $lead, array $settings ) {
		$body = array( 'oid' => (string) $settings['org_id'] );

		foreach ( $lead['standard'] as $field => $value ) {
			$body[ self::WEB_TO_LEAD_NAMES[ $field ] ] = $value;
		}

		// A custom field never replaces the organization ID or a standard field.
		$body += $lead['custom'];

		if ( '' !== (string) ( $settings['debug_email'] ?? '' ) ) {
			$body['debug']      = '1';
			$body['debugEmail'] = (string) $settings['debug_email'];
		}

		$response = wp_safe_remote_post(
			! empty( $settings['sandbox'] ) ? self::WEB_TO_LEAD_SANDBOX_URL : self::WEB_TO_LEAD_URL,
			array(
				'body'        => $body,
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'user-agent'  => 'WP Cortex/' . WP_CORTEX_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 400 ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'wp_cortex_salesforce', sprintf( __( 'Salesforce answered with HTTP status %d.', 'wp-cortex' ), $code ) );
		}

		// Salesforce answers 200 even when it refuses the request; the Is-Processed header then says so.
		$processed = wp_remote_retrieve_header( $response, 'is-processed' );
		$processed = trim( is_array( $processed ) ? implode( ' ', $processed ) : (string) $processed );

		if ( '' !== $processed && 0 !== stripos( $processed, 'true' ) ) {
			/* translators: %s: Salesforce's answer. */
			return new WP_Error( 'wp_cortex_salesforce', sprintf( __( 'Salesforce did not accept the lead. Check the organization ID, the sandbox setting and that Web-to-Lead is on. (%s)', 'wp-cortex' ), mb_substr( $processed, 0, 150 ) ) );
		}

		return true;
	}
}
