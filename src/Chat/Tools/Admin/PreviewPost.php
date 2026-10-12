<?php
/**
 * Admin chat tool: preview_post.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Gives the user a preview link of the post open in the block editor. The browser
 * prepares the preview like the editor's Preview button: a draft is saved, the changes
 * of a published post are kept as an autosave and the live page stays as it is.
 */
final class PreviewPost extends AdminTool {

	/**
	 * Type of the UI action the browser prepares the preview on.
	 */
	public const ACTION = 'preview_post';

	public function name(): string {
		return 'preview_post';
	}

	public function label(): string {
		return __( 'Preview post', 'wp-cortex' );
	}

	/**
	 * Offered while the block editor of a post the user can edit is open.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		return null !== EditorSave::post( $this->admin( $context ) );
	}

	/**
	 * What else decides whether the tool is offered.
	 */
	public function availability_note(): string {
		return __( 'Only while the block editor of a post is open. Like the Preview button of the editor, it saves a draft; a published post is not changed.', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Prepares a preview of the post open in the editor, with its unsaved changes, and shows the user a link to it in the chat (like the Preview button of the editor). A draft is saved for that; the changes of a published post are only kept as an autosave, so the live page does not change. Call it only when the user asks to preview the post.';
	}

	/**
	 * Arguments: none.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function parameters( ToolContext $context ): ?array {
		return null;
	}

	/**
	 * Queues the preview.
	 *
	 * @param array       $args    Arguments: none.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin = $this->admin( $context );
		$post  = EditorSave::post( $admin );

		if ( null === $post ) {
			return EditorSave::error( __( 'No post is open in the editor.', 'wp-cortex' ) );
		}

		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return EditorSave::error( __( 'Posts of this type have no page on the site, so there is nothing to preview.', 'wp-cortex' ) );
		}

		$admin->add_action(
			array(
				'type'    => self::ACTION,
				'post_id' => $post->ID,
				// Used when the editor cannot prepare the preview itself: shows the saved post.
				'url'     => (string) get_preview_post_link( $post ),
			)
		);

		return array(
			'ok'   => true,
			'note' => 'The chat shows the user a preview link right after your reply. In one short sentence, say the preview link is below; do not write a link yourself.',
		);
	}
}
