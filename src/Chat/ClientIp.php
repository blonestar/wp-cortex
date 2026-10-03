<?php
/**
 * Visitor IP address, optionally from a trusted proxy header.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the visitor chat client IP.
 *
 * REMOTE_ADDR is the only address a visitor cannot forge. Behind a proxy or CDN it is the
 * proxy's address, so the site owner can pick the header the proxy sets
 * (public_chat_ip_header); it is then used for the message limit and as the stored IP.
 * Other forwarding headers are recorded as unverified information only.
 */
final class ClientIp {

	/**
	 * Proxy headers that can be trusted, keyed by setting value.
	 */
	public const HEADERS = array(
		'cf-connecting-ip' => 'HTTP_CF_CONNECTING_IP',
		'true-client-ip'   => 'HTTP_TRUE_CLIENT_IP',
		'x-real-ip'        => 'HTTP_X_REAL_IP',
		'x-forwarded-for'  => 'HTTP_X_FORWARDED_FOR',
	);

	/**
	 * Client IP used for the message limit and stored with the conversation.
	 */
	public static function get(): string {
		$ip     = self::remote_addr();
		$header = (string) Settings::get( 'public_chat_ip_header' );

		if ( isset( self::HEADERS[ $header ] ) ) {
			$from_header = self::first_ip( self::server( self::HEADERS[ $header ] ) );

			if ( '' !== $from_header ) {
				$ip = $from_header;
			}
		}

		/**
		 * Filters the visitor chat client IP (message limit and stored IP).
		 *
		 * @param string $ip Client IP: REMOTE_ADDR or the address from the trusted proxy header.
		 */
		return (string) apply_filters( 'wp_cortex_public_chat_client_ip', $ip );
	}

	/**
	 * Client IP and the unverified addresses reported by proxy headers, for the log.
	 *
	 * @return array{ip: string, ip_forwarded: string}
	 */
	public static function details(): array {
		$ip        = self::get();
		$forwarded = array();

		foreach ( self::present_headers() as $value ) {
			foreach ( array_map( 'trim', explode( ',', $value ) ) as $candidate ) {
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) && $candidate !== $ip ) {
					$forwarded[ $candidate ] = true;
				}
			}
		}

		return array(
			'ip'           => $ip,
			'ip_forwarded' => substr( implode( ', ', array_keys( $forwarded ) ), 0, 255 ),
		);
	}

	/**
	 * Proxy headers present on the current request (header name => value).
	 *
	 * @return array<string, string>
	 */
	public static function present_headers(): array {
		$present = array();

		foreach ( self::HEADERS as $name => $key ) {
			$value = self::server( $key );

			if ( '' !== $value ) {
				$present[ $name ] = $value;
			}
		}

		return $present;
	}

	/**
	 * REMOTE_ADDR of the current request.
	 */
	public static function remote_addr(): string {
		$ip = self::server( 'REMOTE_ADDR' );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * First valid IP of a comma-separated header value (the client in X-Forwarded-For).
	 *
	 * @param string $value Header value.
	 */
	private static function first_ip( string $value ): string {
		foreach ( array_map( 'trim', explode( ',', $value ) ) as $candidate ) {
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Sanitized $_SERVER value.
	 *
	 * @param string $key Key.
	 */
	private static function server( string $key ): string {
		return isset( $_SERVER[ $key ] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ), 0, 500 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	}
}
