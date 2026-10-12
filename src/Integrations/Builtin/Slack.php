<?php
/**
 * Slack integration.
 *
 * @package WPCortex
 */

namespace WPCortex\Integrations\Builtin;

use WPCortex\Integrations\Integration;
use WPCortex\Integrations\LeadSummary;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Posts each lead as a message to a Slack channel through an incoming webhook: a
 * headline, the lead's details and a button that opens the conversation. The webhook URL
 * decides the channel and is the only credential, so it is stored as a secret.
 */
final class Slack extends Integration {

	/**
	 * Seconds to wait for Slack.
	 */
	private const TIMEOUT = 15;

	/**
	 * Hosts of Slack's incoming webhooks.
	 */
	private const HOSTS = array( 'hooks.slack.com', 'hooks.slack-gov.com' );

	/**
	 * Longest value of one detail.
	 */
	private const MAX_VALUE = 700;

	/**
	 * Longest text of a message section (Slack allows 3000).
	 */
	private const MAX_TEXT = 2900;

	public function id(): string {
		return 'slack';
	}

	public function label(): string {
		return 'Slack';
	}

	public function description(): string {
		return __( 'Posts each lead to a Slack channel, with its contact details, request, status, AI rating and source.', 'wp-cortex' );
	}

	/**
	 * Settings fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'url' => array(
				'type'        => 'secret',
				'label'       => __( 'Webhook URL', 'wp-cortex' ),
				'description' => __( 'URL of an incoming webhook of a Slack app (api.slack.com/apps > your app > Incoming Webhooks > Add New Webhook), which also chooses the channel. Anyone with this URL can post to that channel, so it is not shown again once saved.', 'wp-cortex' ),
				'placeholder' => 'https://hooks.slack.com/services/…',
				'required'    => true,
			),
		);
	}

	/**
	 * Only Slack's incoming webhook URLs.
	 *
	 * @param array $settings Sanitized settings.
	 * @return true|WP_Error
	 */
	public function validate( array $settings ) {
		$parts = wp_parse_url( (string) ( $settings['url'] ?? '' ) );

		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || ! in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), self::HOSTS, true ) || ! str_starts_with( (string) ( $parts['path'] ?? '' ), '/services/' ) ) {
			return new WP_Error( 'wp_cortex_slack_url', __( 'Enter the URL of a Slack incoming webhook (https://hooks.slack.com/services/…).', 'wp-cortex' ) );
		}

		return true;
	}

	/**
	 * Posts the message.
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

		$response = wp_safe_remote_post(
			(string) $settings['url'],
			array(
				'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'        => (string) wp_json_encode( $this->message( $event, $payload, $changes ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'user-agent'  => 'WP Cortex/' . WP_CORTEX_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return true;
		}

		// Slack answers an error with a short code as text, for example channel_is_archived.
		$reason = mb_substr( trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ) ), 0, 100 );

		if ( 404 === $code || 410 === $code ) {
			/* translators: %s: Slack's error code. */
			return new WP_Error( 'wp_cortex_slack', trim( sprintf( __( 'Slack no longer accepts this webhook (removed, or its channel is archived). Create a new one. %s', 'wp-cortex' ), $reason ) ) );
		}

		/* translators: 1: HTTP status code, 2: Slack's error code. */
		return new WP_Error( 'wp_cortex_slack', trim( sprintf( __( 'Slack answered with HTTP status %1$d. %2$s', 'wp-cortex' ), $code, $reason ) ) );
	}

	/**
	 * The Slack message: plain text for notifications and blocks for the channel.
	 *
	 * @param string $event   Event.
	 * @param array  $payload Lead payload.
	 * @param array  $changes What changed for "updated".
	 * @return array<string, mixed>
	 */
	private function message( string $event, array $payload, array $changes ): array {
		$headline = LeadSummary::headline( $event, $payload );
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$intro    = '*' . $this->escape( $headline ) . '*';
		$changed  = LeadSummary::changes( $changes );
		$lines    = array();

		if ( 'test' === $event ) {
			$intro .= "\n" . $this->escape( __( 'This is a test with a made-up lead. Messages for real leads look like this one.', 'wp-cortex' ) );
		} elseif ( 'updated' === $event && '' !== $changed ) {
			/* translators: %s: what changed, for example "contact details, status". */
			$intro .= "\n" . $this->escape( sprintf( __( 'Changed: %s.', 'wp-cortex' ), $changed ) );
		}

		foreach ( LeadSummary::rows( $payload ) as $label => $value ) {
			if ( mb_strlen( $value ) > self::MAX_VALUE ) {
				$value = mb_substr( $value, 0, self::MAX_VALUE ) . '…';
			}

			$lines[] = '*' . $this->escape( $label ) . ':* ' . $this->escape( $value );
		}

		$blocks = array(
			$this->section( $intro ),
			$this->section( implode( "\n", $lines ) ),
		);
		$url    = (string) ( $payload['conversation_url'] ?? '' );

		if ( 'test' !== $event && '' !== $url ) {
			$blocks[] = array(
				'type'     => 'actions',
				'elements' => array(
					array(
						'type' => 'button',
						'text' => array(
							'type' => 'plain_text',
							'text' => __( 'Open conversation', 'wp-cortex' ),
						),
						'url'  => $url,
					),
				),
			);
		}

		$blocks[] = array(
			'type'     => 'context',
			'elements' => array(
				array(
					'type' => 'mrkdwn',
					'text' => $this->escape( $site . ' · WP Cortex' ),
				),
			),
		);

		return array(
			'text'   => $this->escape( sprintf( '[%s] %s', $site, $headline ) ),
			'blocks' => $blocks,
		);
	}

	/**
	 * A text section.
	 *
	 * @param string $text Escaped mrkdwn text.
	 * @return array<string, mixed>
	 */
	private function section( string $text ): array {
		return array(
			'type' => 'section',
			'text' => array(
				'type' => 'mrkdwn',
				'text' => mb_strlen( $text ) > self::MAX_TEXT ? mb_substr( $text, 0, self::MAX_TEXT ) . '…' : $text,
			),
		);
	}

	/**
	 * Escapes text for Slack. Without "<" a visitor's text cannot form a link or a mention
	 * such as <!channel>.
	 *
	 * @param string $text Plain text.
	 */
	private function escape( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}
}
