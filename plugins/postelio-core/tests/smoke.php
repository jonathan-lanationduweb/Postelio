<?php
/**
 * Smoke test sur WordPress vivant. À exécuter via WP-CLI :
 *
 *   wp eval-file plugins/postelio-core/tests/smoke.php --path=wordpress
 *
 * Vérifie, sur l'installation réelle : table d'audit, rôles/capabilities, routes
 * REST transversales, chaîne événement → audit, idempotence du migrateur.
 *
 * @package Postelio\Core\Tests
 */

use Postelio\Core\Audit\AuditLog;
use Postelio\Core\Permissions\Capabilities;
use Postelio\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	echo "Doit être exécuté via WP-CLI (wp eval-file).\n";
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$fail = array();
$pass = 0;
$t    = static function ( string $label, bool $cond ) use ( &$fail, &$pass ): void {
	if ( $cond ) {
		++$pass;
		echo "  [ok]   {$label}\n";
	} else {
		$fail[] = $label;
		echo "  [FAIL] {$label}\n";
	}
};

global $wpdb;

echo "== Chargement ==\n";
$t( 'classe Plugin chargée', class_exists( '\\Postelio\\Core\\Plugin' ) );
$t( 'plugin postelio-core actif', is_plugin_active( 'postelio-core/postelio-core.php' ) );

echo "== Migrations / table d'audit ==\n";
$table   = $wpdb->prefix . 'postelio_audit_log';
$exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
$t( "table {$table} existe", $exists );
$t( 'schema core = 1', (string) get_option( Plugin::SCHEMA_OPTION ) === '1' );

// Idempotence : relancer les migrations ne doit rien appliquer.
$applied = Plugin::instance()->migrator()->migrate( Plugin::MODULE );
$t( 'migrate() idempotent (0 appliqué)', 0 === $applied );

echo "== Rôles & capabilities ==\n";
foreach ( Capabilities::roles() as $role ) {
	$t( "rôle {$role} présent", null !== get_role( $role ) );
}
$cand = get_role( Capabilities::ROLE_CANDIDATE );
$t( 'candidat a pst_apply_job', $cand && $cand->has_cap( 'pst_apply_job' ) );
$rec = get_role( Capabilities::ROLE_RECRUITER );
$t( 'recruteur a pst_publish_job', $rec && $rec->has_cap( 'pst_publish_job' ) );
$admin = get_role( 'administrator' );
$t( 'admin WP a pst_manage_platform', $admin && $admin->has_cap( 'pst_manage_platform' ) );

echo "== Routes REST transversales ==\n";
$routes = rest_get_server()->get_routes();
foreach ( array( '/postelio/v1/health', '/postelio/v1/version', '/postelio/v1/config', '/postelio/v1/me' ) as $route ) {
	$t( "route {$route} enregistrée", isset( $routes[ $route ] ) );
}

echo "== Chaîne événement -> audit ==\n";
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
Plugin::instance()->events()->emit(
	'smoke.test',
	array(
		'resource_type' => 'smoke',
		'resource_id'   => '4242',
		'audit'         => array( 'note' => 'smoke-test' ),
	)
);
$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
$t( 'événement auditable écrit 1 ligne', $after === $before + 1 );

$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE action = %s ORDER BY id DESC LIMIT 1", 'smoke.test' ) );
$t( 'ligne audit action=smoke.test', $row && 'smoke.test' === $row->action );
$t( 'ligne audit resource_id=4242', $row && '4242' === $row->resource_id );

// Un événement de la denylist ne doit PAS être audité.
$before2 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
Plugin::instance()->events()->emit( 'message.read', array( 'id' => 1 ) );
$after2 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
$t( 'événement denylist NON audité', $after2 === $before2 );

// Nettoyage des lignes de test.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE action = %s", 'smoke.test' ) );

echo "\n";
if ( empty( $fail ) ) {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::success( "Smoke OK : {$pass} vérifications passées." );
	} else {
		echo "Smoke OK : {$pass} vérifications passées.\n";
	}
} else {
	$msg = count( $fail ) . ' échec(s) : ' . implode( ' | ', $fail );
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( $msg );
	} else {
		echo "ÉCHEC : {$msg}\n";
		exit( 1 );
	}
}
