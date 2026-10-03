<?php
/**
 * Taxonomy terms.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing\Extractors;

use WP_Post;
use WPCortex\Indexing\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Assigned terms of every taxonomy registered for the post type. Public taxonomies
 * go into the public index as well.
 */
final class TaxonomyExtractor implements Extractor {

	public function is_available(): bool {
		return true;
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
