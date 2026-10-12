<?php
/**
 * Admin chat tool: reload_page.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Reloads the page the user is on. It refuses while that page has unsaved changes, so
 * a reload never discards the user's work.
 */
final class ReloadPage extends AdminTool {

	/**
	 * UI actions that leave unsaved changes in the editor.
	 */
	private const EDIT_ACTIONS = array( 'edit_post', 'edit_seo', 'edit_fields', 'edit_content' );

	public function name(): string {
		return 'reload_page';
	}

	public function label(): string {
		return __( 'Reload page', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Reloads the page the user is currently on in their browser (the chat stays open). It does not open another page: use open_post or open_admin_page for that. It is refused while the page has unsaved changes. Call it only when the user explicitly asks to reload or refresh the page.';
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
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'When the user asks to reload or refresh the page, call reload_page; never reload on your own initiative. If it is refused because of unsaved changes, tell the user to save them first.' );
	}

	/**
	 * Queues the reload, unless the page has unsaved changes.
	 *
	 * @param array       $args    Arguments: none.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin   = $this->admin( $context );
		$unsaved = $admin->has_unsaved_changes();

		// Changes the editor tools make in this reply are not saved either.
		foreach ( $admin->actions() as $action ) {
			if ( in_array( $action['type'] ?? '', self::EDIT_ACTIONS, true ) ) {
				$unsaved = true;
			}
		}

		if ( $unsaved ) {
			return array(
				'ok'    => false,
				'error' => __( 'The page has unsaved changes, so it was not reloaded. Ask the user to save them first.', 'wp-cortex' ),
			);
		}

		$admin->add_action( array( 'type' => 'reload' ) );

		return array(
			'ok'   => true,
			'note' => 'The page reloads right after your reply. In one short sentence, tell the user you are reloading the page.',
		);
	}
}
