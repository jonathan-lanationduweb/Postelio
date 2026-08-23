<?php
/**
 * Tests unitaires postelio-job-sources (logique PURE, sans WordPress) :
 *   php plugins/postelio-job-sources/tests/run-unit.php
 *
 * Couvre UrlGuard (SSRF/redirect), HtmlSanitizer (XSS), NormalizedExternalJob (hash) et
 * le MAPPING réel France Travail via FranceTravailProvider::normalize() sur fixtures.
 *
 * @package Postelio\JobSources\Tests
 */

define( 'POSTELIO_JOBSOURCES_TESTING', true );

// --- Shims WordPress minimaux (dépendance-free) ---
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v = null ) { return $v; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { return true; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }

$base = dirname( __DIR__ ) . '/src/Sources/';
require_once $base . 'UrlGuard.php';
require_once $base . 'HtmlSanitizer.php';
require_once $base . 'NormalizedExternalJob.php';
require_once $base . 'SyncQuery.php';
require_once $base . 'PageResult.php';
require_once $base . 'RateLimiter.php';
require_once $base . 'JobSourceProvider.php';
require_once $base . 'FranceTravail/FranceTravailProvider.php';

use Postelio\JobSources\Sources\FranceTravail\FranceTravailProvider;
use Postelio\JobSources\Sources\HtmlSanitizer;
use Postelio\JobSources\Sources\NormalizedExternalJob;
use Postelio\JobSources\Sources\UrlGuard;

$tests = 0; $failed = array();
$check = static function ( string $label, bool $cond ) use ( &$tests, &$failed ): void {
	++$tests;
	echo ( $cond ? '  [ok]   ' : '  [FAIL] ' ) . $label . "\n";
	if ( ! $cond ) { $failed[] = $label; }
};

echo "== UrlGuard : appels serveur (SSRF) ==\n";
$check( 'api host FT autorisé', UrlGuard::api_host_allowed( 'https://api.francetravail.io/x' ) );
$check( 'api host token autorisé', UrlGuard::api_host_allowed( 'https://entreprise.francetravail.fr/y' ) );
$check( 'api host tiers refusé', ! UrlGuard::api_host_allowed( 'https://evil.example.com/x' ) );
$check( 'api host http refusé', ! UrlGuard::api_host_allowed( 'http://api.francetravail.io/x' ) );

echo "== UrlGuard : URL de redirection candidat ==\n";
$check( 'https partenaire ok', UrlGuard::safe_redirect_url( 'https://www.partenaire-emploi.fr/offre/123' ) );
$check( 'http refusé', ! UrlGuard::safe_redirect_url( 'http://x.fr/a' ) );
$check( 'javascript refusé', ! UrlGuard::safe_redirect_url( 'javascript:alert(1)' ) );
$check( 'data refusé', ! UrlGuard::safe_redirect_url( 'data:text/html,x' ) );
$check( 'file refusé', ! UrlGuard::safe_redirect_url( 'file:///etc/passwd' ) );
$check( 'localhost refusé', ! UrlGuard::safe_redirect_url( 'https://localhost/x' ) );
$check( 'IP privée refusée', ! UrlGuard::safe_redirect_url( 'https://192.168.1.10/x' ) );
$check( 'vide refusé', ! UrlGuard::safe_redirect_url( '' ) );

echo "== HtmlSanitizer ==\n";
$c = HtmlSanitizer::clean( '<p>Bonjour <strong>équipe</strong></p><script>alert(1)</script>' );
$check( 'script retiré', false === strpos( (string) $c, '<script' ) && false === strpos( (string) $c, 'alert' ) );
$check( 'balises de forme conservées', false !== strpos( (string) $c, '<strong>' ) );
$c2 = HtmlSanitizer::clean( '<img src=x onerror=alert(1)><iframe src="//evil"></iframe>Texte' );
$check( 'onerror/iframe neutralisés', false === strpos( (string) $c2, 'onerror' ) && false === strpos( (string) $c2, '<iframe' ) );
$check( 'texte conservé', false !== strpos( (string) $c2, 'Texte' ) );
$check( 'vide => null', null === HtmlSanitizer::clean( '' ) );

echo "== NormalizedExternalJob : content_hash ==\n";
$n1 = new NormalizedExternalJob(); $n1->source_key = 'france_travail'; $n1->external_id = 'A1'; $n1->title = 'Dev';
$n2 = new NormalizedExternalJob(); $n2->source_key = 'france_travail'; $n2->external_id = 'A1'; $n2->title = 'Dev';
$check( 'hash stable pour contenu identique', $n1->content_hash() === $n2->content_hash() );
$n2->title = 'Dev senior';
$check( 'hash change si contenu change', $n1->content_hash() !== $n2->content_hash() );

echo "== Mapping France Travail (normalize) ==\n";
$ft = new FranceTravailProvider();

$complete = array(
	'id' => '048KLTP', 'intitule' => 'Développeur PHP', 'description' => '<p>Super poste</p><script>x</script>',
	'entreprise' => array( 'nom' => 'ACME', 'logo' => 'https://logos.francetravail.fr/a.png' ),
	'lieuTravail' => array( 'libelle' => 'Lyon', 'commune' => '69123', 'codePostal' => '69001', 'latitude' => 45.76, 'longitude' => 4.83 ),
	'typeContrat' => 'CDI', 'typeContratLibelle' => 'Contrat à durée indéterminée', 'natureContrat' => 'Contrat travail',
	'romeCode' => 'M1805', 'romeLibelle' => 'Études et développement informatique', 'codeNAF' => '62.01Z', 'secteurActiviteLibelle' => 'Programmation informatique',
	'experienceExige' => 'E', 'experienceLibelle' => '2 ans', 'salaire' => array( 'libelle' => '40k€/an' ),
	'dureeTravailLibelleConverti' => 'Temps plein', 'alternance' => false,
	'dateCreation' => '2026-08-01T10:00:00.000Z', 'dateActualisation' => '2026-08-10T12:00:00.000Z',
	'origineOffre' => array( 'origine' => '1', 'urlOrigine' => 'https://candidat.francetravail.fr/offres/recherche/detail/048KLTP' ),
	'contact' => array( 'urlPostulation' => 'https://www.partenaire.fr/apply/048KLTP' ),
	'competences' => array( array( 'code' => 'x', 'libelle' => 'PHP' ) ),
	'champ_inconnu_du_futur' => 'ignore-moi',
);
$job = $ft->normalize( $complete );
$check( 'normalize renvoie un DTO', $job instanceof NormalizedExternalJob );
$check( 'external_id mappé', '048KLTP' === $job->external_id );
$check( 'title mappé', 'Développeur PHP' === $job->title );
$check( 'description assainie (pas de script)', null !== $job->description && false === strpos( (string) $job->description, '<script' ) );
$check( 'company mappée', 'ACME' === $job->company_name );
$check( 'lieu (commune INSEE + lat)', '69123' === $job->commune_insee && 45.76 === $job->latitude );
$check( 'contrat normalisé CDI', 'CDI' === $job->contract_normalized && 'CDI' === $job->contract_code_source );
$check( 'ROME mappé', 'M1805' === $job->rome_code );
$check( 'salaire mappé', '40k€/an' === $job->salary_text );
$check( 'dates → UTC', '2026-08-01 10:00:00' === $job->source_published_at && '2026-08-10 12:00:00' === $job->source_updated_at );
$check( 'apply url partenaire conservée', 'https://www.partenaire.fr/apply/048KLTP' === $job->external_apply_url );
$check( 'source_metadata garde competences (affichage conforme)', isset( $job->source_metadata['competences'] ) );
$check( 'champ inconnu NON stocké', ! isset( $job->source_metadata['champ_inconnu_du_futur'] ) );
$check( 'application_mode = external_redirect', 'external_redirect' === $job->application_mode );

echo "== Cas partiels ==\n";
$confidential = $ft->normalize( array( 'id' => 'B2', 'intitule' => 'Agent', 'entreprise' => array(), 'lieuTravail' => array( 'libelle' => 'Paris' ), 'salaire' => array() ) );
$check( 'entreprise absente => company_name null', null === $confidential->company_name );
$check( 'salaire absent => salary_text null', null === $confidential->salary_text );
$check( 'alternance true détectée', ( $ft->normalize( array( 'id' => 'C3', 'intitule' => 'Appr', 'alternance' => true ) ) )->alternance === true );
$check( 'offre sans id/titre => null', null === $ft->normalize( array( 'intitule' => 'x' ) ) && null === $ft->normalize( array( 'id' => 'z' ) ) );
$evil = $ft->normalize( array( 'id' => 'D4', 'intitule' => 'X', 'contact' => array( 'urlPostulation' => 'javascript:alert(1)' ), 'origineOffre' => array( 'urlOrigine' => 'https://ok.fr/o' ) ) );
$check( 'apply url javascript rejetée → repli urlOrigine', 'https://ok.fr/o' === $evil->external_apply_url );

echo "\n";
echo 'RÉSULTAT : ' . $tests . ' assertions, ' . count( $failed ) . " échec(s). " . ( empty( $failed ) ? "OK\n" : "ÉCHEC\n" );
exit( empty( $failed ) ? 0 : 1 );
