<?php
/**
 * Désinstallation postelio-moderation (opt-in, destructif). Supprime les tables et l'option
 * de schéma. La désactivation NE supprime rien (reports/cases/events conservés).
 *
 * @package Postelio\Moderation
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'postelio_moderation_case_events', 'postelio_moderation_cases', 'postelio_moderation_reports' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

delete_option( 'postelio_moderation_schema' );
