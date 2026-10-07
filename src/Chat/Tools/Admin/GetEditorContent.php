<?php
/**
 * Admin chat tool: get_editor_content.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the blocks of the post open in the block editor, as they are in the editor
 * (possibly not saved yet), with the IDs edit_content needs. The blocks come with the
 * chat message, so the tool reads nothing from the database.
 */
final class GetEditorContent extends AdminTool {

	/**
	 * Most characters of blocks (as JSON) in one response, below the payload limit of
	 * the chat. Longer content is returned in pages.
	 */
	private const PAGE_CHARS = 10000;

	public function name(): string {
		return 'get_editor_content';
	}

	public function label(): string {
		return __( 'Read the content in the editor', 'wp-cortex' );
	}

	/**
	 * Offered while the block editor reported the blocks of the post.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		$editor = $this->admin( $context )->editor();

		return null !== $editor && null !== $editor['blocks'];
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
		return 'Returns the blocks of the post open in the editor, in document order, as they are in the editor now (including unsaved changes): block ID, block type, nesting depth and, for text blocks (paragraph, heading, list item, preformatted, verse, button), their text as inline HTML. Long content comes in pages: when the response has "next", call it again with from set to that value for the following blocks. Call it before edit_content, and whenever the user asks about the content currently in the editor.';
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
				'from' => array(
					'type'        => 'integer',
					'description' => 'Position of the first block to return (the "next" value of the previous response); 0 or omitted for the start.',
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
		return array( 'For questions about or changes to the content of the post in the editor, call get_editor_content first: the index may be older than the editor.' );
	}

	/**
	 * Returns a page of blocks.
	 *
	 * @param array       $args    Arguments: from.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$blocks = array_values( (array) ( $this->admin( $context )->editor()['blocks'] ?? array() ) );
		$total  = count( $blocks );
		$from   = min( $total, absint( $args['from'] ?? 0 ) );
		$page   = array();
		$size   = 0;

		for ( $index = $from; $index < $total; $index++ ) {
			$length = strlen( (string) wp_json_encode( $blocks[ $index ] ) );

			// Always return at least one block, so paging moves on.
			if ( $page && $size + $length > self::PAGE_CHARS ) {
				break;
			}

			$page[] = $blocks[ $index ];
			$size  += $length;
		}

		$response = array(
			'blocks' => $page,
			'from'   => $from,
			'total'  => $total,
		);

		if ( $from + count( $page ) < $total ) {
			$response['next'] = $from + count( $page );
		}

		if ( $total >= EditorState::MAX_BLOCKS ) {
			$response['note'] = sprintf( 'Only the first %d blocks of the post are available.', EditorState::MAX_BLOCKS );
		}

		return $response;
	}
}
