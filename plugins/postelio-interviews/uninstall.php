<?php
/**
 * Désinstallation postelio-interviews (opt-in, destructif). Supprime les tables et
 * l'option de schéma. La désactivation simple NE supprime rien.
 *
 * @package Postelio\Interviews
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'postelio_interview_history', 'postelio_interviews' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

delete_option( 'postelio_interviews_schema' );
