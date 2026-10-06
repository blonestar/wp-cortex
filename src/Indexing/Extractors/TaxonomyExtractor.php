<?php
/**
 * Taxonomy terms.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing\Extractors;

use WP_Post;
use WPCortex\Indexing\Document;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Assigned terms of every taxonomy registered for the post type. Public taxonomies
 * go into the public index as well by default.
 */
final class TaxonomyExtractor implements Extractor, DescribesFields {

	public function is_available(): bool {
		return true;
	}

	public function fields_label(): string {
		return __( 'Taxonomies', 'wp-cortex' );
	}

	public function fields(): array {
		$fields = array();

		foreach ( Settings::post_types() as $type ) {
			foreach ( get_object_taxonomies( $type, 'objects' ) as $taxonomy ) {
				$fields[ 'taxonomy:' . $taxonomy->name ] = array(
					'label'      => (string) ( $taxonomy->labels->name ?? $taxonomy->name ),
					'public'     => (bool) $taxonomy->public,
					'post_types' => array_values( (array) $taxonomy->object_type ),
				);
			}
		}

		return $fields;
	}

	public function extract( WP_Post $post, Document $doc ): void {
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$doc->add_field( 'taxonomy', $taxonomy->name, html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), (bool) $taxonomy->public );
			}
		}
	}
}
