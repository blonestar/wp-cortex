<?php
/**
 * Forwards a stored visitor chat by email.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

use WPCortex\Admin\Menu;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a conversation (summary, contact details, note and transcript) as a plain-text
 * email, with the visitor's images attached, through wp_mail(), so whatever handles the
 * site's mail (an SMTP plugin, a mail connector or the server's mailer) delivers it.
 */
final class VisitorChatMailer {

	public const MAX_RECIPIENTS = 10;
	public const MAX_MESSAGE    = 2000;

	/**
	 * Most images attached to one email (the latest are kept).
	 */
	private const MAX_ATTACHMENTS = 10;

	/**
	 * Valid, unique email addresses from a comma, semicolon or space separated list.
	 *
	 * @param string $list Addresses.
	 * @return string[]|WP_Error
	 */
	public static function parse_recipients( string $list ) {
		$valid = array();

		foreach ( preg_split( '/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) as $address ) {
			if ( ! is_email( $address ) ) {
				/* translators: %s: email address. */
				return new WP_Error( 'wp_cortex_invalid_email', sprintf( __( '"%s" is not a valid email address.', 'wp-cortex' ), $address ), array( 'status' => 400 ) );
			}

			$valid[ strtolower( sanitize_email( $address ) ) ] = sanitize_email( $address );
		}

		if ( ! $valid ) {
			return new WP_Error( 'wp_cortex_invalid_email', __( 'Enter at least one email address.', 'wp-cortex' ), array( 'status' => 400 ) );
		}

		if ( count( $valid ) > self::MAX_RECIPIENTS ) {
			/* translators: %d: maximum number of recipients. */
			return new WP_Error( 'wp_cortex_invalid_email', sprintf( __( 'Enter at most %d email addresses.', 'wp-cortex' ), self::MAX_RECIPIENTS ), array( 'status' => 400 ) );
		}

		return array_values( $valid );
	}

	/**
	 * Sends the conversation.
	 *
	 * @param array    $chat    Conversation from VisitorChatStore::get().
	 * @param string[] $to      Recipients.
	 * @param string   $message Optional message from the administrator.
	 * @return true|WP_Error
	 */
	public function forward( array $chat, array $to, string $message ) {
		$name    = VisitorChatReport::name( $chat );
		$email   = (string) ( ( (array) $chat['contact'] )['email'] ?? '' );
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = '' !== $name
			/* translators: 1: site name, 2: conversation ID, 3: visitor name. */
			? sprintf( __( '[%1$s] Visitor chat #%2$d: %3$s', 'wp-cortex' ), $site, $chat['id'], $name )
			/* translators: 1: site name, 2: conversation ID. */
			: sprintf( __( '[%1$s] Visitor chat #%2$d', 'wp-cortex' ), $site, $chat['id'] );

		if ( ! empty( $chat['forward_stale'] ) ) {
			/* translators: %s: email subject. */
			$subject = sprintf( __( '%s (update)', 'wp-cortex' ), $subject );
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		// Replies go straight to the visitor when they left an email address.
		if ( '' !== $email && is_email( $email ) ) {
			$headers[] = 'Reply-To: ' . ( '' !== $name ? '"' . str_replace( array( '"', "\r", "\n" ), '', $name ) . '" ' : '' ) . '<' . $email . '>';
		}

		$error   = null;
		$capture = static function ( WP_Error $e ) use ( &$error ) {
			$error = $e;
		};

		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( $to, $subject, $this->body( $chat, $message ), $headers, $this->attachments( $chat ) );
		remove_action( 'wp_mail_failed', $capture );

		if ( ! $sent ) {
			$detail = $error instanceof WP_Error ? ' ' . $error->get_error_message() : '';

			return new WP_Error( 'wp_cortex_mail_failed', __( 'The email could not be sent. Check the site\'s mail settings (for example an SMTP plugin).', 'wp-cortex' ) . $detail, array( 'status' => 502 ) );
		}

		return true;
	}

	/**
	 * Plain-text email body.
	 *
	 * @param array  $chat    Conversation.
	 * @param string $message Message from the administrator.
	 */
	private function body( array $chat, string $message ): string {
		$sections = array();
		$message  = trim( $message );
		$contact  = VisitorChatReport::contact( $chat );

		if ( '' !== $message ) {
			$sections[] = $message;
		}

		$status = $this->status( $chat );

		if ( '' !== $status ) {
			$sections[] = $status;
		}

		if ( '' !== trim( (string) $chat['summary'] ) ) {
			$title = __( 'Summary', 'wp-cortex' );

			if ( $chat['summary_stale'] ) {
				/* translators: %s: date and time (UTC) the summary was generated. */
				$title .= ' ' . sprintf( __( '(outdated: generated %s UTC, the conversation continued afterwards)', 'wp-cortex' ), $chat['summary_at'] );
			}

			$sections[] = $this->section( $title, (string) $chat['summary'] );
		}

		$sections[] = $this->section( __( 'Contact details', 'wp-cortex' ), '' !== $contact ? $contact : __( 'The visitor did not leave contact details.', 'wp-cortex' ) );

		if ( '' !== trim( (string) $chat['admin_note'] ) ) {
			$sections[] = $this->section( __( 'Note', 'wp-cortex' ), (string) $chat['admin_note'] );
		}

		$sections[] = $this->section( __( 'Conversation', 'wp-cortex' ), VisitorChatReport::details( $chat ) . "\n\n" . VisitorChatReport::transcript( $chat ) );

		$images = count( VisitorImages::paths( (int) $chat['id'], (array) $chat['transcript'] ) );

		if ( $images > self::MAX_ATTACHMENTS ) {
			/* translators: 1: number of attached images, 2: number of images in the conversation. */
			$sections[] = sprintf( __( 'The latest %1$d of %2$d images are attached; open the conversation in the admin to see all of them.', 'wp-cortex' ), self::MAX_ATTACHMENTS, $images );
		}

		$sections[] = __( 'Open in the admin:', 'wp-cortex' ) . ' ' . admin_url( 'admin.php?page=' . Menu::SLUG_VISITORS . '#chat=' . (int) $chat['id'] );

		return implode( "\n\n", $sections ) . "\n";
	}

	/**
	 * Notes about an update of an earlier email and a conversation that may continue.
	 *
	 * @param array $chat Conversation.
	 */
	private function status( array $chat ): string {
		$lines = array();

		if ( ! empty( $chat['forward_stale'] ) ) {
			$count   = (int) $chat['new_messages'];
			$lines[] = $count > 0
				/* translators: 1: date and time (UTC) of the previous email, 2: number of new visitor messages. */
				? sprintf( _n( 'Update: this conversation was already sent on %1$s UTC and has continued since then (%2$d new visitor message).', 'Update: this conversation was already sent on %1$s UTC and has continued since then (%2$d new visitor messages).', $count, 'wp-cortex' ), $chat['forwarded_at'], $count )
				/* translators: %s: date and time (UTC) of the previous email. */
				: sprintf( __( 'Update: this conversation was already sent on %s UTC and has changed since then.', 'wp-cortex' ), $chat['forwarded_at'] );
		}

		if ( VisitorChatStore::ACTIVITY_ACTIVE === ( $chat['activity'] ?? '' ) ) {
			$lines[] = __( 'The visitor was still in the chat when this was sent: the conversation may continue.', 'wp-cortex' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Images of the conversation to attach, the latest MAX_ATTACHMENTS.
	 *
	 * @param array $chat Conversation.
	 * @return string[] Absolute paths.
	 */
	private function attachments( array $chat ): array {
		return array_slice( VisitorImages::paths( (int) $chat['id'], (array) $chat['transcript'] ), -self::MAX_ATTACHMENTS );
	}

	/**
	 * Titled block of text.
	 *
	 * @param string $title Title.
	 * @param string $text  Text.
	 */
	private function section( string $title, string $text ): string {
		return $title . "\n" . str_repeat( '-', max( 3, mb_strlen( $title ) ) ) . "\n" . trim( $text );
	}
}
