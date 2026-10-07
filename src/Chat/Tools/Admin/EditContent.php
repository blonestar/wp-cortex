<?php
/**
 * Admin chat tool: edit_content.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Changes the blocks of the post open in the block editor: replaces the text of a text
 * block, inserts new content (HTML converted to blocks by the editor) or removes a block.
 * Like edit_post, it only changes the editor; the user saves the post or undoes it.
 */
final class EditContent extends AdminTool {

	/**
	 * Most operations per call.
	 */
	public const MAX_OPERATIONS = 30;

	/**
	 * Longest HTML of one operation.
	 */
	public const MAX_HTML_LENGTH = 20000;

	public function name(): string {
		return 'edit_content';
	}

	public function label(): string {
		return __( 'Edit content in the editor', 'wp-cortex' );
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
		return 'Changes the content of the post open in the editor with a list of operations, applied in order: "replace" sets the text of a text block (inline HTML: strong, em, a, code, br, ...; keep existing links unless asked otherwise), "insert_before" / "insert_after" add new content next to a block (HTML such as <p>, <h2>, <ul><li>, <blockquote>, converted to blocks; without a block ID at the start or end of the post), "remove" deletes a block. Use block IDs from get_editor_content. The change is applied in the editor only and is not saved. Call it only when the user asks for the change or accepts content you suggested.';
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
				'operations' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'op'    => array(
								'type' => 'string',
								'enum' => array( 'replace', 'insert_before', 'insert_after', 'remove' ),
							),
							'block' => array(
								'type'        => 'string',
								'description' => 'Block ID from get_editor_content. Optional for insert_before (start of the post) and insert_after (end of the post).',
							),
							'html'  => array(
								'type'        => 'string',
								'description' => 'For replace: the new inline HTML text of the block. For inserts: the HTML of the new content.',
							),
						),
						'required'   => array( 'op' ),
					),
				),
			),
			'required'   => array( 'operations' ),
		);
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'To change the content in the editor, call edit_content with the block IDs from get_editor_content, with the same rules as edit_post: when asked for a suggestion or a draft, show it first and apply it only after the user accepts it, unless the user directly asked to change the content. Change only the blocks the request is about.' );
	}

	/**
	 * Validates the operations and queues them for the editor.
	 *
	 * @param array       $args    Arguments: operations.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin      = $this->admin( $context );
		$blocks     = array();
		$operations = array();

		foreach ( (array) ( $admin->editor()['blocks'] ?? array() ) as $block ) {
			$blocks[ $block['id'] ] = $block['name'];
		}

		$items = (array) ( $args['operations'] ?? array() );

		if ( ! $items || count( $items ) > self::MAX_OPERATIONS ) {
			/* translators: %d: maximum number of operations */
			return $this->error( sprintf( __( 'Pass 1 to %d operations.', 'wp-cortex' ), self::MAX_OPERATIONS ) );
		}

		foreach ( $items as $index => $item ) {
			$item = is_array( $item ) ? $item : array();
			$op   = (string) ( $item['op'] ?? '' );
			$id   = (string) ( $item['block'] ?? '' );
			$html = (string) ( $item['html'] ?? '' );
			$name = $blocks[ $id ] ?? null;

			if ( ! in_array( $op, array( 'replace', 'insert_before', 'insert_after', 'remove' ), true ) ) {
				return $this->error( sprintf( 'Operation %d: unknown op.', $index + 1 ) );
			}

			if ( ( '' !== $id || in_array( $op, array( 'replace', 'remove' ), true ) ) && null === $name ) {
				return $this->error( sprintf( 'Operation %d: unknown block ID. Call get_editor_content for the current block IDs.', $index + 1 ) );
			}

			if ( mb_strlen( $html ) > self::MAX_HTML_LENGTH ) {
				return $this->error( sprintf( 'Operation %1$d: the HTML may have at most %2$d characters.', $index + 1, self::MAX_HTML_LENGTH ) );
			}

			$operation = array(
				'op'    => $op,
				'block' => $id,
			);

			if ( 'replace' === $op ) {
				if ( ! isset( EditorState::TEXT_BLOCKS[ $name ] ) ) {
					return $this->error( sprintf( 'Operation %1$d: %2$s blocks have no text to replace. Insert new content and remove the block instead.', $index + 1, $name ) );
				}

				$operation['html'] = EditorState::inline_html( $html );
			} elseif ( 'remove' !== $op ) {
				$operation['html'] = trim( wp_kses_post( $html ) );
			}

			if ( 'remove' !== $op && '' === $operation['html'] ) {
				return $this->error( sprintf( 'Operation %d: the HTML is empty. Use remove to delete a block.', $index + 1 ) );
			}

			$operations[] = $operation;
		}

		$admin->add_action(
			array(
				'type'       => 'edit_content',
				'post_id'    => $admin->post_id(),
				'operations' => $operations,
			)
		);

		return array(
			'ok'         => true,
			'operations' => count( $operations ),
			'saved'      => false,
		);
	}

	/**
	 * Error response.
	 *
	 * @param string $message Message.
	 * @return array{ok: false, error: string}
	 */
	private function error( string $message ): array {
		return array(
			'ok'    => false,
			'error' => $message,
		);
	}
}
