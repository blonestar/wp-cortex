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
 * Yoast SEO post meta. Every field is stored even when empty, so queries like "posts
 * without a meta description" are a simple equality check. By default only the titles
 * and descriptions that are printed in the page head go into the public index.
 */
final class YoastExtractor implements Extractor, DescribesFields {

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

	/**
	 * Fields in the public index by default: visible in the page source anyway.
	 */
	private const PUBLIC_FIELDS = array( 'title', 'meta_description', 'og_title', 'og_description', 'twitter_title', 'twitter_description' );

	public function is_available(): bool {
		return Settings::get( 'index_yoast' ) && defined( 'WPSEO_VERSION' );
	}

	public function fields_label(): string {
		return __( 'Yoast SEO', 'wp-cortex' );
	}

	public function fields(): array {
		$labels = array(
			'title'               => __( 'SEO title', 'wp-cortex' ),
			'meta_description'    => __( 'Meta description', 'wp-cortex' ),
			'focus_keyword'       => __( 'Focus keyphrase', 'wp-cortex' ),
			'related_keywords'    => __( 'Related keyphrases', 'wp-cortex' ),
			'keyword_synonyms'    => __( 'Keyphrase synonyms', 'wp-cortex' ),
			'seo_score'           => __( 'SEO score', 'wp-cortex' ),
			'readability_score'   => __( 'Readability score', 'wp-cortex' ),
			'is_cornerstone'      => __( 'Cornerstone content', 'wp-cortex' ),
			'noindex'             => __( 'Noindex', 'wp-cortex' ),
			'nofollow'            => __( 'Nofollow', 'wp-cortex' ),
			'canonical'           => __( 'Canonical URL', 'wp-cortex' ),
			'breadcrumb_title'    => __( 'Breadcrumb title', 'wp-cortex' ),
			'og_title'            => __( 'Social title (Open Graph)', 'wp-cortex' ),
			'og_description'      => __( 'Social description (Open Graph)', 'wp-cortex' ),
			'twitter_title'       => __( 'X (Twitter) title', 'wp-cortex' ),
			'twitter_description' => __( 'X (Twitter) description', 'wp-cortex' ),
		);

		$fields = array();
		foreach ( array_keys( self::FIELDS ) as $name ) {
			$fields[ 'yoast:' . $name ] = array(
				'label'  => $labels[ $name ] ?? $name,
				'public' => in_array( $name, self::PUBLIC_FIELDS, true ),
			);
		}

		$fields['yoast:primary_*'] = array(
			'label'  => __( 'Primary terms', 'wp-cortex' ),
			'public' => false,
		);

		return $fields;
	}

	public function extract( WP_Post $post, Document $doc ): void {
		// Yoast data is rarely set on media; empty rows for every file would only add noise.
		if ( 'attachment' === $post->post_type ) {
			return;
		}

		foreach ( self::FIELDS as $name => $suffix ) {
			$value = get_post_meta( $post->ID, '_yoast_wpseo_' . $suffix, true );
			$doc->add_field( 'yoast', $name, is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ), in_array( $name, self::PUBLIC_FIELDS, true ) );
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
