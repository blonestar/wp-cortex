<?php
/**
 * Removes all WP Cortex data on uninstall.
 *
 * @package WPCortex
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Storage/Storage.php';
require_once __DIR__ . '/src/Storage/Database.php';

$wp_cortex_settings = get_option( 'wp_cortex_settings' );

if ( ! is_array( $wp_cortex_settings ) || ! isset( $wp_cortex_settings['uninstall_delete_index'] ) || $wp_cortex_settings['uninstall_delete_index'] ) {
	\WPCortex\Storage\Storage::delete_data_dir();
} else {
	// Keep the databases and the options that locate them; visitor images go with their conversations.
	\WPCortex\Storage\Storage::delete_dir( \WPCortex\Storage\Storage::visitor_images_dir() );
	delete_transient( 'wp_cortex_storage_exposed' );
}

foreach ( array( 'wp_cortex_settings', 'wp_cortex_index_run', 'wp_cortex_index_lock', 'wp_cortex_sync_queue', 'wp_cortex_last_sync', 'wp_cortex_db_version', 'wp_cortex_skills_db_version', 'wp_cortex_visitor_chats_db_version', 'wp_cortex_issue_reports_db_version' ) as $option ) {
	delete_option( $option );
}

delete_transient( 'wp_cortex_update_release' );

wp_clear_scheduled_hook( 'wp_cortex_process_queue' );
wp_clear_scheduled_hook( 'wp_cortex_index_watchdog' );
wp_clear_scheduled_hook( 'wp_cortex_purge_visitor_chats' );

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wp_cortex_conversations" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wp_cortex_skills" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wp_cortex_visitor_chats" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wp_cortex_issue_reports" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

foreach ( array( '_transient_wp_cortex_models_', '_transient_timeout_wp_cortex_models_', '_transient_wp_cortex_rate_', '_transient_timeout_wp_cortex_rate_' ) as $prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
