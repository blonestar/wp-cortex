<?php
/**
 * Admin chat tool: list_block_types.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the block types and block patterns that can be inserted in the post open in
 * the block editor, so edit_content can insert them by name. The block types come with
 * the chat message (what the editor allows in this post); their descriptions, the ACF
 * fields of ACF blocks and the patterns come from the registries of WordPress and ACF.
 */
final class ListBlockTypes extends AdminTool {

	/**
	 * Most characters of block types and of patterns (as JSON) in one response, together
	 * below the payload limit of the chat.
	 */
	private const BLOCK_CHARS   = 5500;
	private const PATTERN_CHARS = 4500;

	public function name(): string {
		return 'list_block_types';
	}

	public function label(): string {
		return __( 'List the blocks and patterns of the editor', 'wp-cortex' );
	}

	/**
	 * Offered while the block editor reported the block types of the post.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		$editor = $this->admin( $context )->editor();

		return null !== $editor && null !== $editor['blocks'] && ! empty( $editor['block_types'] );
	}

	/**
	 * What else decides whether the tool is offered.
	 */
	public function availability_note(): string {
		return __( 'Only while the block editor of a post is open.', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Lists what can be inserted in the post open in the editor: block types (name, title; with a search also the description, for ACF blocks the fields that can be filled, and whether the block holds other blocks) and block patterns (ready-made sections: name, title; with a search also the description and the text blocks that can be filled, in order). Pass search with a word or two (for example "hero", "columns", "testimonial") to find a block or pattern; without it every name is listed. Call it before inserting a block or pattern with edit_content.';
	}

	/**
	 * Arguments.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function parameters( ToolContext $context ): ?array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'search' => array(
					'type'        => 'string',
					'description' => 'Words to look for in the names, titles, descriptions and keywords. Omit to list everything by name and title only.',
				),
			),
		);
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'To add a block of the site (a hero, columns, a custom or ACF block) or a ready-made section, call list_block_types with a search word, then edit_content with insert_block or insert_pattern. Prefer a pattern when one fits the request: it comes with the block set up as the site uses it. Never say a block cannot be inserted before checking list_block_types.' );
	}

	/**
	 * Returns the matching block types and patterns.
	 *
	 * @param array       $args    Arguments: search.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin    = $this->admin( $context );
		$search   = trim( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
		$detailed = '' !== $search;
		$blocks   = BlockCatalog::search( BlockCatalog::block_types( (array) ( $admin->editor()['block_types'] ?? array() ) ), $search );
		$patterns = BlockCatalog::search( BlockCatalog::patterns( (string) get_post_type( $admin->post_id() ) ), $search );
		$response = array();

		foreach ( $blocks as $index => $row ) {
			$blocks[ $index ] = $this->block_row( $row, $detailed );
		}

		foreach ( $patterns as $index => $row ) {
			$patterns[ $index ] = $this->pattern_row( $row, $detailed );
		}

		$response['blocks']   = $this->page( $blocks, self::BLOCK_CHARS );
		$response['patterns'] = $this->page( $patterns, self::PATTERN_CHARS );

		if ( count( $response['blocks'] ) < count( $blocks ) ) {
			$response['more_blocks'] = count( $blocks ) - count( $response['blocks'] );
		}

		if ( count( $response['patterns'] ) < count( $patterns ) ) {
			$response['more_patterns'] = count( $patterns ) - count( $response['patterns'] );
		}

		if ( isset( $response['more_blocks'] ) || isset( $response['more_patterns'] ) ) {
			$response['note'] = 'Not everything fits. Pass a more specific search to see the rest.';
		} elseif ( $detailed && ! $blocks && ! $patterns ) {
			$response['note'] = 'Nothing matches. Try another word, or call it without search for every name.';
		}

		return $response;
	}

	/**
	 * A block type as the model sees it.
	 *
	 * @param array<string, mixed> $row      Block type from BlockCatalog.
	 * @param bool                 $detailed Whether to add the description and fields.
	 * @return array<string, mixed>
	 */
	private function block_row( array $row, bool $detailed ): array {
		$out = array(
			'name'  => $row['name'],
			'title' => $row['title'],
		);

		if ( ! $detailed ) {
			return $out;
		}

		if ( '' !== $row['description'] ) {
			$out['description'] = $row['description'];
		}

		foreach ( (array) ( $row['fields'] ?? array() ) as $field ) {
			$item = array(
				'name'  => $field['name'],
				'label' => $field['label'],
				'type'  => $field['type'],
			);

			if ( $field['choices'] ) {
				$item['choices'] = array_map( 'strval', array_keys( $field['choices'] ) );
			}

			$out['fields'][] = $item;
		}

		if ( ! empty( $row['inner_blocks'] ) ) {
			$out['inner_blocks'] = true;
		}

		return $out;
	}

	/**
	 * A pattern as the model sees it.
	 *
	 * @param array<string, mixed> $row      Pattern from BlockCatalog.
	 * @param bool                 $detailed Whether to add the description and text blocks.
	 * @return array<string, mixed>
	 */
	private function pattern_row( array $row, bool $detailed ): array {
		$out = array(
			'name'  => $row['name'],
			'title' => $row['title'],
		);

		if ( ! $detailed ) {
			return $out;
		}

		if ( '' !== $row['description'] ) {
			$out['description'] = $row['description'];
		}

		$texts = BlockCatalog::pattern_texts( $row['content'] );

		if ( $texts ) {
			$out['texts'] = $texts;
		}

		return $out;
	}

	/**
	 * The rows that fit in a number of characters (as JSON).
	 *
	 * @param array<int, array<string, mixed>> $rows  Rows, best first.
	 * @param int                              $chars Character budget.
	 * @return array<int, array<string, mixed>>
	 */
	private function page( array $rows, int $chars ): array {
		$page = array();
		$size = 0;

		foreach ( $rows as $row ) {
			$size += strlen( (string) wp_json_encode( $row ) );

			if ( $page && $size > $chars ) {
				break;
			}

			$page[] = $row;
		}

		return $page;
	}
}
