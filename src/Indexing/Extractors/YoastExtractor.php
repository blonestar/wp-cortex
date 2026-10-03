<?php
/**
 * Yoast SEO data.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing\Extractors;

use WP_Post;
use WPCortex\Indexing\Document;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Yoast SEO post meta (admin index only). Every field is stored even when empty, so
 * queries like "posts without a meta description" are a simple equality check.
 */
final class YoastExtractor implements Extractor {

	/**
	 * Field name => Yoast meta key suffix (after "_yoast_wpseo_").
	 */
	private const FIELDS = array(
		'title'                => 'title',
		'meta_description'     => 'metadesc',
		'focus_keyword'        => 'focuskw',
		'related_keywords'     => 'focuskeywords',
		'keyword_synonyms'     => 'keywordsynonyms',
		'seo_score'            => 'linkdex',
		'readability_score'    => 'content_score',
		'is_cornerstone'       => 'is_cornerstone',
		'noindex'              => 'meta-robots-noindex',
		'nofollow'             => 'meta-robots-nofollow',
		'canonical'            => 'canonical',
		'breadcrumb_title'     => 'bctitle',
		'og_title'             => 'opengraph-title',
		'og_description'       => 'opengraph-description',
		'twitter_title'        => 'twitter-title',
		'twitter_description'  => 'twitter-description',
	);

	public function is_available(): bool {
		return Settings::get( 'index_yoast' ) && defined( 'WPSEO_VERSION' );
	}

	public function extract( WP_Post $post, Document $doc ): void {
		// Yoast data is rarely set on media; empty rows for every file would only add noise.
		if ( 'attachment' === $post->post_type ) {
			return;
		}

		foreach ( self::FIELDS as $name => $suffix ) {
			$value = get_post_meta( $post->ID, '_yoast_wpseo_' . $suffix, true );
			$doc->add_field( 'yoast', $name, is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );
		}

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$term_id = (int) get_post_meta( $post->ID, '_yoast_wpseo_primary_' . $taxonomy, true );
			if ( $term_id ) {
				$term = get_term( $term_id, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$doc->add_field( 'yoast', 'primary_' . $taxonomy, $term->name );
				}
			}
		}
	}
}
