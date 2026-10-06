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
 * Meta keys explicitly listed in the settings (admin index only by default).
 */
final class MetaExtractor implements Extractor, DescribesFields {

	public function is_available(): bool {
		return ! empty( Settings::get( 'meta_keys' ) );
	}

	public function fields_label(): string {
		return __( 'Custom meta keys', 'wp-cortex' );
	}

	public function fields(): array {
		$fields = array();

		foreach ( (array) Settings::get( 'meta_keys' ) as $key ) {
			$fields[ 'meta:' . $key ] = array(
				'label'  => (string) $key,
				'public' => false,
			);
		}

		return $fields;
	}

	public function extract( WP_Post $post, Document $doc ): void {
		foreach ( (array) Settings::get( 'meta_keys' ) as $key ) {
			foreach ( get_post_meta( $post->ID, $key ) as $value ) {
				$doc->add_field( 'meta', $key, is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );
			}
		}
	}
}
