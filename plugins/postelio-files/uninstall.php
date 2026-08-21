<?php
/**
 * Désinstallation de Postelio Files. NON destructif par défaut ; suppression de la
 * table uniquement si `postelio_delete_data_on_uninstall` = true. Les fichiers
 * physiques du stockage privé sont laissés en place (à purger manuellement) pour
 * éviter toute perte accidentelle de pièces encore référencées.
 *
 * @package Postelio\Files
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
if ( ! get_option( 'postelio_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;
$table = $wpdb->prefix . 'postelio_files';
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
delete_option( 'postelio_files_schema' );
