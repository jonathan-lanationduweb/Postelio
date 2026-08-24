<?php
/**
 * Désinstallation postelio-billing (opt-in, destructif). Supprime les tables et l'option de
 * schéma. La désactivation NE supprime rien (orders/payments/events conservés — obligations
 * comptables). ATTENTION : supprimer l'historique financier peut contrevenir aux obligations
 * légales de conservation (durée exacte À VALIDER).
 *
 * @package Postelio\Billing
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'postelio_billing_events', 'postelio_billing_payments', 'postelio_billing_orders' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

delete_option( 'postelio_billing_schema' );
