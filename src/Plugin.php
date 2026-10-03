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
use WPCortex\Cli\Command;
use WPCortex\Indexing\IndexRun;
use WPCortex\Indexing\PostSync;
use WPCortex\Rest\ChatController;
use WPCortex\Rest\IndexController;
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
		( new Abilities() )->register();

		ConversationStore::maybe_upgrade();

		if ( is_admin() ) {
			( new Menu() )->register();
			( new ChatPanel() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cortex', Command::class );
		}
	}

	/**
	 * Activation: prepare the protected data directory and default settings.
	 */
	public static function activate(): void {
		add_option( Settings::OPTION, Settings::defaults(), '', false );
		Storage::ensure_data_dir();
		ConversationStore::install();
	}

	/**
	 * Deactivation: stop background work. Data is kept until uninstall.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( PostSync::CRON_HOOK );
		IndexRun::cancel();
	}
}
