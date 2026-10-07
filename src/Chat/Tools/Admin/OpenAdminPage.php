<?php
/**
 * Admin chat tool: open_admin_page.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Admin\AdminPages;
use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Navigates to a screen of the user's admin menu. Only screens reported by the browser
 * can be opened and the URL is built server-side.
 */
final class OpenAdminPage extends AdminTool {

	public function name(): string {
		return 'open_admin_page';
	}

	public function label(): string {
		return __( 'Open admin screen', 'wp-cortex' );
	}

	/**
	 * Offered when the browser reported the admin menu.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		return (bool) $this->admin( $context )->admin_pages();
	}

	/**
	 * Description for the model, listing the screens.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		$lines = array();

		foreach ( $this->admin( $context )->admin_pages() as $page ) {
			$lines[] = '- ' . $page['path'] . ': ' . $page['label'];
		}

		return "Navigates the user to a WordPress admin screen (menu item), for example Settings › Permalinks or Plugins. Not for posts or pages of the site: use open_post for those. Call it only when the user explicitly asks to open or go to an admin screen. Available screens (page: menu label):\n" . implode( "\n", $lines );
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
				'page' => array(
					'type'        => 'string',
					'description' => 'The page value of one of the available screens.',
					'enum'        => wp_list_pluck( $this->admin( $context )->admin_pages(), 'path' ),
				),
				'tab'  => array(
					'type'        => 'string',
					'description' => 'Optional. The name of a tab to open on that screen after it loads. Use it when the user names a tab; tabs of other screens are not listed. For a tab nested inside another tab, give the path from the outer tab separated by " › ", for example "Visitor chat › Appearance".',
				),
			),
			'required'   => array( 'page' ),
		);
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'When the user asks to go to an admin screen (settings, plugins, users, media library, a post type list and similar), call open_admin_page. When the user names both an admin screen and a tab, call open_admin_page with its tab parameter (a path such as "Visitor chat › Appearance" for nested tabs).' );
	}

	/**
	 * Queues navigation to the screen.
	 *
	 * @param array       $args    Arguments: page, tab.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin = $this->admin( $context );
		$path  = (string) ( $args['page'] ?? '' );
		$match = null;

		foreach ( $admin->admin_pages() as $page ) {
			if ( $page['path'] === $path ) {
				$match = $page;
				break;
			}
		}

		if ( null === $match ) {
			return array(
				'ok'    => false,
				'error' => __( 'Unknown admin screen. Use one of the listed page values.', 'wp-cortex' ),
			);
		}

		$action = array(
			'url'   => admin_url( $match['path'] ),
			'title' => $match['label'],
		);

		$tab = mb_substr( sanitize_text_field( (string) ( $args['tab'] ?? '' ) ), 0, AdminPages::MAX_TAB_LENGTH );

		if ( '' !== $tab ) {
			$action['tab'] = $tab;
		}

		$admin->navigate( $action );

		return array( 'ok' => true );
	}
}
