<?php
// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}
global $wpdb;

$exaig_table = $wpdb->prefix . 'heatmap_graphs';

// Validate identifier
if ( preg_match( '/^[A-Za-z0-9_]+$/', $exaig_table ) ) {
    // Check existence with prepare()
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $exaig_exists = $wpdb->get_var(
        $wpdb->prepare( "SHOW TABLES LIKE %s", $exaig_table )
    );

    if ( $exaig_exists === $exaig_table ) {
        // Table name is validated and safe (plugin prefix + constant string)
        // Still need direct interpolation for identifier
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "DROP TABLE IF EXISTS `{$exaig_table}`" );
    }
}



delete_option('exaig_heatmap_graph_db_version');

