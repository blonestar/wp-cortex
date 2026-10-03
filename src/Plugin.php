<?php
/**
 * Plugin bootstrap.
 *
 * @package WPCortex
 */

namespace WPCortex;

use WPCortex\Admin\Menu;
use WPCortex\Cli\Command;
use WPCortex\Indexing\IndexRun;
use WPCortex\Indexing\PostSync;
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

		if ( is_admin() ) {
			( new Menu() )->register();
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
	}

	/**
	 * Deactivation: stop background work. Data is kept until uninstall.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( PostSync::CRON_HOOK );
		IndexRun::cancel();
	}
}
