<?php
/**
 * Désinstallation postelio-skills (opt-in, destructif). Supprime la table des commentaires,
 * l'option de schéma, et les savoir-faire (CPT) + leurs meta. La DÉSACTIVATION ne supprime rien.
 *
 * @package Postelio\Skills
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// CPT + meta (savoir-faire).
$ids = get_posts( array( 'post_type' => 'postelio_skill', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true ) );
foreach ( $ids as $id ) {
	wp_delete_post( (int) $id, true );
}

// Table des commentaires.
$table = $wpdb->prefix . 'postelio_skill_comments';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB

delete_option( 'postelio_skills_schema' );
