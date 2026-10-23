<?php
/**
 * Uninstall handler.
 *
 * Data is preserved by default. Everything is removed only when the admin has
 * opted in via the "Delete all data on uninstall" setting.
 *
 * @package OBLD
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Scheduled work is removed regardless of the data-retention setting: leaving
// cron entries pointing at classes that no longer exist is never wanted.
// Deactivation clears the recurring sweep; this also clears every queued
// per-award event, whatever its arguments.
wp_clear_scheduled_hook( 'obld_retry_awards' );
wp_unschedule_hook( 'obld_process_award' );

if ( ! get_option( 'obld_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

// Custom tables.
foreach ( array( 'badges', 'triggers', 'awards', 'logs' ) as $obld_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}obld_{$obld_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

// Options and transients (all plugin state is prefixed obld_).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'obld\\_%' OR option_name LIKE '\\_transient\\_obld\\_%' OR option_name LIKE '\\_transient\\_timeout\\_obld\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

// Per-user notice-dismissal state.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'obld\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
