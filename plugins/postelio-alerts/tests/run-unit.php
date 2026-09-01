<?php
/**
 * Tests unitaires postelio-alerts SANS dépendance (ni PHPUnit ni WordPress).
 *
 *   php plugins/postelio-alerts/tests/run-unit.php
 *
 * Couvre : ParisSchedule (07h30 Europe/Paris, DST hiver/été, hebdomadaire, échéance stricte) ;
 * FilterValidator (whitelist, strict vs permissif, published_after interne, empreinte stable) ;
 * constantes des repositories. Le comportement DB/REST est couvert par le smoke.
 *
 * @package Postelio\Alerts\Tests
 */

declare( strict_types=1 );
define( 'POSTELIO_ALERTS_TESTING', true );
define( 'POSTELIO_CORE_TESTING', true );

// Shims WP minimaux.
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', (string) $s ) ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

$core = dirname( __DIR__, 2 ) . '/postelio-core/src';
require_once $core . '/Errors.php';
require_once $core . '/ApiError.php';
require_once dirname( __DIR__ ) . '/src/Time/ParisSchedule.php';
require_once dirname( __DIR__, 2 ) . '/postelio-jobs/src/Search/FilterValidator.php';
require_once dirname( __DIR__ ) . '/src/Searches/SavedSearchRepository.php';
require_once dirname( __DIR__ ) . '/src/Alerts/DeliveryRepository.php';

use Postelio\Alerts\Time\ParisSchedule;
use Postelio\Core\ApiError;
use Postelio\Jobs\Search\FilterValidator;

$tests = 0; $failed = array();
function check( string $l, bool $c ): void { global $tests, $failed; ++$tests; echo ( $c ? '  [ok]   ' : '  [FAIL] ' ) . $l . "\n"; if ( ! $c ) { $failed[] = $l; } }
function u( string $s ): int { return (int) strtotime( $s . ' UTC' ); }

echo "== ParisSchedule : daily 07h30 Europe/Paris ==\n";
// Hiver (CET, UTC+1) : 07h30 local = 06h30 UTC.
check( 'hiver avant 07h30 -> même jour 06:30 UTC', ParisSchedule::next_daily( u( '2026-01-15 06:00:00' ) ) === '2026-01-15 06:30:00' );
check( 'hiver après 07h30 -> lendemain 06:30 UTC', ParisSchedule::next_daily( u( '2026-01-15 06:45:00' ) ) === '2026-01-16 06:30:00' );
// Été (CEST, UTC+2) : 07h30 local = 05h30 UTC. Prouve la prise en compte DST.
check( 'été avant 07h30 -> même jour 05:30 UTC', ParisSchedule::next_daily( u( '2026-07-15 04:00:00' ) ) === '2026-07-15 05:30:00' );
check( 'été après 07h30 -> lendemain 05:30 UTC', ParisSchedule::next_daily( u( '2026-07-15 06:00:00' ) ) === '2026-07-16 05:30:00' );
check( 'DST : cible identique en local, offset UTC différent', ParisSchedule::next_daily( u( '2026-01-15 06:00:00' ) ) !== ParisSchedule::next_daily( u( '2026-07-15 06:00:00' ) ) );

echo "== ParisSchedule : weekly lundi 07h30 ==\n";
// 2026-01-05 est un lundi.
check( 'lundi avant 07h30 -> ce lundi 06:30 UTC', ParisSchedule::next_weekly( u( '2026-01-05 06:00:00' ) ) === '2026-01-05 06:30:00' );
check( 'lundi après 07h30 -> lundi suivant', ParisSchedule::next_weekly( u( '2026-01-05 07:00:00' ) ) === '2026-01-12 06:30:00' );
check( 'mardi -> lundi suivant', ParisSchedule::next_weekly( u( '2026-01-06 10:00:00' ) ) === '2026-01-12 06:30:00' );

echo "== ParisSchedule : next_run dispatch ==\n";
check( 'daily == next_daily', ParisSchedule::next_run( 'daily', u( '2026-03-10 09:00:00' ) ) === ParisSchedule::next_daily( u( '2026-03-10 09:00:00' ) ) );
check( 'weekly == next_weekly', ParisSchedule::next_run( 'weekly', u( '2026-03-10 09:00:00' ) ) === ParisSchedule::next_weekly( u( '2026-03-10 09:00:00' ) ) );
check( 'disabled == null', null === ParisSchedule::next_run( 'disabled', time() ) );
check( 'fréquence inconnue == null', null === ParisSchedule::next_run( 'bogus', time() ) );
check( 'échéance strictement future', u( ParisSchedule::next_daily( u( '2026-01-15 06:30:00' ) ) ) > u( '2026-01-15 06:30:00' ) );

