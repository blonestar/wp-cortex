<?php
/**
 * Per-visitor message limit for the visitor chat.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Counts visitor chat messages per client IP in a fixed one-hour window (transients).
 */
final class RateLimiter {

	public const TRANSIENT_PREFIX = 'wp_cortex_rate_';

	private const WINDOW = HOUR_IN_SECONDS;

	/**
	 * Records one message for the client and tells whether it is within the limit.
	 * Messages over the limit are not counted.
	 *
	 * @param int $limit Messages allowed per hour.
	 */
	public static function allow( int $limit ): bool {
		$key   = self::TRANSIENT_PREFIX . substr( wp_hash( self::client_ip() ), 0, 32 );
		$entry = get_transient( $key );
		$now   = time();

		if ( ! is_array( $entry ) || ( (int) ( $entry['start'] ?? 0 ) + self::WINDOW ) <= $now ) {
			$entry = array(
				'count' => 0,
				'start' => $now,
			);
		}

		if ( (int) $entry['count'] >= $limit ) {
			return false;
		}

		++$entry['count'];
		set_transient( $key, $entry, max( 1, (int) $entry['start'] + self::WINDOW - $now ) );

		return true;
	}

	/**
	 * Client IP. Only REMOTE_ADDR is trusted; sites behind a proxy or CDN can supply the
	 * real address through the wp_cortex_public_chat_client_ip filter.
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP used to limit visitor chat messages.
		 *
		 * @param string $ip Client IP from REMOTE_ADDR.
		 */
		return (string) apply_filters( 'wp_cortex_public_chat_client_ip', $ip );
	}
}
