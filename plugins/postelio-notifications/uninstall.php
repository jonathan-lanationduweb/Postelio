<?php
/**
 * Désinstallation postelio-notifications (opt-in, destructif). Supprime les tables,
 * l'option de schéma et les préférences user_meta. La désactivation NE supprime rien.
 *
 * @package Postelio\Notifications
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'postelio_notification_deliveries', 'postelio_notifications' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

delete_option( 'postelio_notifications_schema' );
delete_metadata( 'user', 0, 'pst_notification_prefs', '', true );
