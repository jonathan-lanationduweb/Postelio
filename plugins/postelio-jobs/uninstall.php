<?php
/**
 * Désinstallation de Postelio Jobs. NON destructif par défaut ; suppression des
 * offres uniquement si l'option `postelio_delete_data_on_uninstall` est à true.
 *
 * @package Postelio\Jobs
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
if ( ! get_option( 'postelio_delete_data_on_uninstall' ) ) {
	return;
}

$ids = get_posts(
	array(
		'post_type'      => 'postelio_job',
		'post_status'    => 'any',
		'fields'         => 'ids',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
	)
);
foreach ( $ids as $id ) {
	wp_delete_post( (int) $id, true );
}
