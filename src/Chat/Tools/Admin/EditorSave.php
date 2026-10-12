<?php
/**
 * Shared parts of the admin chat tools that save the post open in the block editor.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The save tools (save_post, publish_post, set_post_status) never write to the database
 * themselves: they add a "save_post" UI action and the browser saves the post through
 * the block editor, as if the user clicked its button. A save that changes what
 * visitors see (the post is or becomes published) is shown as an action card first and
 * is sent to the editor only after the user confirms it.
 */
final class EditorSave {

	/**
	 * Type of the UI action the browser saves the post on.
	 */
	public const ACTION = 'save_post';

	/**
	 * Statuses in which visitors (or logged-in readers) can see the post.
	 */
	private const LIVE = array( 'publish', 'private' );

	/**
	 * Whether the post is visible on the site in this status.
	 *
	 * @param string $status Post status.
	 */
	public static function is_live( string $status ): bool {
		return in_array( $status, self::LIVE, true );
	}

	/**
	 * The post open in the editor, or null when no editor is open.
	 *
	 * @param AdminContext $admin Turn context.
	 */
	public static function post( AdminContext $admin ): ?\WP_Post {
		$post = null !== $admin->editor() && $admin->post_id() > 0 ? get_post( $admin->post_id() ) : null;

		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Status the post would be saved with: the one set in the editor, or the saved one.
	 *
	 * @param AdminContext $admin Turn context.
	 * @param \WP_Post     $post  Post open in the editor.
	 */
	public static function edited_status( AdminContext $admin, \WP_Post $post ): string {
		$status = (string) ( $admin->editor()['status'] ?? '' );

		return '' !== $status ? $status : (string) $post->post_status;
	}

	/**
	 * Sentence about the state of the post, for tool descriptions.
	 *
	 * @param AdminContext $admin Turn context.
	 */
	public static function state_line( AdminContext $admin ): string {
		$post = self::post( $admin );

		if ( null === $post ) {
			return '';
		}

		$edited = self::edited_status( $admin, $post );

		return sprintf(
			' Saved status of the post: %1$s%2$s; unsaved changes in the editor: %3$s.',
			$post->post_status,
			$edited !== $post->post_status ? sprintf( ' (changed to %s in the editor, not saved yet)', $edited ) : '',
			$admin->has_unsaved_changes() ? 'yes' : 'none reported'
		);
	}

	/**
	 * Title of a post for cards.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function title( \WP_Post $post ): string {
		$title = trim( html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ) );

		return '' !== $title ? $title : __( '(no title)', 'wp-cortex' );
	}

	/**
	 * Queues a save for the browser.
	 *
	 * @param AdminContext $admin  Turn context.
	 * @param array        $action Parts of the UI action: mode (save, publish, status) and optionally status and date.
	 */
	public static function queue( AdminContext $admin, array $action ): void {
		$admin->add_action(
			array(
				'type'    => self::ACTION,
				'post_id' => $admin->post_id(),
			) + $action
		);
	}

	/**
	 * Shows a save as an action card the user has to confirm.
	 *
	 * @param AdminContext $admin       Turn context.
	 * @param string       $tool        Function name of the tool.
	 * @param string       $label       Card label.
	 * @param string       $description What happens when the user runs it.
	 * @param array        $input       Input stored with the card, used by run_confirmed().
	 * @return array<string, string> Response for the model.
	 */
	public static function card( AdminContext $admin, string $tool, string $label, string $description, array $input ): array {
		$id = wp_generate_uuid4();

		$admin->set_item(
			'action:' . $id,
			array(
				'role'   => AbilityTool::ACTION_ROLE,
				'action' => array(
					'id'          => $id,
					'tool'        => $tool,
					'label'       => $label,
					'description' => $description,
					'destructive' => false,
					// Runs in the browser: a confirmed card is sent to the editor, which saves the post.
					'client'      => true,
					'input'       => $input,
					'status'      => 'pending',
					'created'     => time(),
				),
			)
		);

		return array(
			'status' => 'awaiting_confirmation',
			'note'   => 'Nothing has been saved yet. The user sees an action card and the post is saved only if they confirm it. Tell the user briefly what will happen and that they can run or cancel it below. Do not call it again.',
		);
	}

	/**
	 * Result of a confirmed card: the UI action for the browser (ChatAgent::resolve_action()
	 * takes it out of the result) and a note for the model.
	 *
	 * @param array  $input  Input stored with the card.
	 * @param string $cap    Meta capability the user needs for the post.
	 * @param array  $action Parts of the UI action: mode and optionally status and date.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function confirmed( array $input, string $cap, array $action ) {
		$post_id = (int) ( $input['post_id'] ?? 0 );

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new \WP_Error( 'wp_cortex_not_found', __( 'The post no longer exists.', 'wp-cortex' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( $cap, $post_id ) ) {
			return new \WP_Error( 'wp_cortex_forbidden', __( 'You are not allowed to do this with the post.', 'wp-cortex' ) );
		}

		return array(
			'status'    => 'sent_to_editor',
			'note'      => 'The editor is saving the post now; the chat shows the user whether it worked. Do not claim it succeeded: say it is being saved (or published, scheduled, switched) and do not call the tool again.',
			'ui_action' => array(
				'type'      => self::ACTION,
				'post_id'   => $post_id,
				'confirmed' => true,
			) + $action,
		);
	}

	/**
	 * Error response.
	 *
	 * @param string $message Message.
	 * @return array{ok: false, error: string}
	 */
	public static function error( string $message ): array {
		return array(
			'ok'    => false,
			'error' => $message,
		);
	}
}
