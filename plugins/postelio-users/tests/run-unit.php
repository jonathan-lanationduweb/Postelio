<?php
/**
 * Tests unitaires postelio-users SANS dépendance (ni PHPUnit ni WordPress).
 *
 *   php plugins/postelio-users/tests/run-unit.php
 *
 * Couvre la logique testable en isolation : TokenService (parse/issue/validate/
 * refresh/revoke, expiration) et SettingsService (défauts, whitelist, fusion),
 * via des shims usermeta en mémoire.
 *
 * @package Postelio\Users\Tests
 */

declare( strict_types=1 );

define( 'POSTELIO_CORE_TESTING', true );
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// --- Shims WordPress (store usermeta en mémoire) ---------------------------
$GLOBALS['__um'] = array(); // [user_id][key] => value

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) {
		return $GLOBALS['__um'][ $uid ][ $key ] ?? ( $single ? '' : array() );
	}
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $key, $val ) {
		$GLOBALS['__um'][ $uid ][ $key ] = $val;
		return true;
	}
}
if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( $uid, $key ) {
		unset( $GLOBALS['__um'][ $uid ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) {
		return preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $k ) );
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	$GLOBALS['__pw'] = 0;
	function wp_generate_password( $len = 12, $special = true ) {
		// Déterministe mais unique par appel (suffit pour les tests).
		return substr( hash( 'sha256', 'seed' . ( ++$GLOBALS['__pw'] ) ), 0, $len );
	}
}

require_once dirname( __DIR__ ) . '/src/Auth/TokenService.php';
require_once dirname( __DIR__ ) . '/src/Settings/SettingsService.php';

use Postelio\Users\Auth\TokenService;
use Postelio\Users\Settings\SettingsService;

// --- Assertions ------------------------------------------------------------
$tests  = 0;
$failed = array();
function check( string $label, bool $cond ): void {
	global $tests, $failed;
	++$tests;
	echo ( $cond ? '  [ok]   ' : '  [FAIL] ' ) . $label . "\n";
	if ( ! $cond ) {
		$failed[] = $label;
	}
}

echo "== TokenService::parse ==\n";
check( 'jeton malformé (1 partie) => null', null === TokenService::parse( 'abc' ) );
check( 'uid non numérique => null', null === TokenService::parse( 'x.tid.secret' ) );
$p = TokenService::parse( '42.tid123.secretpart' );
check( 'parse valide uid', $p && 42 === $p['uid'] );
check( 'parse valide tid', $p && 'tid123' === $p['tid'] );

echo "== TokenService issue/validate ==\n";
$svc  = new TokenService();
$iss  = $svc->issue( 7, 'test' );
check( 'issue renvoie un token', isset( $iss['token'] ) && is_string( $iss['token'] ) );
check( 'token commence par uid', 0 === strpos( $iss['token'], '7.' ) );
check( 'validate OK => uid 7', 7 === $svc->validate( $iss['token'] ) );
check( 'seul le hash est stocké (pas le secret)', ! str_contains( wp_json_encode_safe( $GLOBALS['__um'][7] ), TokenService::parse( $iss['token'] )['secret'] ) );

echo "== TokenService rejets ==\n";
$parsed  = TokenService::parse( $iss['token'] );
$forged  = sprintf( '7.%s.%s', $parsed['tid'], 'mauvaissecret' );
check( 'mauvais secret => 0', 0 === $svc->validate( $forged ) );
check( 'jeton inexistant => 0', 0 === $svc->validate( '7.absent.xxxx' ) );

// Expiration : on force la date d'expiration dans le passé.
$GLOBALS['__um'][7][ TokenService::META_KEY ][ $parsed['tid'] ]['expires'] = time() - 10;
check( 'jeton expiré => 0', 0 === $svc->validate( $iss['token'] ) );

echo "== TokenService refresh/revoke ==\n";
$svc2  = new TokenService();
$a     = $svc2->issue( 9 );
$b     = $svc2->refresh( $a['token'] );
check( 'refresh renvoie un nouveau jeton', $b && $b['token'] !== $a['token'] );
check( 'ancien jeton invalidé', 0 === $svc2->validate( $a['token'] ) );
check( 'nouveau jeton valide', 9 === $svc2->validate( $b['token'] ) );
$svc2->revoke_all( 9 );
check( 'revoke_all invalide tout', 0 === $svc2->validate( $b['token'] ) );

echo "== SettingsService ==\n";
$set = new SettingsService();
$def = SettingsService::defaults();
check( 'défauts langue=fr', 'fr' === $def['langue'] );
check( 'défauts notifications.conseils=false', false === $def['notifications']['conseils'] );
$u1 = $set->update( 5, array( 'langue' => 'en', 'notifications' => array( 'conseils' => true, 'inconnu' => true ) ) );
check( 'langue mise à jour', 'en' === $u1['langue'] );
check( 'notif connue mise à jour', true === $u1['notifications']['conseils'] );
check( 'clé notif inconnue ignorée', ! array_key_exists( 'inconnu', $u1['notifications'] ) );
$u2 = $set->update( 5, array( 'langue' => 'zz' ) );
check( 'langue invalide ignorée (reste en)', 'en' === $u2['langue'] );
check( 'get() fusionne défauts + stocké', true === $set->get( 5 )['notifications']['changement_statut'] );

// Petit helper JSON sûr pour l'assertion de non-fuite du secret.
function wp_json_encode_safe( $v ): string {
	return json_encode( $v );
}

echo "\n";
if ( empty( $failed ) ) {
	echo "RÉSULTAT : {$tests} assertions, 0 échec. OK\n";
	exit( 0 );
}
echo 'RÉSULTAT : ' . count( $failed ) . " échec(s) sur {$tests} assertions.\n";
exit( 1 );
