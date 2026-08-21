<?php
/**
 * Désinstallation de Postelio Companies.
 *
 * NON destructif par défaut. Suppression uniquement si l'option
 * `postelio_delete_data_on_uninstall` est à true.
 *
 * @package Postelio\Companies
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
if ( ! get_option( 'postelio_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

// 1. Entreprises (CPT) + leurs métadonnées.
$ids = get_posts(
	array(
		'post_type'      => 'postelio_company',
		'post_status'    => 'any',
		'fields'         => 'ids',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
	)
);
foreach ( $ids as $id ) {
	wp_delete_post( (int) $id, true );
}

// 2. Table des membres.
$table = $wpdb->prefix . 'postelio_company_members';
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// 3. Option de schéma.
delete_option( 'postelio_companies_schema' );
