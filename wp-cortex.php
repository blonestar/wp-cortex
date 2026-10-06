<?php
/**
 * Plugin Name:       WP Cortex
 * Description:       Memory layer for WordPress: indexes site content into a local SQLite store (structured fields, full-text and vector embeddings) for AI search and chat.
 * Plugin URI:        https://github.com/blonestar/wp-cortex/
 * Version:           0.9.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Bojan
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/blonestar/wp-cortex/
 * Text Domain:       wp-cortex
 *
 * @package WPCortex
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_CORTEX_VERSION', '0.9.0' );
define( 'WP_CORTEX_FILE', __FILE__ );
define( 'WP_CORTEX_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_CORTEX_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4 autoloader for the WPCortex\ namespace (src/).
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'WPCortex\\';

		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$file = WP_CORTEX_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

WPCortex\Update\PluginUpdater::register();

/**
 * The SQLite store requires pdo_sqlite with FTS5. Bail out with a notice instead of fataling.
 */
if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'WP Cortex requires the pdo_sqlite PHP extension. The plugin is inactive until it is available.', 'wp-cortex' )
			);
		}
	);
	return;
}

register_activation_hook( __FILE__, array( WPCortex\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( WPCortex\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( WPCortex\Plugin::class, 'boot' ) );
