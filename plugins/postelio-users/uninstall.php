<?php
/**
 * Désinstallation de Postelio Users.
 *
 * NON destructif par défaut (comptes/profils conservés). Suppression uniquement si
 * l'option `postelio_delete_data_on_uninstall` est à true. Les rôles/capabilities
 * appartiennent à postelio-core et ne sont pas touchés ici.
 *
 * @package Postelio\Users
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
if ( ! get_option( 'postelio_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

foreach ( array( 'postelio_candidate_profiles', 'postelio_recruiter_profiles' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

foreach ( array(
	'postelio_status',
	'postelio_email_verified_at',
	'postelio_email_verify',
	'postelio_created_at',
	'postelio_last_login_at',
	'postelio_api_tokens',
	'postelio_settings',
) as $meta_key ) {
	delete_metadata( 'user', 0, $meta_key, '', true );
}

delete_option( 'postelio_users_schema' );
