<?php
/**
 * Removes all WP Cortex data on uninstall.
 *
 * @package WPCortex
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Storage/Storage.php';
require_once __DIR__ . '/src/Storage/Database.php';

\WPCortex\Storage\Storage::delete_data_dir();

foreach ( array( 'wp_cortex_settings', 'wp_cortex_index_run', 'wp_cortex_index_lock', 'wp_cortex_sync_queue', 'wp_cortex_last_sync' ) as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'wp_cortex_process_queue' );
