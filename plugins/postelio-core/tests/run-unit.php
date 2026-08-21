<?php
/**
 * Tests unitaires SANS dépendance (ni PHPUnit ni WordPress).
 *
 * Exécution :  php plugins/postelio-core/tests/run-unit.php
 *
 * Couvre la logique pure du socle : Errors, ApiError, Response, Capabilities,
 * Registry (tri topologique + cycles), Events (normalisation + émission).
 *
 * @package Postelio\Core\Tests
 */

declare( strict_types=1 );

define( 'POSTELIO_CORE_TESTING', true );

// --- Shims minimalistes des hooks WordPress (pour tester Events) ------------
$GLOBALS['__pst_actions']  = array(); // hook => [callbacks]
$GLOBALS['__pst_fired']    = array(); // liste des do_action déclenchés

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
		$GLOBALS['__pst_actions'][ $hook ][] = $cb;
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['__pst_fired'][] = array( 'hook' => $hook, 'args' => $args );
		foreach ( $GLOBALS['__pst_actions'][ $hook ] ?? array() as $cb ) {
			$cb( ...$args );
		}
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		return '2026-01-01 00:00:00';
	}
}

// --- Chargement des classes WP-free ----------------------------------------
$src = dirname( __DIR__ ) . '/src/';
require_once $src . 'Errors.php';
require_once $src . 'ApiError.php';
require_once $src . 'Support/Response.php';
require_once $src . 'Permissions/Capabilities.php';
require_once $src . 'Registry.php';
require_once $src . 'Events.php';

use Postelio\Core\ApiError;
use Postelio\Core\Errors;
use Postelio\Core\Events;
use Postelio\Core\Permissions\Capabilities;
use Postelio\Core\Registry;
use Postelio\Core\Support\Response;

// --- Micro-framework d'assertions ------------------------------------------
$tests  = 0;
$failed = array();

function check( string $label, bool $cond ): void {
	global $tests, $failed;
	++$tests;
	if ( ! $cond ) {
		$failed[] = $label;
		echo "  [FAIL] {$label}\n";
	} else {
		echo "  [ok]   {$label}\n";
	}
}

function throws( callable $fn ): bool {
	try {
		$fn();
	} catch ( \Throwable $e ) {
		return true;
	}
	return false;
}

echo "== Errors ==\n";
check( 'MAP contient les 11 codes', count( Errors::MAP ) === 11 );
check( 'forbidden => 403', Errors::http_status( 'forbidden' ) === 403 );
check( 'code inconnu => 500', Errors::http_status( 'nope' ) === 500 );
check( 'is_known(validation_error)', Errors::is_known( 'validation_error' ) );
$env = Errors::envelope( 'not_found', 'X' );
check( 'envelope.error.code', $env['error']['code'] === 'not_found' );
$env2 = Errors::envelope( 'inconnu', 'X' );
check( 'envelope code inconnu coercé', $env2['error']['code'] === 'server_error' );

echo "== ApiError ==\n";
$e = ApiError::forbidden();
check( 'forbidden code', $e->error_code() === 'forbidden' );
check( 'forbidden status 403', $e->http_status() === 403 );
$ev = ApiError::validation( array( 'email' => 'requis' ) )->to_envelope();
check( 'validation details propagés', isset( $ev['error']['details']->email ) );
check( 'validation status 422', ApiError::validation( array() )->http_status() === 422 );

echo "== Response ==\n";
$ok = Response::ok( array( 'a' => 1 ) );
check( 'ok a data', isset( $ok['data'] ) && $ok['data']['a'] === 1 );
check( 'per_page clamp bas => 20', Response::clamp_per_page( 0 ) === 20 );
check( 'per_page clamp haut => 100', Response::clamp_per_page( 5000 ) === 100 );
$pg = Response::paginated( array( 1, 2, 3 ), 1, 20, 42 );
check( 'pagination total_pages', $pg['meta']['pagination']['total_pages'] === 3 );
check( 'pagination data réindexée', $pg['data'] === array( 1, 2, 3 ) );

echo "== Capabilities ==\n";
check( 'candidate a pst_apply_job', in_array( 'pst_apply_job', Capabilities::candidate(), true ) );
check( 'recruiter a pst_publish_job', in_array( 'pst_publish_job', Capabilities::recruiter(), true ) );
check( 'admin cumule tout', Capabilities::for_role( Capabilities::ROLE_ADMIN ) === Capabilities::all() );
check( 'all() dédupliqué', count( Capabilities::all() ) === count( array_unique( Capabilities::all() ) ) );
check( '5 rôles', count( Capabilities::roles() ) === 5 );

echo "== Registry ==\n";
$r = new Registry();
$r->register( 'core', array( 'load_order' => 0 ) );
$r->register( 'users', array( 'requires' => array( 'core' ), 'load_order' => 10 ) );
$r->register( 'jobs', array( 'requires' => array( 'core', 'users' ), 'load_order' => 20 ) );
check( 'has(core)', $r->has( 'core' ) );
check( 'get(users).version défaut', $r->get( 'users' )['version'] === '0.0.0' );
check( 'doublon rejeté', throws( fn() => $r->register( 'core' ) ) );
$order = $r->boot_order();
check( 'core avant users', array_search( 'core', $order, true ) < array_search( 'users', $order, true ) );
check( 'users avant jobs', array_search( 'users', $order, true ) < array_search( 'jobs', $order, true ) );

$r2 = new Registry();
$r2->register( 'a', array( 'requires' => array( 'ghost' ) ) );
check( 'dépendance manquante détectée', ! empty( $r2->missing_dependencies() ) );
check( 'boot_order refuse dépendance manquante', throws( fn() => $r2->boot_order() ) );

$r3 = new Registry();
$r3->register( 'x', array( 'requires' => array( 'y' ) ) );
$r3->register( 'y', array( 'requires' => array( 'x' ) ) );
check( 'cycle détecté', throws( fn() => $r3->boot_order() ) );

echo "== Events ==\n";
check( 'hook() préfixe', Events::hook( 'application.created' ) === 'postelio/application.created' );
check( 'hook() idempotent', Events::hook( 'postelio/x' ) === 'postelio/x' );
$GLOBALS['__pst_fired'] = array();
$bus                    = new Events();
$received               = array();
$bus->on( 'application.created', function ( $payload ) use ( &$received ) {
	$received[] = $payload;
} );
$bus->emit( 'application.created', array( 'id' => 7 ) );
check( 'listener reçoit le payload', ( $received[0]['id'] ?? null ) === 7 );
$hooks_fired = array_column( $GLOBALS['__pst_fired'], 'hook' );
check( 'hook spécifique déclenché', in_array( 'postelio/application.created', $hooks_fired, true ) );
check( 'hook générique déclenché', in_array( Events::HOOK_ANY, $hooks_fired, true ) );

// --- Bilan ------------------------------------------------------------------
echo "\n";
if ( empty( $failed ) ) {
	echo "RÉSULTAT : {$tests} assertions, 0 échec. OK\n";
	exit( 0 );
}
echo 'RÉSULTAT : ' . count( $failed ) . " échec(s) sur {$tests} assertions.\n";
exit( 1 );
