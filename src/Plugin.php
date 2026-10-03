<?php
/**
 * Plugin bootstrap.
 *
 * @package WPCortex
 */

namespace WPCortex;

use WPCortex\Abilities\Abilities;
use WPCortex\Admin\ChatPanel;
use WPCortex\Admin\Menu;
use WPCortex\Chat\ConversationStore;
use WPCortex\Chat\SkillStore;
use WPCortex\Cli\Command;
use WPCortex\Frontend\FrontendChat;
use WPCortex\Indexing\IndexRun;
use WPCortex\Indexing\PostSync;
use WPCortex\Rest\ChatController;
use WPCortex\Rest\IndexController;
use WPCortex\Rest\PublicChatController;
use WPCortex\Rest\SkillController;
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
		( new Abilities() )->register();

		ConversationStore::maybe_upgrade();
		SkillStore::maybe_upgrade();

		if ( is_admin() ) {
			( new Menu() )->register();
			( new ChatPanel() )->register();
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
	 * Activation: prepare the protected data directory and default settings.
	 */
	public static function activate(): void {
		add_option( Settings::OPTION, Settings::defaults(), '', false );
		Storage::ensure_data_dir();
		ConversationStore::install();
		SkillStore::install();
	}

	/**
	 * Deactivation: stop background work. Data is kept until uninstall.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( PostSync::CRON_HOOK );
		IndexRun::cancel();
	}
}
