<?php
// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;
$table = $wpdb->prefix . 'heatmap_graphs';
// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
// Avoid using $wpdb->prepare here because table names cannot be placeholders; ensure the name is safe and quoted.
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($table) . '`');

delete_option('exaig_heatmap_graph_db_version');

