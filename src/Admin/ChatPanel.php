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

		wp_enqueue_style( 'wp-cortex-chat', WP_CORTEX_URL . 'assets/css/chat.css', array( 'dashicons' ), Plugin::asset_version( 'assets/css/chat.css' ) );
		wp_enqueue_script( 'wp-cortex-chat', WP_CORTEX_URL . 'assets/js/chat.js', array( 'wp-api-fetch', 'wp-i18n', 'wp-dom-ready' ), Plugin::asset_version( 'assets/js/chat.js' ), true );
		wp_set_script_translations( 'wp-cortex-chat', 'wp-cortex' );

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

		wp_add_inline_script(
			'wp-cortex-chat',
			'window.wpCortexChat = ' . wp_json_encode(
				array(
					'userId'      => get_current_user_id(),
					'screen'      => $screen ? (string) $screen->id : '',
					'postId'      => $post_id,
					'settingsUrl' => admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS ),
				)
			) . ';',
			'before'
		);
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
