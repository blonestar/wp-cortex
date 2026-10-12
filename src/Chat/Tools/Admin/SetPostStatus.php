<?php
/**
 * Admin chat tool: set_post_status.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Switches the post open in the block editor to draft or submits it for review, and
 * saves it. The browser saves it through the editor; taking a published post offline
 * runs only after the user confirms the action card.
 */
final class SetPostStatus extends AdminTool implements ConfirmableTool {

	/**
	 * Statuses the tool can set.
	 */
	private const STATUSES = array( 'draft', 'pending' );

	public function name(): string {
		return 'set_post_status';
	}

	public function label(): string {
		return __( 'Switch post to draft or pending review', 'wp-cortex' );
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
		return __( 'Only while the block editor of a post is open. Taking a published post offline runs only after you confirm it in the chat.', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Changes the status of the post open in the editor to "draft" (switch to draft, unpublish, unschedule) or "pending" (submit for review) and saves it with everything changed in the editor. A published post would disappear from the site, so the user sees an action card and it runs only after they confirm it; other posts are saved right away. To publish or schedule use publish_post.' . EditorSave::state_line( $this->admin( $context ) );
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
				'status' => array(
					'type'        => 'string',
					'enum'        => self::STATUSES,
					'description' => '"draft" or "pending" (pending review).',
				),
			),
			'required'   => array( 'status' ),
		);
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'Call set_post_status only when the user asks to switch the post open in the editor to draft, unpublish or unschedule it, or submit it for review. For a published post it needs the user\'s confirmation on the action card: do not claim the post was taken offline.' );
	}

	/**
	 * Queues the status change, or shows it as an action card when the post is published.
	 *
	 * @param array       $args    Arguments: status.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin  = $this->admin( $context );
		$post   = EditorSave::post( $admin );
		$status = (string) ( $args['status'] ?? '' );

		if ( null === $post ) {
			return EditorSave::error( __( 'No post is open in the editor.', 'wp-cortex' ) );
		}

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return EditorSave::error( __( 'The status must be "draft" or "pending".', 'wp-cortex' ) );
		}

		if ( $status === $post->post_status && $status === EditorSave::edited_status( $admin, $post ) ) {
			/* translators: %s: post status */
			return EditorSave::error( sprintf( __( 'The post already has the status "%s". Use save_post to save its changes.', 'wp-cortex' ), $status ) );
		}

		$action = array(
			'mode'   => 'status',
			'status' => $status,
		);

		if ( ! EditorSave::is_live( $post->post_status ) ) {
			EditorSave::queue( $admin, $action );

			return array(
				'ok'     => true,
				'status' => 'sent_to_editor',
				'note'   => 'The editor changes the status and saves the post right after your reply; the chat shows the user whether it worked. In one short sentence, say it is being saved.',
			);
		}

		$title = EditorSave::title( $post );

		return EditorSave::card(
			$admin,
			$this->name(),
			'draft' === $status ? __( 'Switch the published post to draft', 'wp-cortex' ) : __( 'Switch the published post to pending review', 'wp-cortex' ),
			/* translators: %s: post title */
			sprintf( __( '“%s” is live on the site. It is taken offline (visitors get a "not found" page) and saved with everything changed in the editor.', 'wp-cortex' ), $title ),
			array(
				'post_id' => $post->ID,
				'status'  => $status,
			)
		);
	}

	/**
	 * Sends a confirmed status change to the editor.
	 *
	 * @param array $input Input stored with the action card: post_id, status.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function run_confirmed( array $input ) {
		$status = (string) ( $input['status'] ?? '' );

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new \WP_Error( 'wp_cortex_invalid_status', __( 'The status must be "draft" or "pending".', 'wp-cortex' ) );
		}

		return EditorSave::confirmed(
			$input,
			'edit_post',
			array(
				'mode'   => 'status',
				'status' => $status,
			)
		);
	}
}
