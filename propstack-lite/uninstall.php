<?php
/**
 * Entfernt beim Löschen des Plugins alle Daten: Tabelle, Optionen, Transients, Cron-Events.
 * Propstack selbst wird nicht verändert.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}psl_properties" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

foreach ( [ 'propstack_lite_settings', 'psl_db_version', 'psl_sync_state', 'psl_sync_lock', 'propstack_lite_cache_salt' ] as $option ) {
	delete_option( $option );
}

delete_transient( 'psl_property_statuses' );
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_psl\_%' OR option_name LIKE '\_transient\_timeout\_psl\_%'
	    OR option_name LIKE '\_transient\_propstack\_lite\_cache%' OR option_name LIKE '\_transient\_timeout\_propstack\_lite\_cache%'"
);

foreach ( [ 'psl_sync_incremental', 'psl_sync_full', 'psl_sync_full_once', 'psl_sync_incremental_once' ] as $hook ) {
	wp_clear_scheduled_hook( $hook );
}
