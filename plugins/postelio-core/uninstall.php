<?php
/**
 * Désinstallation de Postelio Core.
 *
 * Politique (docs/backend/implementation-plan.md) : la suppression de données n'a
 * lieu QUE sur action explicite. Par défaut, la désinstallation est NON destructive
 * (tables, rôles et options conservés). Pour tout supprimer, définir au préalable
 * l'option `postelio_delete_data_on_uninstall` à true.
 *
 * @package Postelio\Core
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! get_option( 'postelio_delete_data_on_uninstall' ) ) {
	// Conservation des données par défaut.
	return;
}

global $wpdb;

// 1. Suppression de la table d'audit.
$table = $wpdb->prefix . 'postelio_audit_log';
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// 2. Suppression des options du core.
delete_option( 'postelio_core_schema' );
delete_option( 'postelio_platform_version' );
delete_option( 'postelio_delete_data_on_uninstall' );

// 3. Retrait des rôles/capabilities Postelio (si l'autoloader est disponible).
$roles_file = __DIR__ . '/src/Permissions/Roles.php';
$caps_file  = __DIR__ . '/src/Permissions/Capabilities.php';
if ( is_file( $roles_file ) && is_file( $caps_file ) ) {
	require_once $caps_file;
	require_once $roles_file;
	\Postelio\Core\Permissions\Roles::uninstall();
}
