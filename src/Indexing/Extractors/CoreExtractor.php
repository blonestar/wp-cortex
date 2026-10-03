<?php
/**
 * Core post data.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing\Extractors;

use WP_Post;
use WPCortex\Indexing\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Title, URL, dates, author, excerpt and rendered content.
 */
final class CoreExtractor implements Extractor {

	public function is_available(): bool {
		return true;
	}

	public function extract( WP_Post $post, Document $doc ): void {
		$doc->object_type  = 'post';
		$doc->object_id    = $post->ID;
		$doc->subtype      = $post->post_type;
		$doc->status       = $post->post_status;
		$doc->title        = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$doc->url          = (string) get_permalink( $post );
		$doc->excerpt      = has_excerpt( $post ) ? wp_strip_all_tags( $post->post_excerpt ) : '';
		$doc->author_id    = (int) $post->post_author;
		$doc->author_name  = $post->post_author ? (string) get_the_author_meta( 'display_name', (int) $post->post_author ) : '';
		$doc->published_at = '0000-00-00 00:00:00' === $post->post_date_gmt ? '' : $post->post_date_gmt;
		$doc->modified_at  = $post->post_modified_gmt;
		$doc->body_html    = $this->render_content( $post );

		if ( $post->post_parent ) {
			$doc->add_field( 'core', 'parent_id', $post->post_parent, true );
		}

		$template = get_page_template_slug( $post );
		if ( $template ) {
			$doc->add_field( 'core', 'template', $template );
		}

		if ( has_post_thumbnail( $post ) ) {
			$doc->add_field( 'core', 'featured_image', (string) get_the_post_thumbnail_url( $post, 'full' ), true );
		}
	}

	/**
	 * Renders blocks (including dynamic ones) the way the front end would, minus shortcodes.
	 *
	 * @param WP_Post $post Post.
	 */
	private function render_content( WP_Post $post ): string {
		$content = strip_shortcodes( $post->post_content );

		if ( ! has_blocks( $content ) ) {
			return $content;
		}

		$previous_post   = $GLOBALS['post'] ?? null;
		$previous_user   = get_current_user_id();
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		// Render as an anonymous visitor: output is then identical in admin, REST, cron and
		// WP-CLI (stable hashes), and logged-in-only block content never reaches the index.
		wp_set_current_user( 0 );

		try {
			return do_blocks( $content );
		} catch ( \Throwable $e ) {
			// A misbehaving dynamic block must not stop indexing; fall back to the stored markup.
			return $content;
		} finally {
			wp_set_current_user( $previous_user );
			$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			if ( $previous_post instanceof WP_Post ) {
				setup_postdata( $previous_post );
			}
		}
	}
}
