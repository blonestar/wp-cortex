<?php
/**
 * Plain-text rendering of a stored visitor chat.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a conversation from VisitorChatStore::get() into plain text, used as the input
 * of the AI summary and as the body of forwarded emails.
 */
final class VisitorChatReport {

	/**
	 * Visitor's name from the contact details, empty when unknown.
	 *
	 * @param array $chat Conversation.
	 */
	public static function name( array $chat ): string {
		$contact = (array) $chat['contact'];

		return trim( ( $contact['first_name'] ?? '' ) . ' ' . ( $contact['last_name'] ?? '' ) );
	}

	/**
	 * Contact details, one "Label: value" line each.
	 *
	 * @param array $chat Conversation.
	 */
	public static function contact( array $chat ): string {
		$labels = array(
			'first_name' => __( 'First name', 'wp-cortex' ),
			'last_name'  => __( 'Last name', 'wp-cortex' ),
			'email'      => __( 'Email', 'wp-cortex' ),
			'phone'      => __( 'Phone', 'wp-cortex' ),
			'address'    => __( 'Address', 'wp-cortex' ),
			'company'    => __( 'Company', 'wp-cortex' ),
			'website'    => __( 'Website URL(s)', 'wp-cortex' ),
			'request'    => __( 'Request', 'wp-cortex' ),
		);
		$contact = (array) $chat['contact'];
		$lines   = array();

		foreach ( $labels as $key => $label ) {
			if ( ! empty( $contact[ $key ] ) ) {
				$lines[] = $label . ': ' . $contact[ $key ];
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Conversation details: dates, start page, IP addresses.
	 *
	 * @param array $chat Conversation.
	 */
	public static function details( array $chat ): string {
		$lines = array(
			/* translators: %s: date and time (UTC). */
			sprintf( __( 'Started: %s UTC', 'wp-cortex' ), $chat['created_at'] ),
			/* translators: %s: date and time (UTC). */
			sprintf( __( 'Last activity: %s UTC', 'wp-cortex' ), $chat['updated_at'] ),
		);

		if ( $chat['page'] ) {
			/* translators: 1: page title, 2: page URL. */
			$lines[] = sprintf( __( 'Started on: %1$s (%2$s)', 'wp-cortex' ), $chat['page']['title'], $chat['page']['url'] );
		}

		if ( '' !== $chat['ip'] ) {
			/* translators: %s: IP address. */
			$lines[] = sprintf( __( 'IP address: %s', 'wp-cortex' ), $chat['ip'] );
		}

		if ( '' !== $chat['ip_forwarded'] ) {
			/* translators: %s: IP addresses. */
			$lines[] = sprintf( __( 'Reported by proxy headers (unverified): %s', 'wp-cortex' ), $chat['ip_forwarded'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Transcript with timestamps, the page and attached image of each visitor message, sources
	 * and opened pages.
	 *
	 * @param array $chat Conversation.
	 */
	public static function transcript( array $chat ): string {
		$lines = array();

		foreach ( (array) $chat['transcript'] as $item ) {
			$at = ! empty( $item['at'] ) ? '[' . $item['at'] . '] ' : '';

			switch ( $item['role'] ?? '' ) {
				case 'user':
					$page    = ! empty( $item['page'] ) ? ' (' . sprintf( /* translators: %s: page title. */ __( 'on "%s"', 'wp-cortex' ), $item['page']['title'] ) . ')' : '';
					$image   = ! empty( $item['image'] ) ? ' [' . sprintf( /* translators: %s: image file name. */ __( 'image attached: %s', 'wp-cortex' ), $item['image'] ) . ']' : '';
					$lines[] = trim( $at . __( 'Visitor', 'wp-cortex' ) . $page . ': ' . $item['text'] . $image );
					break;
				case 'assistant':
					$lines[] = $at . __( 'Assistant', 'wp-cortex' ) . ': ' . $item['text'];
					break;
				case 'sources':
					$titles  = array_map( static fn( $s ) => ( $s['title'] ?? '' ) . ' (' . ( $s['url'] ?? '' ) . ')', (array) ( $item['sources'] ?? array() ) );
					$lines[] = '  ' . __( 'Sources shown:', 'wp-cortex' ) . ' ' . implode( ', ', $titles );
					break;
				case 'navigate':
					$lines[] = '  ' . __( 'Visitor taken to:', 'wp-cortex' ) . ' ' . ( $item['title'] ?? '' ) . ' (' . ( $item['url'] ?? '' ) . ')';
					break;
				case 'notice':
					$lines[] = '  ' . ( $item['text'] ?? '' );
					break;
				case 'error':
					$lines[] = $at . __( 'Error shown to the visitor:', 'wp-cortex' ) . ' ' . ( $item['text'] ?? '' );
					break;
			}
		}

		return implode( "\n", $lines );
	}
}
