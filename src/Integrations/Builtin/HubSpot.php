<?php
/**
 * HubSpot integration.
 *
 * @package WPCortex
 */

namespace WPCortex\Integrations\Builtin;

use WPCortex\Integrations\Integration;
use WPCortex\Leads\Attribution;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates or updates a HubSpot contact for each lead through a private app access token.
 *
 * The contact is matched by the custom unique property wp_cortex_lead_id (site and lead
 * ID), so a corrected email address updates the same contact. When the visitor is
 * already a contact with the same email address, that contact is updated instead. The
 * lead's status, rating, campaign parameters and ad click IDs of the first and the latest
 * visit go into custom contact properties of the "WP Cortex" group, which are created on
 * the first delivery. Empty contact details never clear what HubSpot already has.
 */
final class HubSpot extends Integration {

	/**
	 * HubSpot API.
	 */
	private const API = 'https://api.hubapi.com';

	/**
	 * Seconds to wait for HubSpot.
	 */
	private const TIMEOUT = 15;

	/**
	 * Property group of the custom properties.
	 */
	private const GROUP = 'wp_cortex';

	/**
	 * Unique contact property holding the lead key.
	 */
	private const KEY_PROPERTY = 'wp_cortex_lead_id';

	/**
	 * Transient remembering that the properties exist for a token (hash of the token and
	 * the property list).
	 */
	public const SCHEMA_TRANSIENT = 'wp_cortex_hubspot_schema';

	/**
	 * Lead contact fields and the standard HubSpot contact properties they fill.
	 */
	private const CONTACT_PROPERTIES = array(
		'email'      => 'email',
		'first_name' => 'firstname',
		'last_name'  => 'lastname',
		'phone'      => 'phone',
		'address'    => 'address',
		'company'    => 'company',
		'website'    => 'website',
	);

	public function id(): string {
		return 'hubspot';
	}

	public function label(): string {
		return 'HubSpot';
	}

	public function description(): string {
		return __( 'Creates or updates a HubSpot contact for each lead, with its status, AI rating, campaign parameters and ad click IDs.', 'wp-cortex' );
	}

	/**
	 * Settings fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'token' => array(
				'type'        => 'secret',
				'label'       => __( 'Access token', 'wp-cortex' ),
				'description' => __( 'Access token of a HubSpot private app (Development > Legacy apps, or Settings > Integrations > Private apps) with the scopes crm.objects.contacts.read, crm.objects.contacts.write, crm.schemas.contacts.read and crm.schemas.contacts.write. The first delivery creates the "WP Cortex" contact properties. Send test lead creates or updates a contact named Test Lead (test@example.com), which you can delete.', 'wp-cortex' ),
				'placeholder' => 'pat-…',
				'required'    => true,
			),
		);
	}

	/**
	 * Creates or updates the contact.
	 *
	 * @param string $event    "created", "updated" or "test".
	 * @param array  $payload  Lead payload.
	 * @param array  $changes  What changed for "updated".
	 * @param array  $settings Settings.
	 * @return true|WP_Error
	 */
	public function send( string $event, array $payload, array $changes, array $settings ) {
		$token  = (string) ( $settings['token'] ?? '' );
		$schema = $this->ensure_properties( $token );

		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		$key        = $this->lead_key( $payload );
		$properties = $this->properties( $payload );
		$result     = $this->upsert( $token, $key, $properties );

		// Someone deleted a property in HubSpot: create it again and retry once.
		if ( is_wp_error( $result ) && 'missing_property' === $result->get_error_code() ) {
			delete_transient( self::SCHEMA_TRANSIENT );
			$schema = $this->ensure_properties( $token );
			$result = is_wp_error( $schema ) ? $schema : $this->upsert( $token, $key, $properties );
		}

		return $result;
	}

