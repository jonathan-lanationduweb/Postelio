<?php
/**
 * Désinstallation postelio-alerts (opt-in, destructif — cohérent avec les autres plugins
 * Postelio). Supprime les tables (favoris, recherches sauvegardées, deliveries) et l'option de
 * schéma. La DÉSACTIVATION ne supprime AUCUNE donnée (seulement les tâches planifiées).
 *
 * @package Postelio\Alerts
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'postelio_job_favorites', 'postelio_saved_searches', 'postelio_alert_deliveries' ) as $t ) {
	$table = $wpdb->prefix . $t;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

delete_option( 'postelio_alerts_schema' );
