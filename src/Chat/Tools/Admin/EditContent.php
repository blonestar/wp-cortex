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
 * block, inserts new content (HTML converted to blocks by the editor), a block by its
 * type or a block pattern, moves, duplicates or removes a block and fills the fields of
 * an ACF block. Like edit_post, it only changes the editor; the user saves the post or
 * undoes it.
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

	/**
	 * Operations, with whether they need the ID of an existing block.
	 */
	private const OPERATIONS = array(
		'replace'        => true,
		'insert_before'  => false,
		'insert_after'   => false,
		'insert_block'   => false,
		'insert_pattern' => false,
		'move'           => true,
		'duplicate'      => true,
		'set_fields'     => true,
		'remove'         => true,
	);

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
		return 'Changes the content of the post open in the editor with a list of operations, applied in order. '
			. '"replace" sets the text of a text block (inline HTML: strong, em, a, code, br, ...; keep existing links unless asked otherwise). '
			. '"insert_before" / "insert_after" add new content next to a block (HTML such as <p>, <h2>, <ul><li>, <blockquote>, converted to standard blocks; without a block ID at the start or end of the post). '
			. '"insert_block" adds a block by its type (name from list_block_types), optionally with fields (ACF blocks) and html (the blocks inside a block that holds other blocks). '
			. '"insert_pattern" adds a block pattern (pattern name from list_block_types), optionally with texts for its text blocks. '
			. '"move" puts a block (with the blocks inside it) next to or inside the target block. "duplicate" copies a block right after itself. '
			. '"set_fields" changes fields of an ACF block. "remove" deletes a block. '
			. 'insert_block, insert_pattern and move take position: "before" or "after" the reference block (without one: the start or the end of the post), or "inside" it as its last block. '
			. 'Use block IDs from get_editor_content. The change is applied in the editor only and is not saved. Call it only when the user asks for the change or accepts content you suggested.';
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
							'op'       => array(
								'type' => 'string',
								'enum' => array_keys( self::OPERATIONS ),
							),
							'block'    => array(
								'type'        => 'string',
								'description' => 'Block ID from get_editor_content: the block to change, move, duplicate or remove, or the block an insert is placed next to (optional for inserts).',
							),
							'html'     => array(
								'type'        => 'string',
								'description' => 'For replace: the new inline HTML text of the block. For insert_before / insert_after: the HTML of the new content. For insert_block: optional HTML of the blocks placed inside the new block.',
							),
							'name'     => array(
								'type'        => 'string',
								'description' => 'For insert_block: the block type, for example "core/columns" or "acf/hero".',
							),
							'pattern'  => array(
								'type'        => 'string',
								'description' => 'For insert_pattern: the pattern name.',
							),
							'position' => array(
								'type'        => 'string',
								'enum'        => array( 'before', 'after', 'inside' ),
								'description' => 'For insert_block, insert_pattern and move; "after" when omitted.',
							),
							'target'   => array(
								'type'        => 'string',
								'description' => 'For move: ID of the block the moved block is placed before, after or inside. Omit to move it to the start (before) or the end (after) of the post.',
							),
							'fields'   => array(
								'type'        => 'array',
								'description' => 'For insert_block and set_fields on an ACF block: the fields to fill, by field name from list_block_types.',
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'name'  => array( 'type' => 'string' ),
										'value' => array(
											'type'        => 'string',
											'description' => 'For select and radio fields one of the choices, for true/false fields "1" or "0", for wysiwyg fields HTML.',
										),
									),
									'required'   => array( 'name', 'value' ),
								),
							),
							'texts'    => array(
								'type'        => 'array',
								'description' => 'For insert_pattern: inline HTML for the text blocks of the pattern, in the order list_block_types lists them; an empty string leaves a text block as it is.',
								'items'       => array( 'type' => 'string' ),
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
		return array(
			'To change the content in the editor, call edit_content with the block IDs from get_editor_content, with the same rules as edit_post: when asked for a suggestion or a draft, show it first and apply it only after the user accepts it, unless the user directly asked to change the content. Change only the blocks the request is about.',
			'A block you insert gets its ID only in the editor, so you cannot change it later in the same reply: give its content in the same operation (fields and html of insert_block, texts of insert_pattern). When the user asks for a block without saying what goes in it, insert it as it is and say what is left to fill in.',
		);
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
		$editor     = (array) $admin->editor();
		$blocks     = array();
		$operations = array();

		foreach ( (array) ( $editor['blocks'] ?? array() ) as $block ) {
			$blocks[ $block['id'] ] = $block['name'];
		}

		$items = (array) ( $args['operations'] ?? array() );

		if ( ! $items || count( $items ) > self::MAX_OPERATIONS ) {
			/* translators: %d: maximum number of operations */
			return $this->error( sprintf( __( 'Pass 1 to %d operations.', 'wp-cortex' ), self::MAX_OPERATIONS ) );
		}

		foreach ( $items as $index => $item ) {
			$item   = is_array( $item ) ? $item : array();
			$number = $index + 1;
			$op     = (string) ( $item['op'] ?? '' );
			$id     = (string) ( $item['block'] ?? '' );
			$html   = (string) ( $item['html'] ?? '' );
			$name   = $blocks[ $id ] ?? null;

			if ( ! isset( self::OPERATIONS[ $op ] ) ) {
				return $this->error( sprintf( 'Operation %d: unknown op.', $number ) );
			}

			if ( ( '' !== $id || self::OPERATIONS[ $op ] ) && null === $name ) {
				return $this->error( sprintf( 'Operation %d: unknown block ID. Call get_editor_content for the current block IDs.', $number ) );
			}

			if ( mb_strlen( $html ) > self::MAX_HTML_LENGTH ) {
				return $this->error( sprintf( 'Operation %1$d: the HTML may have at most %2$d characters.', $number, self::MAX_HTML_LENGTH ) );
			}

			$operation = array(
				'op'    => $op,
				'block' => $id,
			);

			switch ( $op ) {
				case 'replace':
					if ( ! isset( EditorState::TEXT_BLOCKS[ $name ] ) ) {
						return $this->error( sprintf( 'Operation %1$d: %2$s blocks have no text to replace. Insert new content and remove the block instead.', $number, $name ) );
					}

					$operation['html'] = EditorState::inline_html( $html );
					break;
				case 'insert_before':
				case 'insert_after':
					$operation['html'] = trim( wp_kses_post( $html ) );
					break;
				case 'insert_block':
					$type = (string) ( $item['name'] ?? '' );

					if ( ! isset( $editor['block_types'][ $type ] ) ) {
						return $this->error( sprintf( 'Operation %d: unknown block type, or one the editor does not allow in this post. Call list_block_types for the block types.', $number ) );
					}

					$fields = $this->fields( $type, $item['fields'] ?? array(), $number );

					if ( is_string( $fields ) ) {
						return $this->error( $fields );
					}

					$operation['name']   = $type;
					$operation['fields'] = $fields;
					$operation['html']   = trim( wp_kses_post( $html ) );
					break;
				case 'insert_pattern':
					$pattern = BlockCatalog::patterns( (string) get_post_type( $admin->post_id() ) )[ (string) ( $item['pattern'] ?? '' ) ] ?? null;

					if ( null === $pattern ) {
						return $this->error( sprintf( 'Operation %d: unknown pattern. Call list_block_types for the pattern names.', $number ) );
					}

					$operation['content'] = $pattern['content'];
					$operation['texts']   = array();

					foreach ( array_slice( array_values( (array) ( $item['texts'] ?? array() ) ), 0, BlockCatalog::MAX_PATTERN_TEXTS ) as $text ) {
						$operation['texts'][] = is_scalar( $text ) ? EditorState::inline_html( mb_substr( (string) $text, 0, EditorState::MAX_BLOCK_TEXT_LENGTH ) ) : '';
					}
					break;
				case 'move':
					$target = (string) ( $item['target'] ?? '' );

					if ( '' !== $target && ( ! isset( $blocks[ $target ] ) || $target === $id ) ) {
						return $this->error( sprintf( 'Operation %d: the target must be the ID of another block from get_editor_content.', $number ) );
					}

					$operation['target'] = $target;
					break;
				case 'set_fields':
					$fields = $this->fields( $name, $item['fields'] ?? array(), $number );

					if ( is_string( $fields ) ) {
						return $this->error( $fields );
					}

					if ( ! $fields ) {
						return $this->error( sprintf( 'Operation %d: pass at least one field.', $number ) );
					}

					$operation['fields'] = $fields;
					break;
			}

			if ( in_array( $op, array( 'replace', 'insert_before', 'insert_after' ), true ) && '' === $operation['html'] ) {
				return $this->error( sprintf( 'Operation %d: the HTML is empty. Use remove to delete a block.', $number ) );
			}

			if ( in_array( $op, array( 'insert_block', 'insert_pattern', 'move' ), true ) ) {
				$position = (string) ( $item['position'] ?? 'after' );
				$anchor   = 'move' === $op ? $operation['target'] : $id;

				if ( ! in_array( $position, array( 'before', 'after', 'inside' ), true ) ) {
					return $this->error( sprintf( 'Operation %d: position must be before, after or inside.', $number ) );
				}

				if ( 'inside' === $position && '' === $anchor ) {
					return $this->error( sprintf( 'Operation %d: position inside needs the ID of the block to put it in.', $number ) );
				}

				$operation['position'] = $position;
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
			'note'       => 'Queued for the editor. A block the editor does not allow in that place is skipped, and the user is told.',
		);
	}

	/**
	 * Validates the fields of an ACF block operation.
	 *
	 * @param string $name   Block name.
	 * @param mixed  $items  Fields from the model: name, value.
	 * @param int    $number Operation number, for the error.
	 * @return array<int, array{key: string, name: string, value: string}>|string The fields for the browser, or an error message.
	 */
	private function fields( string $name, $items, int $number ) {
		$values = array();

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( is_array( $item ) && is_scalar( $item['name'] ?? null ) ) {
				$values[ (string) $item['name'] ] = $item['value'] ?? null;
			}
		}

		if ( ! $values ) {
			return array();
		}

		if ( ! BlockCatalog::acf_fields( $name ) ) {
			return sprintf( 'Operation %1$d: %2$s has no fields that can be filled here.', $number, $name );
		}

		$fields = BlockCatalog::field_values( $name, $values );

		if ( is_string( $fields ) ) {
			return sprintf( 'Operation %1$d: "%2$s" is not a field of %3$s that can be filled, or the value is not valid for it. Call list_block_types with a search for the fields; nothing was changed.', $number, $fields, $name );
		}

		return $fields;
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
