<?php
/**
 * Vector serialization.
 *
 * @package WPCortex
 */

namespace WPCortex\Embeddings;

defined( 'ABSPATH' ) || exit;

/**
 * Packs vectors as little-endian float32 BLOBs (the layout sqlite-vec also uses).
 */
final class VectorCodec {

	/**
	 * Vector to BLOB.
	 *
	 * @param float[] $vector Vector.
	 */
	public static function pack( array $vector ): string {
		return pack( 'g*', ...$vector );
	}

	/**
	 * BLOB to vector.
	 *
	 * @param string $blob Packed vector.
	 * @return float[]
	 */
	public static function unpack( string $blob ): array {
		return array_values( unpack( 'g*', $blob ) ?: array() );
	}
}