	/**
	 * Upserts the contact by the lead key; when a new contact would duplicate the email
	 * address of an existing one, updates that contact.
	 *
	 * @param string $token      Access token.
	 * @param string $key        Lead key.
	 * @param array  $properties Contact properties.
	 * @return true|WP_Error
	 */
	private function upsert( string $token, string $key, array $properties ) {
		$response = $this->request(
			'POST',
			'/crm/v3/objects/contacts/batch/upsert',
			$token,
			array(
				'inputs' => array(
					array(
						'idProperty' => self::KEY_PROPERTY,
						'id'         => $key,
						'properties' => $properties,
					),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 === $response['code'] && empty( $response['body']['errors'] ) ) {
			return true;
		}

		$message = $this->error_message( $response );

		if ( 1 === preg_match( '/Existing ID:\s*(\d+)/i', $message, $match ) ) {
			$properties[ self::KEY_PROPERTY ] = $key;
			$update                           = $this->request( 'PATCH', '/crm/v3/objects/contacts/' . $match[1], $token, array( 'properties' => $properties ) );

			if ( is_wp_error( $update ) ) {
				return $update;
			}

			return 200 === $update['code'] ? true : $this->error( $update );
		}

		if ( false !== stripos( $message, 'PROPERTY_DOESNT_EXIST' ) || false !== stripos( $message, 'does not exist' ) ) {
			return new WP_Error( 'missing_property', $message );
		}

		return $this->error( $response );
	}

	/**
	 * Creates the "WP Cortex" property group and the missing custom properties, once per
	 * token and property list.
	 *
	 * @param string $token Access token.
	 * @return true|WP_Error
	 */
	private function ensure_properties( string $token ) {
		$definitions = $this->definitions();
		$hash        = md5( $token . '|' . implode( ',', array_keys( $definitions ) ) );

		if ( get_transient( self::SCHEMA_TRANSIENT ) === $hash ) {
			return true;
		}

		$existing = $this->request( 'GET', '/crm/v3/properties/contacts', $token );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( 200 !== $existing['code'] ) {
			return $this->error( $existing );
		}

		$names   = array_column( (array) ( $existing['body']['results'] ?? array() ), 'name' );
		$missing = array_diff_key( $definitions, array_flip( $names ) );

		if ( $missing ) {
			$group = $this->request(
				'POST',
				'/crm/v3/properties/contacts/groups',
				$token,
				array(
					'name'  => self::GROUP,
					'label' => 'WP Cortex',
				)
			);

			// 409: the group exists already.
			if ( is_wp_error( $group ) ) {
				return $group;
			}

			if ( 201 !== $group['code'] && 200 !== $group['code'] && 409 !== $group['code'] ) {
				return $this->error( $group );
			}

			$inputs = array();

			foreach ( $missing as $name => $definition ) {
				$inputs[] = array_merge(
					array(
						'name'      => $name,
						'groupName' => self::GROUP,
					),
					$definition
				);
			}

			$created = $this->request( 'POST', '/crm/v3/properties/contacts/batch/create', $token, array( 'inputs' => $inputs ) );

			if ( is_wp_error( $created ) ) {
				return $created;
			}

			if ( 201 !== $created['code'] && 200 !== $created['code'] ) {
				return $this->error( $created );
			}
		}

		set_transient( self::SCHEMA_TRANSIENT, $hash, WEEK_IN_SECONDS );

		return true;
	}

	/**
	 * Custom contact properties: name => type, field type and label.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function definitions(): array {
		$text        = array(
			'type'      => 'string',
			'fieldType' => 'text',
		);
		$number      = array(
			'type'      => 'number',
			'fieldType' => 'number',
		);
		$definitions = array(
			self::KEY_PROPERTY            => $text + array(
				'label'          => 'Cortex lead ID',
				'description'    => 'Site and ID of the WP Cortex lead. WP Cortex uses it to update this contact.',
				'hasUniqueValue' => true,
			),
			'wp_cortex_lead_status'       => $text + array( 'label' => 'Cortex lead status' ),
			'wp_cortex_lead_rating'       => $text + array( 'label' => 'Cortex lead rating' ),
			'wp_cortex_lead_score'        => $number + array( 'label' => 'Cortex lead score' ),
			'wp_cortex_lead_intent'       => $text + array( 'label' => 'Cortex lead intent' ),
			'wp_cortex_request'           => array(
				'type'      => 'string',
				'fieldType' => 'textarea',
				'label'     => 'Cortex request',
			),
			'wp_cortex_conversation_url'  => $text + array( 'label' => 'Cortex conversation' ),
			'wp_cortex_visits'            => $number + array( 'label' => 'Cortex visits before the lead' ),
		);

		foreach ( $this->touch_names() as $touch => $label ) {
			foreach ( array_merge( array( 'channel', 'landing', 'referrer' ), array_keys( Attribution::UTM_PARAMS ), array_keys( Attribution::CLICK_IDS ) ) as $key ) {
				$definitions[ 'wp_cortex_' . $touch . '_' . $key ] = $text + array( 'label' => 'Cortex ' . $label . ': ' . $key );
			}
		}

		return $definitions;
	}

	/**
	 * Visits sent to HubSpot and their label prefix.
	 *
	 * @return array<string, string>
	 */
	private function touch_names(): array {
		return array(
			'last'  => 'latest visit',
			'first' => 'first visit',
		);
	}

	/**
	 * Contact properties of a lead. Empty contact details are left out so they never clear
	 * HubSpot's values; the rating and the visits only when the lead has them.
	 *
	 * @param array $payload Lead payload.
	 * @return array<string, string>
	 */
	private function properties( array $payload ): array {
		$properties = array();
		$contact    = (array) ( $payload['contact'] ?? array() );

		foreach ( self::CONTACT_PROPERTIES as $field => $property ) {
			$value = trim( (string) ( $contact[ $field ] ?? '' ) );

			if ( '' !== $value ) {
				$properties[ $property ] = $value;
			}
		}

		$properties['wp_cortex_lead_status']      = (string) ( $payload['status'] ?? '' );
		$properties['wp_cortex_request']          = (string) ( $contact['request'] ?? '' );
		$properties['wp_cortex_conversation_url'] = (string) ( $payload['conversation_url'] ?? '' );

		if ( is_array( $payload['rating'] ?? null ) ) {
			$properties['wp_cortex_lead_rating'] = (string) $payload['rating']['rating'];
			$properties['wp_cortex_lead_score']  = (string) (int) $payload['rating']['score'];
			$properties['wp_cortex_lead_intent'] = (string) $payload['rating']['intent'];
		}

		if ( is_array( $payload['attribution'] ?? null ) ) {
			$properties['wp_cortex_visits'] = (string) (int) $payload['attribution']['visits'];

			foreach ( array_keys( $this->touch_names() ) as $touch ) {
				$visit = (array) ( $payload['attribution'][ $touch ] ?? array() );
				$flat  = array_merge(
					array(
						'channel'  => (string) ( $visit['channel'] ?? '' ),
						'landing'  => (string) ( $visit['landing'] ?? '' ),
						'referrer' => (string) ( $visit['referrer'] ?? '' ),
					),
					(array) ( $visit['utm'] ?? array() ),
					(array) ( $visit['click_ids'] ?? array() )
				);

				foreach ( $flat as $key => $value ) {
					$properties[ 'wp_cortex_' . $touch . '_' . $key ] = (string) $value;
				}
			}
		}

		return $properties;
	}

	/**
	 * Unique key of a lead across sites sharing one HubSpot account: the site address and
	 * the lead ID ("test" for the test lead).
	 *
	 * @param array $payload Lead payload.
	 */
	private function lead_key( array $payload ): string {
		$site = (string) preg_replace( '#^https?://#', '', untrailingslashit( home_url() ) );

		return $site . '#' . ( ! empty( $payload['test'] ) ? 'test' : (int) $payload['id'] );
	}

	/**
	 * Calls the HubSpot API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   API path.
	 * @param string     $token  Access token.
	 * @param array|null $body   JSON body.
	 * @return array{code: int, body: array}|WP_Error
	 */
	private function request( string $method, string $path, string $token, ?array $body = null ) {
		$args = array(
			'method'      => $method,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'user-agent'  => 'WP Cortex/' . WP_CORTEX_VERSION . '; ' . home_url( '/' ),
		);

		if ( null !== $body ) {
			$args['body'] = (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		$response = wp_remote_request( self::API . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => is_array( $decoded ) ? $decoded : array(),
		);
	}

	/**
	 * HubSpot's error message of a response, including the errors of a batch.
	 *
	 * @param array $response Response from request().
	 */
	private function error_message( array $response ): string {
		$messages = array();

		if ( ! empty( $response['body']['message'] ) ) {
			$messages[] = (string) $response['body']['message'];
		}

		foreach ( (array) ( $response['body']['errors'] ?? array() ) as $error ) {
			if ( is_array( $error ) && ! empty( $error['message'] ) ) {
				$messages[] = (string) $error['message'];
			}
		}

		return implode( ' ', array_unique( $messages ) );
	}

	/**
	 * A failed response as a WP_Error, with a hint for a refused token or missing scopes.
	 *
	 * @param array $response Response from request().
	 */
	private function error( array $response ): WP_Error {
		$message = $this->error_message( $response );

		if ( 401 === $response['code'] ) {
			$message = __( 'HubSpot refused the access token.', 'wp-cortex' );
		} elseif ( 403 === $response['code'] ) {
			/* translators: %s: HubSpot's message. */
			$message = sprintf( __( 'The access token is missing a scope (crm.objects.contacts.read, crm.objects.contacts.write, crm.schemas.contacts.read, crm.schemas.contacts.write). %s', 'wp-cortex' ), $message );
		} elseif ( '' === $message ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'HubSpot answered with HTTP status %d.', 'wp-cortex' ), $response['code'] );
		}

		return new WP_Error( 'wp_cortex_hubspot', trim( $message ) );
	}
}
