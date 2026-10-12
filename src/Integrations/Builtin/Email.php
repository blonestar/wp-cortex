<?php
/**
 * Email notification integration.
 *
 * @package WPCortex
 */

namespace WPCortex\Integrations\Builtin;

use WPCortex\Integrations\Integration;
use WPCortex\Integrations\LeadSummary;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Emails each lead as plain text to one or more addresses through wp_mail(), so whatever
 * handles the site's mail (for example an SMTP plugin) sends it. It needs no account
 * anywhere else. Replies go to the visitor when they left an email address.
 */
final class Email extends Integration {

	/**
	 * Most recipients.
	 */
	private const MAX_RECIPIENTS = 10;

	public function id(): string {
		return 'email';
	}

	public function label(): string {
		return __( 'Email notification', 'wp-cortex' );
	}

	public function description(): string {
		return __( 'Emails each lead to the addresses you choose, with its contact details, request, status, AI rating and source.', 'wp-cortex' );
	}

	/**
	 * Settings fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'to' => array(
				'type'        => 'text',
				'label'       => __( 'Send to', 'wp-cortex' ),
				/* translators: %d: number of addresses. */
				'description' => sprintf( __( 'One or more email addresses, separated by commas (up to %d). The email is sent by the site, like its other notifications; replying to it writes to the visitor when they left an email address.', 'wp-cortex' ), self::MAX_RECIPIENTS ),
				'placeholder' => 'sales@example.com',
				'required'    => true,
			),
		);
	}

	/**
	 * Every recipient must be an email address.
	 *
	 * @param array $settings Sanitized settings.
	 * @return true|WP_Error
	 */
	public function validate( array $settings ) {
		$recipients = $this->recipients( $settings );

		if ( ! $recipients ) {
			return new WP_Error( 'wp_cortex_email_to', __( 'Enter at least one email address.', 'wp-cortex' ) );
		}

		if ( count( $recipients ) > self::MAX_RECIPIENTS ) {
			/* translators: %d: number of addresses. */
			return new WP_Error( 'wp_cortex_email_to', sprintf( __( 'Enter at most %d email addresses.', 'wp-cortex' ), self::MAX_RECIPIENTS ) );
		}

		foreach ( $recipients as $recipient ) {
			if ( ! is_email( $recipient ) ) {
				/* translators: %s: what was entered. */
				return new WP_Error( 'wp_cortex_email_to', sprintf( __( '"%s" is not an email address.', 'wp-cortex' ), $recipient ) );
			}
		}

		return true;
	}

	/**
	 * Sends the email.
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

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( '[%s] %s', $site, LeadSummary::headline( $event, $payload ) );
		$subject = (string) preg_replace( '/\s+/', ' ', $subject );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$email   = (string) ( $payload['contact']['email'] ?? '' );

		// Replies go straight to the visitor when they left an email address.
		if ( 'test' !== $event && '' !== $email && is_email( $email ) ) {
			$headers[] = 'Reply-To: <' . $email . '>';
		}

		$error   = null;
		$capture = static function ( WP_Error $e ) use ( &$error ) {
			$error = $e;
		};

		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( $this->recipients( $settings ), $subject, $this->body( $event, $payload, $changes ), $headers );
		remove_action( 'wp_mail_failed', $capture );

		if ( ! $sent ) {
			$detail = $error instanceof WP_Error ? ' ' . $error->get_error_message() : '';

			return new WP_Error( 'wp_cortex_email_failed', __( 'The email could not be sent. Check the site\'s mail settings (for example an SMTP plugin).', 'wp-cortex' ) . $detail );
		}

		return true;
	}

	/**
	 * Addresses of the "Send to" setting.
	 *
	 * @param array $settings Settings.
	 * @return string[]
	 */
	private function recipients( array $settings ): array {
		$parts = preg_split( '/[\s,;]+/', (string) ( $settings['to'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );

		return array_values( array_unique( $parts ? $parts : array() ) );
	}

	/**
	 * Plain-text email body.
	 *
	 * @param string $event   Event.
	 * @param array  $payload Lead payload.
	 * @param array  $changes What changed for "updated".
	 */
	private function body( string $event, array $payload, array $changes ): string {
		$lines   = array();
		$changed = LeadSummary::changes( $changes );

		if ( 'test' === $event ) {
			$lines[] = __( 'This is a test with a made-up lead. Emails for real leads look like this one.', 'wp-cortex' );
			$lines[] = '';
		} elseif ( 'updated' === $event && '' !== $changed ) {
			/* translators: %s: what changed, for example "contact details, status". */
			$lines[] = sprintf( __( 'Changed: %s.', 'wp-cortex' ), $changed );
			$lines[] = '';
		}

		foreach ( LeadSummary::rows( $payload ) as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}

		if ( 'test' !== $event && '' !== (string) ( $payload['conversation_url'] ?? '' ) ) {
			$lines[] = '';
			/* translators: %s: URL. */
			$lines[] = sprintf( __( 'Conversation: %s', 'wp-cortex' ), (string) $payload['conversation_url'] );
		}

		return implode( "\n", $lines ) . "\n";
	}
}
