<?php
/**
 * Webhook integration.
 *
 * @package WPCortex
 */

namespace WPCortex\Integrations\Builtin;

use WPCortex\Integrations\Integration;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Posts each lead as JSON to a URL: Zapier, Make, n8n, a CRM's webhook endpoint or your
 * own code. The body is { event, changes, sent_at, lead } (lead: Leads\LeadPayload); with
 * a secret, the X-Cortex-Signature header holds "sha256=" and the HMAC-SHA256 of the body.
 */
final class Webhook extends Integration {

	/**
	 * Seconds to wait for the receiving server.
	 */
	private const TIMEOUT = 15;

	public function id(): string {
		return 'webhook';
	}

	public function label(): string {
		return __( 'Webhook', 'wp-cortex' );
	}

	public function description(): string {
		return __( 'Sends each lead as JSON to a URL, for Zapier, Make, n8n, a CRM that accepts webhooks or your own code.', 'wp-cortex' );
	}

	/**
	 * Settings fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'url'    => array(
				'type'        => 'url',
				'label'       => __( 'URL', 'wp-cortex' ),
				'description' => __( 'HTTPS address that receives a POST request with the lead as JSON. Addresses on the local network are refused.', 'wp-cortex' ),
				'placeholder' => 'https://hooks.example.com/…',
				'required'    => true,
			),
			'secret' => array(
				'type'        => 'secret',
				'label'       => __( 'Signing secret', 'wp-cortex' ),
				'description' => __( 'Optional. When set, the X-Cortex-Signature header holds "sha256=" followed by the HMAC-SHA256 of the request body with this secret, so the receiver can check that the request comes from this site.', 'wp-cortex' ),
			),
		);
	}

	/**
	 * Only HTTPS URLs.
	 *
	 * @param array $settings Sanitized settings.
	 * @return true|WP_Error
	 */
	public function validate( array $settings ) {
		$url = (string) ( $settings['url'] ?? '' );

		if ( '' === $url || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return new WP_Error( 'wp_cortex_webhook_url', __( 'Enter an HTTPS URL.', 'wp-cortex' ) );
		}

		return true;
	}

	/**
	 * Posts the lead.
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

		$body = (string) wp_json_encode(
			array(
				'event'   => $event,
				'changes' => array_values( $changes ),
				'sent_at' => gmdate( 'c' ),
				'lead'    => $payload,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		$headers = array(
			'Content-Type'   => 'application/json; charset=utf-8',
			'X-Cortex-Event' => 'lead.' . $event,
		);

		if ( '' !== (string) ( $settings['secret'] ?? '' ) ) {
			$headers['X-Cortex-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, (string) $settings['secret'] );
		}

		// wp_safe_remote_post() refuses local and private network addresses.
		$response = wp_safe_remote_post(
			(string) $settings['url'],
			array(
				'headers'     => $headers,
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

		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'wp_cortex_webhook_status', sprintf( __( 'The URL answered with HTTP status %d.', 'wp-cortex' ), $code ) );
		}

		return true;
	}
}
