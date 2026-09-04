<?php
/**
 * Tests unitaires postelio-site (schéma PUR, sans WordPress) :
 *   php plugins/postelio-site/tests/run-unit.php
 *
 * Couvre les garanties du Site Builder qui ne dépendent pas de WordPress : indications d'aperçu du
 * Footer (cible + appareil), structure des groupes Footer, identité globale (nom de marque, logo,
 * favicon par défaut = favicon Postelio validé), champs conditionnels (show_if), formats acceptés.
 *
 * @package Postelio\Site\Tests
 */

define( 'POSTELIO_SITE_TESTING', true );

require_once dirname( __DIR__ ) . '/src/Config/SiteSchema.php';

use Postelio\Site\Config\SiteSchema;

$tests = 0; $failed = array();
$check = static function ( string $label, bool $cond ) use ( &$tests, &$failed ): void {
	++$tests;
	echo ( $cond ? '  [ok]   ' : '  [FAIL] ' ) . $label . "\n";
	if ( ! $cond ) { $failed[] = $label; }
};

echo "== Footer : aperçu ==\n";
$footer = SiteSchema::page( 'footer' );
$check( 'footer cible le VRAI footer (preview_target=footer)', 'footer' === ( $footer['preview_target'] ?? '' ) );
$check( 'footer impose l\'aperçu mobile (preview_device=mobile)', 'mobile' === ( $footer['preview_device'] ?? '' ) );
foreach ( array( 'home', 'navigation', 'appearance', 'jobs', 'companies', 'skills', 'advice', 'contact', 'seo' ) as $p ) {
	$def = SiteSchema::page( $p );
	$check( "page {$p} garde Desktop/Tablette/Mobile (aucun preview_device)", ! isset( $def['preview_device'] ) );
}

echo "== Footer : structure de l'éditeur ==\n";
$labels = array_map( static fn( $g ) => $g['label'], $footer['groups'] );
$check( 'groupes = Marque / Colonnes de liens / Réseaux sociaux / Mentions / Réglages', $labels === array( 'Marque', 'Colonnes de liens', 'Réseaux sociaux', 'Mentions / bas de page', 'Réglages' ) );
$all = array();
foreach ( $footer['groups'] as $g ) { $all = array_merge( $all, $g['fields'] ); }
$check( 'chaque champ footer appartient à un groupe', array() === array_diff( array_keys( $footer['fields'] ), $all ) );
$check( 'groupe Marque affiche le rappel d\'identité globale', ! empty( $footer['groups'][0]['identity_hint'] ) );
$check( 'logo override masqué quand le logo global est actif (show_if)', ( $footer['fields']['logo']['show_if'] ?? null ) === array( 'field' => 'use_identity_logo', 'equals' => false ) );
$check( 'brand_text footer = override vide par défaut (repli sur l\'identité globale)', '' === $footer['fields']['brand_text']['default'] );
$check( 'description reste propre au footer', isset( $footer['fields']['description'] ) && '' !== $footer['fields']['description']['default'] );
$check( 'réglages d\'affichage réels (newsletter, réseaux)', true === $footer['fields']['show_newsletter']['default'] && true === $footer['fields']['show_socials']['default'] );

echo "== Identité globale (Apparence) ==\n";
$app = SiteSchema::page( 'appearance' );
$check( 'groupe Identité = nom de marque + logo + logo clair + favicon + image sociale', $app['groups'][0]['fields'] === array( 'brand_name', 'logo', 'logo_light', 'favicon', 'social_image' ) );
$check( 'nom de marque global par défaut = Postelio', 'Postelio' === $app['fields']['brand_name']['default'] );
$check( 'favicon par défaut = favicon Postelio validé (/assets/icons/favicon.svg)', SiteSchema::DEFAULT_FAVICON === $app['fields']['favicon']['default'] && '/assets/icons/favicon.svg' === SiteSchema::DEFAULT_FAVICON );
$check( 'favicon : formats svg/png/ico', array( 'svg', 'png', 'ico' ) === $app['fields']['favicon']['accept'] );
$check( 'favicon : prévisualisation icône réelle', 'icon' === ( $app['fields']['favicon']['preview'] ?? '' ) );
$check( 'logo : formats svg/png/webp/jpg', SiteSchema::LOGO_FORMATS === $app['fields']['logo']['accept'] && in_array( 'svg', SiteSchema::LOGO_FORMATS, true ) && in_array( 'webp', SiteSchema::LOGO_FORMATS, true ) );
$check( 'logo : prévisualisation contain', 'contain' === ( $app['fields']['logo']['preview'] ?? '' ) );
$check( 'navigation : logo override conditionnel et brand_text override vide', isset( SiteSchema::page( 'navigation' )['fields']['logo']['show_if'] ) && '' === SiteSchema::page( 'navigation' )['fields']['brand_text']['default'] );

echo "== Défauts ==\n";
$d = SiteSchema::defaults( 'appearance' );
$check( 'defaults appearance porte brand_name + favicon', 'Postelio' === $d['brand_name'] && SiteSchema::DEFAULT_FAVICON === $d['favicon'] );
$check( 'schéma toujours en version 2 (ajouts rétro-compatibles avec défauts)', 2 === SiteSchema::VERSION );

echo "\nRÉSULTAT : {$tests} assertions, " . count( $failed ) . " échec(s). " . ( empty( $failed ) ? 'OK' : 'KO' ) . "\n";
exit( empty( $failed ) ? 0 : 1 );
