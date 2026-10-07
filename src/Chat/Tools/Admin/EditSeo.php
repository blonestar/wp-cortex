<?php
/**
 * Admin chat tool: edit_seo.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Changes the Yoast SEO title, meta description and focus keyphrase of the post open in
 * the block editor. Like edit_post, it only changes the editor; the user saves the post.
 */
final class EditSeo extends AdminTool {

	public function name(): string {
		return 'edit_seo';
	}

	public function label(): string {
		return __( 'Edit Yoast SEO fields in the editor', 'wp-cortex' );
	}

	/**
	 * Offered while the block editor is open and Yoast SEO reported its fields.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		$editor = $this->admin( $context )->editor();

		return null !== $editor && null !== $editor['seo'] && EditorState::has_seo();
	}

	/**
	 * What else decides whether the tool is offered.
	 */
	public function availability_note(): string {
		return __( 'Only while the block editor of a post is open and Yoast SEO is active.', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Changes the Yoast SEO title, meta description or focus keyphrase of the post open in the editor. Pass only the fields to change. The change is applied in the editor only and is not saved: the user reviews it and saves the post. Call it only when the user asks for the change or accepts a value you suggested.';
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
				'title'           => array(
					'type'        => 'string',
					'description' => 'The new SEO title. May use Yoast variables such as %%title%%, %%sep%% and %%sitename%%. An empty string restores the default template.',
				),
				'description'     => array(
					'type'        => 'string',
					'description' => 'The new meta description, as plain text (about 120 to 156 characters). An empty string restores the default.',
				),
				'focus_keyphrase' => array(
					'type'        => 'string',
					'description' => 'The new focus keyphrase.',
				),
			),
		);
	}

	/**
	 * System instruction lines: the current values in the editor.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		$editor = (array) $this->admin( $context )->editor();
		$seo    = (array) ( $editor['seo'] ?? array() );

		return array(
			'Yoast SEO fields currently in the editor (possibly not saved yet; an empty value means the default template is used):',
			sprintf( '- SEO title: %s', '' !== (string) ( $seo['title'] ?? '' ) ? '«' . $seo['title'] . '»' : '(default)' ),
			sprintf( '- Meta description: %s', '' !== (string) ( $seo['description'] ?? '' ) ? '«' . $seo['description'] . '»' : '(default)' ),
			sprintf( '- Focus keyphrase: %s', '' !== (string) ( $seo['focus_keyphrase'] ?? '' ) ? '«' . $seo['focus_keyphrase'] . '»' : '(none)' ),
			'To change them, call edit_seo, with the same rules as edit_post: suggest first when asked for suggestions, and change only what the user asked for or accepted.',
		);
	}

	/**
	 * Queues the changes for the editor.
	 *
	 * @param array       $args    Arguments: title, description, focus_keyphrase.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin   = $this->admin( $context );
		$changes = array();
		$labels  = array();
		$fields  = array(
			'title'           => array( EditorState::MAX_SEO_TITLE_LENGTH, __( 'SEO title', 'wp-cortex' ) ),
			'description'     => array( EditorState::MAX_SEO_DESC_LENGTH, __( 'meta description', 'wp-cortex' ) ),
			'focus_keyphrase' => array( EditorState::MAX_KEYPHRASE_LENGTH, __( 'focus keyphrase', 'wp-cortex' ) ),
		);

		foreach ( $fields as $key => $field ) {
			if ( ! array_key_exists( $key, $args ) ) {
				continue;
			}

			$value = trim( sanitize_text_field( (string) $args[ $key ] ) );

			if ( mb_strlen( $value ) > $field[0] ) {
				return array(
					'ok'    => false,
					/* translators: 1: field name, 2: maximum number of characters */
					'error' => sprintf( __( 'The %1$s may have at most %2$d characters.', 'wp-cortex' ), $field[1], $field[0] ),
				);
			}

			$changes[ $key ] = $value;
			$labels[]        = $field[1];
		}

		if ( ! $changes ) {
			return array(
				'ok'    => false,
				'error' => __( 'Nothing to change. Pass at least one field.', 'wp-cortex' ),
			);
		}

		$admin->add_action(
			array(
				'type'    => 'edit_seo',
				'post_id' => $admin->post_id(),
				'changes' => $changes,
				'labels'  => $labels,
			)
		);

		return array(
			'ok'      => true,
			'changed' => $labels,
			'saved'   => false,
		);
	}
}