echo "== FilterValidator : whitelist ==\n";
$ok = FilterValidator::validate( array( 'q' => 'dev', 'ville' => 'Lille', 'bogus' => 'x' ), false );
check( 'permissif garde q/ville', ( $ok['q'] ?? '' ) === 'dev' && ( $ok['ville'] ?? '' ) === 'Lille' );
check( 'permissif ignore clé inconnue', ! array_key_exists( 'bogus', $ok ) );
check( 'source enum valide', ( FilterValidator::validate( array( 'source' => 'partners' ), false )['source'] ?? '' ) === 'partners' );
check( 'source enum invalide écartée', ! array_key_exists( 'source', FilterValidator::validate( array( 'source' => 'weird' ), false ) ) );
check( 'flag alternance=1 -> true', true === ( FilterValidator::validate( array( 'alternance' => '1' ), false )['alternance'] ?? null ) );
check( 'flag stage=0 -> absent', ! array_key_exists( 'stage', FilterValidator::validate( array( 'stage' => '0' ), false ) ) );
check( 'salaire_min numérique', 30000 === ( FilterValidator::validate( array( 'salaire_min' => '30000' ), false )['salaire_min'] ?? null ) );
check( 'salaire_min non numérique écarté', ! array_key_exists( 'salaire_min', FilterValidator::validate( array( 'salaire_min' => 'abc' ), false ) ) );

echo "== FilterValidator : strict ==\n";
$threw = false;
try { FilterValidator::validate( array( 'bogus' => 'x' ), true ); } catch ( ApiError $e ) { $threw = ( 'validation_error' === $e->error_code() ); }
check( 'strict : clé inconnue -> validation_error', $threw );
$threw2 = false;
try { FilterValidator::validate( array( 'published_after' => '2026-01-01' ), true ); } catch ( ApiError $e ) { $threw2 = true; }
check( 'strict : published_after (interne) rejeté', $threw2 );
$threw3 = false;
try { $r = FilterValidator::validate( array( 'q' => 'dev', 'ville' => 'Lyon' ), true ); } catch ( ApiError $e ) { $threw3 = true; }
check( 'strict : clés valides acceptées', ! $threw3 );

echo "== FilterValidator : empreinte (dédup §14) ==\n";
$a = FilterValidator::validate( array( 'q' => 'dev', 'ville' => 'Lyon', 'alternance' => '1' ), false );
$b = FilterValidator::validate( array( 'alternance' => '1', 'ville' => 'Lyon', 'q' => 'dev' ), false );
check( 'empreinte insensible à l\'ordre', FilterValidator::fingerprint( $a ) === FilterValidator::fingerprint( $b ) );
check( 'empreinte diffère si filtres diffèrent', FilterValidator::fingerprint( $a ) !== FilterValidator::fingerprint( FilterValidator::validate( array( 'q' => 'dev', 'ville' => 'Paris' ), false ) ) );
check( 'empreinte vide stable', FilterValidator::fingerprint( array() ) === FilterValidator::fingerprint( array() ) );

echo "== Constantes ==\n";
check( '3 fréquences (disabled/daily/weekly)', \Postelio\Alerts\Searches\SavedSearchRepository::frequencies() === array( 'disabled', 'daily', 'weekly' ) );
check( 'statuts delivery', \Postelio\Alerts\Alerts\DeliveryRepository::STATUS_RESERVED === 'reserved' && \Postelio\Alerts\Alerts\DeliveryRepository::STATUS_SENT === 'sent' && \Postelio\Alerts\Alerts\DeliveryRepository::STATUS_SKIPPED === 'skipped' );

echo "\n";
if ( empty( $failed ) ) {
	echo "TOUS LES TESTS PASSENT ({$tests}).\n";
	exit( 0 );
}
echo count( $failed ) . " ÉCHEC(S) sur {$tests} :\n";
foreach ( $failed as $f ) { echo "  - {$f}\n"; }
exit( 1 );
