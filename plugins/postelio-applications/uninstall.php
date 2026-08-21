<?php
/**
 * Désinstallation de Postelio Applications. NON destructif par défaut ; suppression
 * uniquement si `postelio_delete_data_on_uninstall` est à true.
 *
 * @package Postelio\Applications
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
if ( ! get_option( 'postelio_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;
foreach ( array( 'postelio_applications', 'postelio_application_history', 'postelio_recruiter_notes' ) as $s ) {
	$table = $wpdb->prefix . $s;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}
delete_option( 'postelio_applications_schema' );
