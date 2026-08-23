<?php
/**
 * Désinstallation postelio-job-sources (opt-in, destructif). Supprime les tables, l'option
 * de schéma et le cache de token. La désactivation NE supprime rien.
 *
 * @package Postelio\JobSources
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'postelio_job_source_sync_runs', 'postelio_external_jobs' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

delete_option( 'postelio_job_sources_schema' );
delete_transient( 'pst_ft_token' );
