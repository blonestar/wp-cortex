<?php
/**
 * Visitor chat tool: search_site.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Public;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Searches the public index, optionally only content by one author.
 */
final class SearchSite extends PublicTool {

	private const MAX_RESULTS = 8;
	private const MAX_LIST    = 30;

	public function name(): string {
		return 'search_site';
	}

	public function label(): string {
		return __( 'Search the site', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Searches the published content of this website (keyword and semantic search), optionally only content by one author. Returns pages with id, title, type, author, date, URL and a matching snippet. Use it for every question about the site, its offer or its content. Pass a query, an author or both.';
	}

	/**
	 * Arguments; post_type is offered when the public index has several types.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function parameters( ToolContext $context ): ?array {
		$search = array(
			'type'       => 'object',
			'properties' => array(
				'query'  => array(
					'type'        => 'string',
					'description' => 'What to look for, in natural language or keywords. May be empty when author is given, to list all content by that author.',
				),
				'author' => array(
					'type'        => 'string',
					'description' => 'Optional. Only content written by this author, for example "Mark Davoli" or "Davoli". Matching ignores case and accents and tolerates inflected forms, but prefer the name in its basic form. Use it for posts by someone, not the query, which also matches content that only mentions the name.',
				),
				'limit'  => array(
					'type'        => 'integer',
					'description' => 'Maximum number of results (default 5, max ' . self::MAX_RESULTS . '; up to ' . self::MAX_LIST . ' when listing by author without a query).',
					'minimum'     => 1,
					'maximum'     => self::MAX_LIST,
				),
			),
		);

		$types = $this->visitor( $context )->post_types();

		if ( count( $types ) > 1 ) {
			$search['properties']['post_type'] = array(
				'type'        => 'string',
				'description' => 'Optional. Only content of this type.',
				'enum'        => $types,
			);
		}

		return $search;
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array(
			'Use search_site to find the pages relevant to the question.',
			'To find content written by someone (for example "posts by Jane Doe"), call search_site with the author parameter and an empty query; a text query also matches pages that only mention the name. Every result carries its author. The matching authors are listed with their total number of posts and whether they have an author page.',
		);
	}

	/**
	 * Searches the public index.
	 *
	 * @param array       $args    Arguments: query, author, limit, post_type.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$visitor = $this->visitor( $context );
		$query   = trim( (string) ( $args['query'] ?? '' ) );
		$author  = trim( (string) ( $args['author'] ?? '' ) );

		if ( '' === $query && '' === $author ) {
			return array( 'error' => 'Pass a query or an author.' );
		}

		$types = $visitor->post_types();
		if ( ! $types ) {
			return array(
				'results' => array(),
				'total'   => 0,
			);
		}

		// Listing everything by an author needs more rows than a topic search.
		$max    = '' === $query ? self::MAX_LIST : self::MAX_RESULTS;
		$search = array(
			'limit'      => max( 1, min( $max, (int) ( $args['limit'] ?? ( '' === $query ? $max : 5 ) ) ) ),
			'post_types' => $types,
		);

		if ( '' !== $author ) {
			$search['author'] = mb_substr( $author, 0, 100 );
		}
		$type = (string) ( $args['post_type'] ?? '' );

		if ( '' !== $type && in_array( $type, $types, true ) ) {
			$search['post_types'] = array( $type );
		}

		try {
			$rows = $visitor->search()->search( mb_substr( $query, 0, 500 ), $search );
		} catch ( \Throwable $e ) {
			return array( 'error' => 'Search is not available.' );
		}

		$results = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];

			$results[] = array(
				'id'        => $id,
				'title'     => (string) $row['title'],
				'post_type' => (string) $row['post_type'],
				'author'    => (string) $row['author'],
				'date'      => substr( (string) $row['published_at'], 0, 10 ),
				'url'       => (string) $row['url'],
				'section'   => (string) $row['heading'],
				'snippet'   => (string) $row['snippet'],
			);

			$visitor->remember( $id, (string) $row['title'], (string) $row['url'], (string) $row['snippet'] );
		}

		$payload = array(
			'results' => $results,
			'total'   => count( $results ),
		);

		if ( '' !== $author ) {
			$payload['authors'] = array_map(
				static fn( array $row ) => array(
					'name'        => $row['name'],
					'posts'       => $row['count'],
					'author_page' => '' !== $row['url'],
				),
				$visitor->public_authors( $author )
			);
		}

		return $payload;
	}
}
