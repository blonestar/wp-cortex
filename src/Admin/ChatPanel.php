<?php
/**
 * Floating admin chat panel.
 *
 * @package WPCortex
 */

namespace WPCortex\Admin;

use WPCortex\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the chat assistant panel on every admin screen.
 *
 * The same panel is shown on the front end by Frontend\FrontendChat.
 */
final class ChatPanel {

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_footer', array( $this, 'render_root' ) );
	}

	/**
	 * Whether the chat panel is enabled in the settings. Missing value counts as enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$value = \WPCortex\Settings::get( 'chat_enabled' );

		return null === $value ? true : (bool) $value;
	}

	/**
	 * Whether the panel should load for the current user.
	 *
	 * @return bool
	 */
	private function should_load(): bool {
		return current_user_can( 'manage_options' ) && self::is_enabled();
	}

	/**
	 * Enqueues the chat assets.
	 */
	public function enqueue(): void {
		if ( ! $this->should_load() ) {
			return;
		}

		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_id = 0;

		if ( $screen && 'post' === $screen->base ) {
			global $post;

			if ( $post instanceof \WP_Post ) {
				$post_id = (int) $post->ID;
			} elseif ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$post_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		self::enqueue_assets(
			array(
				'screen'     => $screen ? (string) $screen->id : '',
				'postId'     => $post_id,
				'adminPages' => self::admin_pages(),
			)
		);
	}

	/**
	 * Admin screens the assistant may open: the admin menu, followed by the tabs and
	 * sections of the Cortex settings screen, which the menu does not list.
	 *
	 * @return array<int, array{path: string, label: string}>
	 */
	private static function admin_pages(): array {
		$pages = AdminPages::from_menu();

		if ( current_user_can( 'manage_options' ) ) {
			$pages = AdminPages::sanitize( array_merge( $pages, ( new SettingsPage() )->chat_screens() ) );
		}

		return $pages;
	}

	/**
	 * Enqueues the chat script and styles with their configuration. Shared by the
	 * admin screens and the front end.
	 *
	 * @param array<string, mixed> $config Panel configuration: screen, postId, adminPages, frontend.
	 */
	public static function enqueue_assets( array $config ): void {
		wp_enqueue_style( 'wp-cortex-chat', WP_CORTEX_URL . 'assets/css/chat.css', array( 'dashicons' ), Plugin::asset_version( 'assets/css/chat.css' ) );
		wp_enqueue_script( 'wp-cortex-markdown', WP_CORTEX_URL . 'assets/js/chat-markdown.js', array(), Plugin::asset_version( 'assets/js/chat-markdown.js' ), true );
		wp_enqueue_script( 'wp-cortex-chat', WP_CORTEX_URL . 'assets/js/chat.js', array( 'wp-api-fetch', 'wp-i18n', 'wp-dom-ready', 'wp-cortex-markdown' ), Plugin::asset_version( 'assets/js/chat.js' ), true );
		wp_set_script_translations( 'wp-cortex-chat', 'wp-cortex' );

		$config = array_merge(
			array(
				'userId'      => get_current_user_id(),
				'screen'      => '',
				'postId'      => 0,
				'settingsUrl' => admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS ),
				'skillsUrl'   => admin_url( 'admin.php?page=' . Menu::SLUG_SKILLS ),
				'adminPages'  => array(),
				'frontend'    => false,
			),
			$config
		);

		wp_add_inline_script( 'wp-cortex-chat', 'window.wpCortexChat = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Prints the root container for the panel.
	 */
	public function render_root(): void {
		if ( ! $this->should_load() ) {
			return;
		}

		echo '<div id="wp-cortex-chat-root"></div>';
	}
}
