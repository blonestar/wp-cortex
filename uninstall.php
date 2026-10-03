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

foreach ( array( 'wp_cortex_settings', 'wp_cortex_index_run', 'wp_cortex_index_lock', 'wp_cortex_sync_queue', 'wp_cortex_last_sync', 'wp_cortex_db_version' ) as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'wp_cortex_process_queue' );

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wp_cortex_conversations" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

foreach ( array( '_transient_wp_cortex_models_', '_transient_timeout_wp_cortex_models_' ) as $prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
