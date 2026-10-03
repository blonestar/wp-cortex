<?php
/**
 * WordPress Abilities API registration.
 *
 * @package WPCortex
 */

namespace WPCortex\Abilities;

use WPCortex\Search\SearchService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers read-only abilities that operate on the admin index.
 */
final class Abilities {

	public const CATEGORY = 'wp-cortex';

	private const DOCUMENT_CHARS = 6000;

	/**
	 * Hooks the Abilities API init actions.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the ability category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WP Cortex', 'wp-cortex' ),
				'description' => __( 'Search and inspect the site content indexed by WP Cortex.', 'wp-cortex' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$meta = array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => false,
			'mcp'          => array( 'public' => false ),
		);

		wp_register_ability(
			'wp-cortex/search-content',
			array(
				'label'               => __( 'Search site content', 'wp-cortex' ),
				'description'         => 'Searches the site content index (posts, pages and other indexed post types, including drafts and private content) with hybrid keyword and semantic search plus structured filters. Use it to find content by topic, or by data such as author, SEO fields, taxonomy terms, custom fields, status or modification date. To find posts written by someone, use the author parameter (not the query, which also matches posts that only mention the name). The query may be empty when only filters are used. Filters are objects { source, name, op, value }. A field is identified by its source and name, shown as source.name: for example source "yoast" with name "meta_description" (also focus_keyword, seo_score, readability_score, noindex, title), source "taxonomy" with name "category" (taxonomy slug), source "acf" with the ACF field name, source "meta" with the meta key, source "core" with template, featured_image or parent_id. Always pass source and name separately. Operators: eq (equals), neq (not equal), contains, not_contains, empty (field exists but is empty), not_empty, missing (post has no such field row), exists. Use list-fields first if you are unsure which fields exist. Returns at most "limit" results with id, title, post type, status, author, URLs and a snippet.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'query'           => array(
							'type'        => 'string',
							'description' => 'Free-text search query. Leave empty for filter-only searches.',
						),
						'post_types'      => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Restrict to these post types, for example ["post","page"].',
						),
						'statuses'        => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Restrict to these post statuses: publish, draft, pending, future, private.',
						),
						'author'          => array(
							'type'        => 'string',
							'description' => 'Only content whose author display name contains this text (case-insensitive), for example "Davoli".',
						),
						'author_id'       => array(
							'type'        => 'integer',
							'description' => 'Only content by this author user ID.',
						),
						'filters'         => array(
							'type'        => 'array',
							'description' => 'Structured field filters; all must match.',
							'items'       => array(
								'type'                 => 'object',
								'properties'           => array(
									'source' => array( 'type' => 'string' ),
									'name'   => array( 'type' => 'string' ),
									'op'     => array(
										'type' => 'string',
										'enum' => array( 'eq', 'neq', 'contains', 'not_contains', 'empty', 'not_empty', 'missing', 'exists' ),
									),
									'value'  => array( 'type' => 'string' ),
								),
								'required'             => array( 'name', 'op' ),
								'additionalProperties' => false,
							),
						),
						'modified_after'  => array(
							'type'        => 'string',
							'description' => 'Only content modified on or after this date (Y-m-d).',
						),
						'modified_before' => array(
							'type'        => 'string',
							'description' => 'Only content modified on or before this date (Y-m-d).',
						),
						'limit'           => array(
							'type'        => 'integer',
							'description' => 'Maximum number of results (default 10, max 25).',
							'minimum'     => 1,
							'maximum'     => 25,
							'default'     => 10,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'results' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'total'   => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'search_content' ),
				'permission_callback' => array( $this, 'permission' ),
				'meta'                => $meta,
			)
		);

		wp_register_ability(
			'wp-cortex/get-document',
			array(
				'label'               => __( 'Get indexed document', 'wp-cortex' ),
				'description'         => 'Returns the indexed details of one post by ID: title, status, type, URLs, all indexed fields (SEO, taxonomy, custom fields) and the beginning of its content. Use it to inspect a specific post found by search-content, for example to check its meta description or read its text.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'WordPress post ID.',
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'get_document' ),
				'permission_callback' => array( $this, 'permission' ),
				'meta'                => $meta,
			)
		);

		wp_register_ability(
			'wp-cortex/find-duplicates',
			array(
				'label'               => __( 'Find duplicate content', 'wp-cortex' ),
				'description'         => 'Finds groups of posts that share the same title ("by": "title"), the same value of a structured field ("by": "field" with source and name, for example source "yoast" and name "meta_description") or the same text content ("by": "content"). Comparison ignores case and extra whitespace; empty values are skipped. Use it for any duplicate check instead of guessing with search-content. Returns the largest groups first with the total number of groups; posts carry id, title, type, status, author and modification date.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'by'         => array(
							'type'    => 'string',
							'enum'    => array( 'title', 'field', 'content' ),
							'default' => 'title',
						),
						'source'     => array(
							'type'        => 'string',
							'description' => 'Field source when "by" is "field", for example "yoast".',
						),
						'name'       => array(
							'type'        => 'string',
							'description' => 'Field name when "by" is "field", for example "meta_description".',
						),
						'post_types' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Restrict to these post types, for example ["post"].',
						),
						'statuses'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Restrict to these post statuses: publish, draft, pending, future, private.',
						),
						'limit'      => array(
							'type'        => 'integer',
							'description' => 'Maximum number of groups (default 25, max 100).',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 25,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'groups'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'total_groups' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'find_duplicates' ),
				'permission_callback' => array( $this, 'permission' ),
				'meta'                => $meta,
			)
		);

		wp_register_ability(
			'wp-cortex/list-fields',
			array(
				'label'               => __( 'List indexed fields', 'wp-cortex' ),
				'description'         => 'Lists every structured field available for filtering in search-content, with its source, name and the number of documents that have it. Call it when you are unsure about exact field names (for example the Yoast, taxonomy, ACF or custom meta field names).',
				'category'            => self::CATEGORY,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'fields' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'list_fields' ),
				'permission_callback' => array( $this, 'permission' ),
				'meta'                => $meta,
			)
		);
	}

	/**
	 * Permission check shared by all abilities.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Ability: search-content.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function search_content( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		try {
			$args = array(
				'limit' => max( 1, min( 25, (int) ( $input['limit'] ?? 10 ) ) ),
			);

			foreach ( array( 'post_types', 'statuses', 'filters' ) as $key ) {
				if ( ! empty( $input[ $key ] ) && is_array( $input[ $key ] ) ) {
					$args[ $key ] = $input[ $key ];
				}
			}
			foreach ( array( 'author', 'modified_after', 'modified_before' ) as $key ) {
				if ( ! empty( $input[ $key ] ) && is_string( $input[ $key ] ) ) {
					$args[ $key ] = $input[ $key ];
				}
			}
			if ( ! empty( $input['author_id'] ) ) {
				$args['author_id'] = (int) $input['author_id'];
			}

			$rows    = SearchService::for_scope( 'admin' )->search( (string) ( $input['query'] ?? '' ), $args );
			$results = array();

			foreach ( $rows as $row ) {
				$results[] = array(
					'id'        => (int) $row['id'],
					'title'     => (string) $row['title'],
					'post_type' => (string) $row['post_type'],
					'status'    => (string) $row['status'],
					'author'    => (string) $row['author'],
					'url'       => (string) $row['url'],
					'edit_url'  => $this->edit_url( (int) $row['id'] ),
					'snippet'   => (string) $row['snippet'],
					'heading'   => (string) $row['heading'],
				);
			}

			return array(
				'results' => $results,
				'total'   => count( $results ),
			);
		} catch ( \Throwable $e ) {
			return new WP_Error( 'wp_cortex_search_failed', $e->getMessage() );
		}
	}

	/**
	 * Ability: get-document.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_document( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = (int) ( $input['post_id'] ?? 0 );

		try {
			$doc = $post_id > 0 ? SearchService::for_scope( 'admin' )->get_document( $post_id ) : null;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'wp_cortex_document_failed', $e->getMessage() );
		}

		if ( null === $doc ) {
			return new WP_Error( 'wp_cortex_not_indexed', __( 'This post is not in the index.', 'wp-cortex' ) );
		}

		$remaining = self::DOCUMENT_CHARS;
		$chunks    = array();

		foreach ( $doc['chunks'] as $chunk ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$text      = mb_substr( (string) $chunk['content'], 0, $remaining );
			$remaining -= mb_strlen( $text );
			$chunks[]  = array(
				'heading' => (string) $chunk['heading'],
				'content' => $text,
			);
		}

		return array(
			'id'        => $post_id,
			'title'     => (string) $doc['title'],
			'status'    => (string) $doc['status'],
			'post_type' => (string) ( $doc['subtype'] ?? '' ),
			'author'    => (string) ( $doc['author_name'] ?? '' ),
			'url'       => (string) ( $doc['url'] ?? '' ),
			'edit_url'  => $this->edit_url( $post_id ),
			'modified'  => (string) ( $doc['modified_at'] ?? '' ),
			'fields'    => $doc['fields'],
			'content'   => $chunks,
			'truncated' => count( $chunks ) < count( $doc['chunks'] ) || ( $chunks && $remaining <= 0 ),
		);
	}

	/**
	 * Ability: find-duplicates.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function find_duplicates( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$by    = in_array( $input['by'] ?? '', array( 'title', 'field', 'content' ), true ) ? $input['by'] : 'title';

		if ( 'field' === $by && ( empty( $input['source'] ) || empty( $input['name'] ) ) ) {
			return new WP_Error( 'wp_cortex_missing_field', 'Pass source and name when "by" is "field". Use list-fields to see the available fields.' );
		}

		try {
			$args = array(
				'limit'  => max( 1, min( 100, (int) ( $input['limit'] ?? 25 ) ) ),
				'source' => (string) ( $input['source'] ?? '' ),
				'name'   => (string) ( $input['name'] ?? '' ),
			);

			foreach ( array( 'post_types', 'statuses' ) as $key ) {
				if ( ! empty( $input[ $key ] ) && is_array( $input[ $key ] ) ) {
					$args[ $key ] = $input[ $key ];
				}
			}

			$found  = SearchService::for_scope( 'admin' )->find_duplicates( $by, $args );
			$groups = array();

			foreach ( $found['groups'] as $group ) {
				$posts = array();

				foreach ( $group['documents'] as $row ) {
					$posts[] = array(
						'id'        => (int) $row['id'],
						'title'     => (string) $row['title'],
						'post_type' => (string) $row['post_type'],
						'status'    => (string) $row['status'],
						'author'    => (string) $row['author'],
						'modified'  => (string) $row['modified_at'],
					);
				}

				$groups[] = array(
					'value' => mb_substr( $group['value'], 0, 300 ),
					'count' => $group['count'],
					'posts' => $posts,
				);
			}

			return array(
				'by'           => $by,
				'groups'       => $groups,
				'total_groups' => $found['total_groups'],
			);
		} catch ( \Throwable $e ) {
			return new WP_Error( 'wp_cortex_duplicates_failed', $e->getMessage() );
		}
	}

	/**
	 * Ability: list-fields.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function list_fields() {
		try {
			$fields = array();

			foreach ( SearchService::for_scope( 'admin' )->field_catalog() as $row ) {
				$fields[] = array(
					'source'    => $row['source'],
					'name'      => $row['name'],
					'documents' => $row['documents'],
				);
			}

			return array( 'fields' => $fields );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'wp_cortex_fields_failed', $e->getMessage() );
		}
	}

	/**
	 * Edit URL when the current user may edit the post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Empty string when not editable.
	 */
	private function edit_url( int $post_id ): string {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return '';
		}

		return (string) get_edit_post_link( $post_id, 'raw' );
	}
}
