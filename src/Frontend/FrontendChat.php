<?php
/**
 * Chat panels on the front end of the site.
 *
 * @package WPCortex
 */

namespace WPCortex\Frontend;

use WPCortex\Admin\ChatPanel;
use WPCortex\Chat\ChatAgent;
use WPCortex\Chat\PublicChatAgent;
use WPCortex\Chat\VisitorImages;
use WPCortex\Plugin;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shows one of two chats on the front end:
 *
 * - administrators (manage_options) get the admin chat, backed by the admin index,
 *   when "chat_frontend" is on;
 * - everyone else gets the visitor chat, backed by the public index only, when
 *   "public_chat_enabled" is on. Administrators see it too while the front-end admin
 *   chat is off, so they can try it.
 */
final class FrontendChat {

	private const MODE_ADMIN  = 'admin';
	private const MODE_PUBLIC = 'public';

	/**
	 * Mode for the current request, resolved once.
	 *
	 * @var string|null
	 */
	private ?string $mode = null;

	/**
	 * Registers front-end hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render_root' ) );
	}

	/**
	 * Which chat to show: "admin", "public" or "" for none.
	 */
	private function mode(): string {
		if ( null !== $this->mode ) {
			return $this->mode;
		}

		$this->mode = '';

		if ( is_embed() || is_customize_preview() || is_feed() || is_robots() ) {
			return $this->mode;
		}

		if ( current_user_can( 'manage_options' ) && Settings::get( 'chat_frontend' ) ) {
			$this->mode = self::MODE_ADMIN;
		} elseif ( Settings::get( 'public_chat_enabled' ) ) {
			$this->mode = self::MODE_PUBLIC;
		}

		return $this->mode;
	}

	/**
	 * Post the visitor is viewing, 0 outside single posts and pages.
	 */
	private function current_post_id(): int {
		return is_singular() ? (int) get_queried_object_id() : 0;
	}

	/**
	 * Enqueues the assets of the active chat.
	 */
	public function enqueue(): void {
		$mode = $this->mode();

		if ( self::MODE_ADMIN === $mode ) {
			ChatPanel::enqueue_assets(
				array(
					'screen'   => ChatAgent::FRONTEND_SCREEN,
					'postId'   => $this->current_post_id(),
					'frontend' => true,
				)
			);
			return;
		}

		if ( self::MODE_PUBLIC !== $mode ) {
			return;
		}

		$title   = (string) Settings::get( 'public_chat_title' );
		$welcome = (string) Settings::get( 'public_chat_welcome' );

		wp_enqueue_style( 'wp-cortex-public-chat', WP_CORTEX_URL . 'assets/css/public-chat.css', array(), Plugin::asset_version( 'assets/css/public-chat.css' ) );
		wp_add_inline_style( 'wp-cortex-public-chat', '#wp-cortex-public-chat-root{' . ChatAppearance::declarations() . '}' );
		wp_enqueue_script( 'wp-cortex-markdown', WP_CORTEX_URL . 'assets/js/chat-markdown.js', array(), Plugin::asset_version( 'assets/js/chat-markdown.js' ), true );
		wp_enqueue_script( 'wp-cortex-public-chat', WP_CORTEX_URL . 'assets/js/public-chat.js', array( 'wp-i18n', 'wp-cortex-markdown' ), Plugin::asset_version( 'assets/js/public-chat.js' ), true );
		wp_set_script_translations( 'wp-cortex-public-chat', 'wp-cortex' );

		// No nonce and no user data: the markup is the same for every visitor, so pages stay cacheable.
		wp_add_inline_script(
			'wp-cortex-public-chat',
			'window.wpCortexPublicChat = ' . wp_json_encode(
				array(
					'endpoint'  => rest_url( 'wp-cortex/v1/public-chat/message' ),
					'postId'    => $this->current_post_id(),
					'title'     => '' !== $title ? $title : __( 'Ask a question', 'wp-cortex' ),
					'welcome'   => '' !== $welcome ? $welcome : __( 'Hi! Ask me anything about this website.', 'wp-cortex' ),
					'label'     => (string) Settings::get( 'public_chat_launcher_label' ),
					'maxLength' => PublicChatAgent::MAX_MESSAGE_LENGTH,
					'images'    => VisitorImages::enabled(),
					'imageSide' => VisitorImages::MAX_SIDE,
					'imageMax'  => VisitorImages::MAX_UPLOAD_BYTES,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Prints the root container of the active chat.
	 */
	public function render_root(): void {
		$mode = $this->mode();

		if ( self::MODE_ADMIN === $mode ) {
			echo '<div id="wp-cortex-chat-root" class="wp-cortex-chat-frontend"></div>';
		} elseif ( self::MODE_PUBLIC === $mode ) {
			echo '<div id="wp-cortex-public-chat-root" class="' . esc_attr( implode( ' ', ChatAppearance::classes() ) ) . '"></div>';
		}
	}
}
