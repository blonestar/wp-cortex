<?php
/**
 * Custom post meta.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing\Extractors;

use WP_Post;
use WPCortex\Indexing\Document;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Meta keys explicitly listed in the settings (admin index only).
 */
final class MetaExtractor implements Extractor {

	public function is_available(): bool {
		return ! empty( Settings::get( 'meta_keys' ) );
	}

	public function extract( WP_Post $post, Document $doc ): void {
		foreach ( (array) Settings::get( 'meta_keys' ) as $key ) {
			foreach ( get_post_meta( $post->ID, $key ) as $value ) {
				$doc->add_field( 'meta', $key, is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );
			}
		}
	}
}
