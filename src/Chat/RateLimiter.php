<?php
/**
 * Per-visitor message limit for the visitor chat.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Counts visitor chat messages per client IP (ClientIp) in a fixed one-hour window (transients).
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
		$key   = self::TRANSIENT_PREFIX . substr( wp_hash( ClientIp::get() ), 0, 32 );
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
}
