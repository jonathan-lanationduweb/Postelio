<?php
/**
 * Tests unitaires postelio-companies SANS dépendance (ni PHPUnit ni WordPress).
 *
 *   php plugins/postelio-companies/tests/run-unit.php
 *
 * Couvre : Siren (Luhn/format), CompanyService::completion, CompanyPresenter
 * (redaction public/owner/admin, non-exposition des ID internes, verrou légal).
 *
 * @package Postelio\Companies\Tests
 */

declare( strict_types=1 );

define( 'POSTELIO_CORE_TESTING', true );

$src = dirname( __DIR__ ) . '/src/';
require_once $src . 'Verification/Siren.php';
require_once $src . 'Companies/CompanyService.php';
require_once $src . 'Companies/CompanyPresenter.php';

use Postelio\Companies\Companies\CompanyPresenter;
use Postelio\Companies\Companies\CompanyService;
use Postelio\Companies\Verification\Siren;

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

echo "== Siren / Siret ==\n";
// SIREN valides connus (clé de Luhn correcte).
check( 'SIREN 552100554 valide (clé Luhn OK)', Siren::is_valid_siren( '552100554' ) );
check( 'SIREN avec espaces normalisé', Siren::is_valid_siren( '552 100 554' ) );
check( 'SIREN 123456789 invalide', ! Siren::is_valid_siren( '123456789' ) );
check( 'SIREN trop court invalide', ! Siren::is_valid_siren( '55210055' ) );
check( 'SIRET 55210055400013 valide', Siren::is_valid_siret( '552 100 554 00013' ) );
check( 'SIRET 12345678900000 invalide', ! Siren::is_valid_siret( '12345678900000' ) );
check( 'siret_matches_siren OK', Siren::siret_matches_siren( '55210055400013', '552100554' ) );
check( 'siret_matches_siren KO', ! Siren::siret_matches_siren( '55210055400013', '999888777' ) );
check( 'normalize retire non-chiffres', '552100554' === Siren::normalize( 'FR 552-100-554' ) );

echo "== CompanyService::completion ==\n";
$empty = CompanyService::completion( array( 'description' => '', 'editorial' => array(), 'legal_declared' => array() ) );
check( 'profil vide => 0%', 0 === $empty['pct'] );
check( 'profil vide => manque legal', in_array( 'legal', $empty['missing'], true ) );
$full = CompanyService::completion( array(
	'description'    => str_repeat( 'a', 60 ),
	'editorial'      => array(
		'logo_id'   => 12,
		'avantages' => array( 'a', 'b', 'c' ),
		'valeurs'   => array( 'x', 'y' ),
		'email'     => 'rh@ex.fr',
	),
	'legal_declared' => array( 'siren' => '552100554', 'raison_sociale' => 'Danone' ),
) );
check( 'profil complet => 100%', 100 === $full['pct'] );
check( 'profil complet => rien à compléter', array() === $full['missing'] );

echo "== CompanyPresenter (audiences + D2) ==\n";
$base = array(
	'id'             => 42,
	'uuid'           => '11111111-1111-4111-8111-111111111111',
	'author_id'      => 7,
	'nom'            => 'Fiduciaire Bellecour',
	'description'    => 'Cabinet comptable',
	'editorial'      => array( 'ville' => 'Lyon', 'email' => 'rh@ex.fr', 'has_photo' => true, 'logo_id' => 5, 'logo_url' => 'http://x/y.png' ),
	'legal_declared' => array( 'siren' => '552100554', 'raison_sociale' => 'FB SARL', 'tva' => 'FR..' ),
	'legal_verified' => array(),
	'verification'   => array( 'status' => 'unverified' ),
);

$pub = CompanyPresenter::public_view( $base );
check( 'public expose uuid', $pub['uuid'] === $base['uuid'] );
check( 'public N\'expose PAS id interne', ! isset( $pub['id'] ) );
check( 'public N\'expose PAS author_id', ! isset( $pub['author_id'] ) );
check( 'public non vérifié => legal vide', array() === $pub['legal'] );
check( 'public verified=false', false === $pub['verified'] );

$verified = $base;
$verified['verification'] = array( 'status' => 'verified', 'motif' => null, 'provider' => 'manual' );
$verified['legal_verified'] = array( 'raison_sociale' => 'FB SARL', 'forme_juridique' => 'SARL', 'siren' => '552100554', 'ville_siege' => 'Lyon', 'naf_ape' => '6920Z' );
$pubv = CompanyPresenter::public_view( $verified );
check( 'public vérifié => legal public present', 'FB SARL' === ( $pubv['legal']['raison_sociale'] ?? null ) );
check( 'public vérifié => PAS de TVA exposée', ! isset( $pubv['legal']['tva'] ) );
check( 'public verified=true', true === $pubv['verified'] );

$owner = CompanyPresenter::owner_view( $verified );
check( 'owner expose legal_declared', isset( $owner['legal_declared'] ) );
check( 'owner legal_locked=true si vérifié', true === $owner['verification']['legal_locked'] );
check( 'owner expose completion', isset( $owner['completion']['pct'] ) );
check( 'owner N\'expose PAS id interne', ! isset( $owner['id'] ) );

$rejected = $base;
$rejected['verification'] = array( 'status' => 'rejected', 'motif' => 'SIREN introuvable', 'reviewer_id' => 9 );
$ownerR = CompanyPresenter::owner_view( $rejected );
check( 'owner voit le motif si rejet', 'SIREN introuvable' === ( $ownerR['verification']['motif'] ?? null ) );
$ownerU = CompanyPresenter::owner_view( $base ); // unverified
check( 'owner NE voit PAS de motif hors rejet', ! isset( $ownerU['verification']['motif'] ) );

$admin = CompanyPresenter::admin_view( $rejected );
check( 'admin voit reviewer_id', 9 === ( $admin['verification']['reviewer_id'] ?? null ) );
check( 'admin voit le motif complet', 'SIREN introuvable' === ( $admin['verification']['motif'] ?? null ) );

echo "\n";
if ( empty( $failed ) ) {
	echo "RÉSULTAT : {$tests} assertions, 0 échec. OK\n";
	exit( 0 );
}
echo 'RÉSULTAT : ' . count( $failed ) . " échec(s) sur {$tests} assertions.\n";
exit( 1 );
