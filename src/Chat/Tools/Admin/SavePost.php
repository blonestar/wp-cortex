<?php
/**
 * Admin chat tool: save_post.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Saves the post open in the block editor with the status it has there (Save draft or
 * Update). The browser saves it through the editor; a post that is or becomes published
 * is saved only after the user confirms the action card, because the changes go live.
 */
final class SavePost extends AdminTool implements ConfirmableTool {

	public function name(): string {
		return 'save_post';
	}

	public function label(): string {
		return __( 'Save post', 'wp-cortex' );
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
	 * What else decides whether and how the tool runs.
	 */
	public function availability_note(): string {
		return __( 'Only while the block editor of a post is open. A draft is saved right away; changes to a published post are saved only after you confirm them in the chat.', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Saves the post open in the editor with everything changed there, keeping its status (like the Save draft or Update button). A draft or pending post is saved right away. For a published post the changes would go live, so the user sees an action card and the post is saved only after they confirm it. It does not publish a draft (publish_post) or change the status (set_post_status).' . EditorSave::state_line( $this->admin( $context ) );
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'Saving the post open in the editor (save_post and the other tools that save it): only when the user explicitly asks to save, publish, schedule or change the status; never on your own initiative and never just because you changed something with an editor tool. When the user asks for a change and a save in one message, call the editor tool first and the saving tool after it in the same reply. The post is saved by the editor in the user\'s browser after your reply, so you do not see the outcome: say that it is being saved, not that it was saved; the chat shows the user the result.' );
	}

	/**
	 * Queues the save, or shows it as an action card when the post is or becomes published.
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

		$edited  = EditorSave::edited_status( $admin, $post );
		$invalid = EditorSave::invalid_blocks( $admin );

		// A scheduled post goes online on its own later.
		if ( $invalid && ( 'future' === $post->post_status || 'future' === $edited ) ) {
			return EditorSave::invalid_error( $invalid );
		}

		if ( ! EditorSave::is_live( $post->post_status ) && ! EditorSave::is_live( $edited ) ) {
			EditorSave::queue( $admin, array( 'mode' => 'save' ) );

			return array(
				'ok'     => true,
				'status' => 'sent_to_editor',
				'note'   => 'The editor saves the post right after your reply and the chat shows the user whether it worked. In one short sentence, say the post is being saved.' . EditorSave::invalid_note( $invalid ),
			);
		}

		if ( $invalid ) {
			return EditorSave::invalid_error( $invalid );
		}

		$title = EditorSave::title( $post );

		return EditorSave::card(
			$admin,
			$this->name(),
			EditorSave::is_live( $post->post_status ) ? __( 'Update the published post', 'wp-cortex' ) : __( 'Save and publish the post', 'wp-cortex' ),
			EditorSave::is_live( $post->post_status )
				/* translators: %s: post title */
				? sprintf( __( '“%s” is live on the site. Saving puts every change made in the editor online right away.', 'wp-cortex' ), $title )
				/* translators: %s: post title */
				: sprintf( __( 'The status of “%s” was changed to published in the editor. Saving publishes the post.', 'wp-cortex' ), $title ),
			array( 'post_id' => $post->ID )
		);
	}

	/**
	 * Sends a confirmed save to the editor.
	 *
	 * @param array $input Input stored with the action card: post_id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function run_confirmed( array $input ) {
		return EditorSave::confirmed( $input, 'edit_post', array( 'mode' => 'save' ) );
	}
}
