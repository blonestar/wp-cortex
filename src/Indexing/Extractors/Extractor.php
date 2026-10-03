<?php
/**
 * Extractor contract.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing\Extractors;

use WP_Post;
use WPCortex\Indexing\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Adds one kind of data (core, taxonomies, Yoast, ACF...) to a document.
 */
interface Extractor {

	/**
	 * Whether the extractor is enabled and its data source is available.
	 */
	public function is_available(): bool;

	/**
	 * Adds data from the post to the document.
	 *
	 * @param WP_Post  $post Post.
	 * @param Document $doc  Document being built.
	 */
	public function extract( WP_Post $post, Document $doc ): void;
}
