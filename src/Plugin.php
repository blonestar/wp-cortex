<?php
/**
 * Plugin bootstrap.
 *
 * @package WPCortex
 */

namespace WPCortex;

use WPCortex\Abilities\Abilities;
use WPCortex\Admin\ChatPanel;
use WPCortex\Admin\DashboardWidget;
use WPCortex\Admin\Menu;
use WPCortex\Chat\ConversationStore;
use WPCortex\Chat\IssueReportStore;
use WPCortex\Chat\SkillStore;
use WPCortex\Chat\VisitorChatStore;
use WPCortex\Chat\VisitorImages;
use WPCortex\Cli\Command;
use WPCortex\Frontend\FrontendChat;
use WPCortex\Indexing\IndexRun;
use WPCortex\Indexing\PostSync;
use WPCortex\Rest\ChatController;
use WPCortex\Rest\IndexController;
use WPCortex\Rest\IssueReportController;
use WPCortex\Rest\PublicChatController;
use WPCortex\Rest\SkillController;
use WPCortex\Rest\VisitorChatController;
use WPCortex\Storage\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin services together.
 */
final class Plugin {

	/**
	 * Registers all hooks. Runs on plugins_loaded.
	 */
	public static function boot(): void {
		( new PostSync() )->register();
		( new IndexController() )->register();
		( new ChatController() )->register();
		( new PublicChatController() )->register();
		( new SkillController() )->register();
		( new VisitorChatController() )->register();
		( new IssueReportController() )->register();
		( new Abilities() )->register();

		ConversationStore::maybe_upgrade();
		SkillStore::maybe_upgrade();
		VisitorChatStore::maybe_upgrade();
		IssueReportStore::maybe_upgrade();

		add_action( VisitorChatStore::PURGE_HOOK, array( self::class, 'purge_visitor_chats' ) );
		add_action( 'admin_init', array( self::class, 'privacy_policy_content' ) );
		add_action( 'admin_init', array( self::class, 'relocate_data_dir' ) );

		if ( ! wp_next_scheduled( VisitorChatStore::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', VisitorChatStore::PURGE_HOOK );
		}

		if ( is_admin() ) {
			( new Menu() )->register();
			( new ChatPanel() )->register();
			( new DashboardWidget() )->register();
		} else {
			( new FrontendChat() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cortex', Command::class );
		}
	}

	/**
	 * Asset version for cache busting: the plugin version plus the file's modification
	 * time, so changed JS/CSS is reloaded between releases.
	 *
	 * @param string $path Path relative to the plugin directory, e.g. 'assets/js/chat.js'.
	 */
	public static function asset_version( string $path ): string {
		$mtime = @filemtime( WP_CORTEX_DIR . $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return false === $mtime ? WP_CORTEX_VERSION : WP_CORTEX_VERSION . '.' . $mtime;
	}

	/**
	 * Daily cron: deletes visitor chats older than the retention setting.
	 */
	public static function purge_visitor_chats(): void {
		( new VisitorChatStore() )->purge( (int) Settings::get( 'public_chat_retention' ) );
	}

	/**
	 * Suggested privacy policy text (Settings > Privacy) while the visitor chat is on.
	 */
	public static function privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) || ! Settings::get( 'public_chat_enabled' ) ) {
			return;
		}

		$text = '<p>' . esc_html__( 'This site offers a chat assistant that answers questions using an AI service. The messages you send in the chat are sent to that service to generate the answers.', 'wp-cortex' ) . '</p>';

		if ( Settings::get( 'public_chat_reports' ) ) {
			$text .= '<p>' . esc_html__( 'If you report a problem with the site in the chat (for example a typo or a broken image), the report is stored on this site with the address of the page it is about, so that the site team can fix it.', 'wp-cortex' ) . '</p>';
		}

		if ( VisitorImages::enabled() ) {
			$text .= '<p>' . esc_html__( 'You can attach images to your chat messages, for example screenshots. They are sent to the AI service together with your message; if conversations are stored, they are stored with the conversation and deleted with it. Avoid sending images that show personal data you do not want to share.', 'wp-cortex' ) . '</p>';
		}

		if ( Settings::get( 'public_chat_log' ) ) {
			$text .= '<p>' . esc_html__( 'Chat conversations are stored on this site so that the site team can read them. If you choose to leave contact details in the chat (such as your name, email address, phone number, address or website URL(s)), they are stored with the conversation and used only to get back to you. No cookie is stored.', 'wp-cortex' ) . '</p>';

			if ( Settings::get( 'public_chat_store_ip' ) ) {
				$text .= '<p>' . esc_html__( 'Your IP address is stored with the conversation to protect the chat from abuse and to help the site team handle your request.', 'wp-cortex' ) . '</p>';
			}
		}

		wp_add_privacy_policy_content( __( 'WP Cortex', 'wp-cortex' ), wp_kses_post( $text ) );
	}

	/**
	 * Moves data stored in uploads by earlier versions to a private location, once, when an
	 * administrator opens the admin.
	 */
	public static function relocate_data_dir(): void {
		if ( current_user_can( 'manage_options' ) && ! wp_doing_ajax() ) {
			Storage::maybe_relocate();
		}
	}

	/**
	 * Activation: prepare the protected data directory and default settings.
	 */
	public static function activate(): void {
		add_option( Settings::OPTION, Settings::defaults(), '', false );
		Storage::ensure_data_dir();
		ConversationStore::install();
		SkillStore::install();
		VisitorChatStore::install();
		IssueReportStore::install();
	}

	/**
	 * Deactivation: stop background work. Data is kept until uninstall.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( PostSync::CRON_HOOK );
		wp_clear_scheduled_hook( VisitorChatStore::PURGE_HOOK );
		IndexRun::cancel();
	}
}
