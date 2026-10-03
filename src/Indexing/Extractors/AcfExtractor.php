<?php
/**
 * Advanced Custom Fields data.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing\Extractors;

use WP_Post;
use WP_Term;
use WPCortex\Indexing\Document;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Flattens ACF values (groups, repeaters, flexible content) into dotted field names.
 * Long text values are also added as content sections so they are chunked and searchable.
 */
final class AcfExtractor implements Extractor {

	/**
	 * Text longer than this (in characters) becomes a content section as well.
	 */
	private const SECTION_MIN_LENGTH = 200;

	private const MAX_VALUE_LENGTH = 2000;

	public function is_available(): bool {
		return Settings::get( 'index_acf' ) && function_exists( 'get_field_objects' );
	}

	public function extract( WP_Post $post, Document $doc ): void {
		$objects = get_field_objects( $post->ID );
		if ( ! is_array( $objects ) ) {
			return;
		}

		$public = (bool) Settings::get( 'acf_public' );

		foreach ( $objects as $object ) {
			$label = (string) ( $object['label'] ?? $object['name'] );

			foreach ( $this->flatten( $object['name'], $object['value'] ?? null ) as $name => $value ) {
				$text = trim( wp_strip_all_tags( $value ) );
				if ( '' === $text ) {
					continue;
				}

				$doc->add_field( 'acf', $name, mb_substr( $text, 0, self::MAX_VALUE_LENGTH ), $public );

				if ( mb_strlen( $text ) >= self::SECTION_MIN_LENGTH ) {
					$doc->add_section( $label, $value, $public );
				}
			}
		}
	}

	/**
	 * Converts a (formatted) ACF value into scalar leaves.
	 *
	 * @param string $name  Dotted field path.
	 * @param mixed  $value Field value.
	 * @return array<string, string>
	 */
	private function flatten( string $name, $value ): array {
		if ( null === $value || false === $value || '' === $value ) {
			return array();
		}

		if ( $value instanceof WP_Post ) {
			return array( $name => $value->post_title );
		}

		if ( $value instanceof WP_Term ) {
			return array( $name => $value->name );
		}

		if ( is_scalar( $value ) ) {
			return array( $name => (string) $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		// Image/file arrays: the descriptive text is what matters, not the URLs and sizes.
		if ( isset( $value['mime_type'], $value['url'] ) ) {
			$text = trim( implode( ' ', array_filter( array( $value['title'] ?? '', $value['alt'] ?? '', $value['caption'] ?? '' ) ) ) );
			return '' === $text ? array() : array( $name => $text );
		}

		// Link fields.
		if ( isset( $value['url'], $value['title'] ) && count( $value ) <= 3 ) {
			return array( $name => trim( $value['title'] . ' ' . $value['url'] ) );
		}

		$leaves = array();
		foreach ( $value as $key => $item ) {
			if ( 'acf_fc_layout' === $key ) {
				continue;
			}
			$leaves += $this->flatten( $name . '.' . $key, $item );
		}

		return $leaves;
	}
}
