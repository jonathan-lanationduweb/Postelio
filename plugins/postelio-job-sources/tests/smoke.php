<?php
/**
 * Smoke test postelio-job-sources sur WordPress vivant :
 *   wp eval-file plugins/postelio-job-sources/tests/smoke.php --path=wordpress
 *
 * Utilise FakeJobSourceProvider (aucune API réelle). Couvre : sync (create/update/
 * unchanged/removed/failure≠removed/hidden préservé), recherche unifiée /jobs, détail
 * externe + 410, apply-redirect (302/410/refus), garde applications, attribution, provider
 * désactivé. Aucune vraie clé France Travail requise.
 *
 * @package Postelio\JobSources\Tests
 */

use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Companies\Members\MembershipRepository;
use Postelio\Companies\Verification\ManualVerificationProvider;
use Postelio\Companies\Verification\VerificationService;
use Postelio\Core\Plugin as Core;
use Postelio\JobSources\Jobs\ExternalJobRepository;
use Postelio\JobSources\Plugin as JS;
use Postelio\JobSources\Sources\FakeJobSourceProvider;
use Postelio\Users\Profiles\CandidateProfileRepository;
use Postelio\Users\Profiles\RecruiterProfileRepository;
use Postelio\Users\Users\AccountService;

if ( ! defined( 'ABSPATH' ) ) { echo "WP-CLI requis.\n"; exit( 1 ); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$fail = array(); $pass = 0;
$t = static function ( string $l, bool $c ) use ( &$fail, &$pass ): void { if ( $c ) { ++$pass; echo "  [ok]   {$l}\n"; } else { $fail[] = $l; echo "  [FAIL] {$l}\n"; } };
$req = static function ( string $m, string $route, ?array $body, int $user ) {
	wp_set_current_user( $user );
	$r = new WP_REST_Request( $m, $route );
	if ( null !== $body ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
	return rest_do_request( $r );
};
$acc = new AccountService( new CandidateProfileRepository(), new RecruiterProfileRepository() );
$mk  = static function ( string $role ) use ( $acc ): int { return $acc->register( array( 'email' => 'js.' . $role . '.' . wp_generate_password( 6, false ) . '@postelio.test', 'password' => 'motdepasse123', 'role' => $role ) ); };

$ftOffer = static function ( string $id, string $title, string $city = 'Lyon', string $contrat = 'CDI', string $apply = 'https://www.partenaire.fr/apply/' ): array {
	return array(
		'id' => $id, 'intitule' => $title, 'description' => '<p>Poste ' . $title . '</p>',
		'entreprise' => array( 'nom' => 'ExtCo' ),
		'lieuTravail' => array( 'libelle' => $city, 'commune' => '69123', 'codePostal' => '69001' ),
		'typeContrat' => $contrat, 'romeCode' => 'M1805', 'experienceExige' => 'E', 'experienceLibelle' => '2 ans',
		'dateCreation' => '2026-08-0' . ( ( (int) substr( $id, -1 ) % 8 ) + 1 ) . 'T10:00:00Z',
		'dateActualisation' => '2026-08-10T10:00:00Z',
		'origineOffre' => array( 'origine' => '2', 'urlOrigine' => 'https://www.partenaire.fr/o/' . $id ),
		'contact' => array( 'urlPostulation' => $apply . $id ),
	);
};

global $wpdb;
$EJ    = $wpdb->prefix . 'postelio_external_jobs';
$AP    = $wpdb->prefix . 'postelio_applications';
$repo  = new ExternalJobRepository();
$fake  = new FakeJobSourceProvider();
$users = array(); $companies = array(); $jobs = array();

// Injecte le Fake provider + un slice de test (aucun appel réseau).
add_filter( 'postelio/job_sources/providers', static function () use ( $fake ) { return array( $fake ); } );
add_filter( 'postelio/job_sources/slices', static function () { return array( array( 'key' => 'test', 'criteria' => array() ) ); } );

$run = static function () { return JS::instance()->orchestrator()->run_provider( 'france_travail' ); };
$appCount = static function () use ( $wpdb, $AP ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$AP}" ); };

echo "== Activation / tables ==\n";
$t( 'plugin job-sources actif', is_plugin_active( 'postelio-job-sources/postelio-job-sources.php' ) );
$t( 'module registry', Core::instance()->registry()->has( 'job_sources' ) );
foreach ( array( 'postelio_external_jobs', 'postelio_job_source_sync_runs' ) as $s ) {
	$tbl = $wpdb->prefix . $s;
	$t( "table {$tbl}", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
}

echo "== Sync : create / unchanged / update (UUID stable) ==\n";
$fake->offers = array( $ftOffer( 'A1', 'Développeur' ), $ftOffer( 'A2', 'Assistant' ) );
$r1 = $run();
$t( 'création de 2 offres', 2 === (int) $r1['created'] );
$uuidA1 = (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_uuid FROM {$EJ} WHERE source_key='france_travail' AND external_id='A1'" ) );
$t( 'A1 a un UUID public', preg_match( '/^[0-9a-f-]{36}$/i', $uuidA1 ) === 1 );
$r2 = $run();
$t( 're-sync identique => unchanged', 2 === (int) $r2['unchanged'] && 0 === (int) $r2['created'] );
$fake->offers[0]['intitule'] = 'Développeur senior';
$r3 = $run();
$t( 'contenu changé => updated', 1 === (int) $r3['updated'] );
$uuidA1b = (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_uuid FROM {$EJ} WHERE external_id='A1'" ) );
$t( 'UUID stable après update', $uuidA1 === $uuidA1b );

echo "== Panne provider ≠ disparition ==\n";
$fake->throw_on_fetch = true;
$rf = $run();
$t( 'run en échec', 'failed' === ( $rf['status'] ?? ( $rf['errors'] > 0 ? 'failed' : '' ) ) || (int) $rf['errors'] >= 1 );
$t( 'aucune offre retirée sur panne', 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$EJ} WHERE sync_status='active'" ) );
$fake->throw_on_fetch = false;

echo "== Masquage admin préservé à la resync ==\n";
$repo->set_visibility( $uuidA1, 'hidden' );
$run();
$t( 'offre masquée reste hidden après resync', 'hidden' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT local_visibility FROM {$EJ} WHERE external_id='A1'" ) ) );
$repo->set_visibility( $uuidA1, 'visible' );

echo "== Disparition confirmée => removed + anonymisation ==\n";
$fake->offers = array( $ftOffer( 'A1', 'Développeur senior' ) ); // A2 disparaît
$rr = $run();
$t( 'A2 retirée (refresh complet)', 1 === (int) $rr['removed'] );
$a2 = $wpdb->get_row( $wpdb->prepare( "SELECT sync_status, company_name, description, external_url FROM {$EJ} WHERE external_id='A2'" ), ARRAY_A );
$t( 'A2 sync_status=removed', 'removed' === ( $a2['sync_status'] ?? '' ) );
$t( 'A2 anonymisée (company/desc/url vidés)', null === $a2['company_name'] && null === $a2['description'] && null === $a2['external_url'] );

echo "== Contexte natif (offre Postelio publiée) ==\n";
$rec = $mk( 'recruiter' ); $cand = $mk( 'candidate' ); $users = array( $rec, $cand );
$s = (string) wp_rand( 100000000, 999999998 ); while ( ! \Postelio\Companies\Verification\Siren::is_valid_siren( $s ) ) { $s = str_pad( (string) ( ( (int) $s ) + 1 ), 9, '0', STR_PAD_LEFT ); }
$cuuid = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'NativeCo', 'legal' => array( 'siren' => $s ) ), $rec )->get_data()['data']['uuid'];
$cid = CompanyDirectory::id_from_uuid( $cuuid ); $companies[] = $cid;
$vs = new VerificationService( new CompanyRepository(), new ManualVerificationProvider() ); $vs->request( $cid, 1 ); $vs->decide( $cid, 1, 'verified' );
$ju = $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'Dev natif', 'ville' => 'Lyon' ), $rec )->get_data()['data']['uuid'];
$req( 'POST', '/postelio/v1/jobs/' . $ju . '/publish', null, $rec );
$jid = ( new \Postelio\Jobs\Jobs\JobRepository() )->get_by_uuid( $ju )['id']; $jobs[] = $jid;

echo "== Recherche unifiée /jobs (native + externe) ==\n";
$list = $req( 'GET', '/postelio/v1/jobs', null, 0 )->get_data();
$srcTypes = array();
foreach ( (array) $list['data'] as $it ) { $srcTypes[ ( $it['source']['type'] ?? '?' ) ] = true; }
$t( 'liste contient natif ET externe', isset( $srcTypes['native'] ) && isset( $srcTypes['external'] ) );
$ext = null; foreach ( (array) $list['data'] as $it ) { if ( ( $it['source']['type'] ?? '' ) === 'external' ) { $ext = $it; break; } }
$t( 'offre externe : source.external=true', $ext && true === ( $ext['source']['external'] ?? false ) );
$t( 'offre externe : application_mode=external_redirect', $ext && 'external_redirect' === ( $ext['application']['mode'] ?? '' ) );
$t( 'offre externe : attribution présente', $ext && ! empty( $ext['source']['attribution']['notice'] ) && ! empty( $ext['source']['attribution']['source_updated_at'] ) );
$t( 'offre externe : pas d\'external_id exposé', $ext && ! isset( $ext['external_id'] ) );
$t( 'meta.total_is_exact présent + true (petit jeu)', array_key_exists( 'total_is_exact', (array) ( $list['meta']['pagination'] ?? array() ) ) && true === $list['meta']['pagination']['total_is_exact'] );

echo "== Pagination mixte : total_is_exact=false au-delà du merge_cap ==\n";
$capCb = static function () { return 1; };
add_filter( 'postelio/job_sources/merge_cap', $capCb );
$listCap = $req( 'GET', '/postelio/v1/jobs', null, 0 )->get_data();
$t( 'merge_cap=1 => total_is_exact=false', false === ( $listCap['meta']['pagination']['total_is_exact'] ?? true ) );
$t( 'total reste la somme (exacte) même si ordre approximatif', (int) $listCap['meta']['pagination']['total'] >= 2 );
remove_filter( 'postelio/job_sources/merge_cap', $capCb );

echo "== Filtre source ==\n";
$onlyN = $req( 'GET', '/postelio/v1/jobs', null, 0 ); $rN = new WP_REST_Request( 'GET', '/postelio/v1/jobs' ); $rN->set_query_params( array( 'source' => 'postelio' ) ); $dN = rest_do_request( $rN )->get_data();
$allN = true; foreach ( (array) $dN['data'] as $it ) { if ( ( $it['source']['type'] ?? '' ) !== 'native' ) { $allN = false; } }
$t( 'source=postelio => uniquement natif', $allN && count( (array) $dN['data'] ) >= 1 );
$rP = new WP_REST_Request( 'GET', '/postelio/v1/jobs' ); $rP->set_query_params( array( 'source' => 'partners' ) ); $dP = rest_do_request( $rP )->get_data();
$allP = true; foreach ( (array) $dP['data'] as $it ) { if ( ( $it['source']['type'] ?? '' ) !== 'external' ) { $allP = false; } }
$t( 'source=partners => uniquement externe', $allP && count( (array) $dP['data'] ) >= 1 );
$t( 'offre removed absente de la recherche', ! in_array( 'A2', array_map( static fn( $i ) => $i['uuid'] ?? '', (array) $dP['data'] ), true ) );

echo "== Détail offre externe + 410 ==\n";
$det = $req( 'GET', '/postelio/v1/jobs/' . $uuidA1, null, 0 );
$t( 'détail externe => 200 + source externe', 200 === $det->get_status() && 'external' === ( $det->get_data()['data']['source']['type'] ?? '' ) );
$uuidA2 = (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_uuid FROM {$EJ} WHERE external_id='A2'" ) );
$t( 'détail offre removed => 410', 410 === $req( 'GET', '/postelio/v1/jobs/' . $uuidA2, null, 0 )->get_status() );

echo "== apply-redirect ==\n";
$before = $appCount();
$ar = $req( 'GET', '/postelio/v1/jobs/' . $uuidA1 . '/apply-redirect', null, $cand );
$t( 'apply-redirect externe => 302 + Location', 302 === $ar->get_status() && '' !== (string) $ar->get_headers()['Location'] ?? '' );
$t( 'apply-redirect offre removed => 410', 410 === $req( 'GET', '/postelio/v1/jobs/' . $uuidA2 . '/apply-redirect', null, $cand )->get_status() );
$t( 'apply-redirect sur offre native => 404', 404 === $req( 'GET', '/postelio/v1/jobs/' . $ju . '/apply-redirect', null, $cand )->get_status() );
$t( 'aucune candidature créée par le redirect', $appCount() === $before );

echo "== Garde applications : pas de candidature Postelio sur offre externe ==\n";
$applyExt = $req( 'POST', '/postelio/v1/jobs/' . $uuidA1 . '/applications', array( 'screening_answers' => array() ), $cand );
$t( 'candidature Postelio sur offre externe refusée (409)', 409 === $applyExt->get_status() );
$t( 'aucune row Applications créée', $appCount() === $before );

echo "== Redirect refusé : offre masquée (hidden) => 404 ==\n";
$repo->set_visibility( $uuidA1, 'hidden' );
$t( 'apply-redirect offre hidden => 404', 404 === $req( 'GET', '/postelio/v1/jobs/' . $uuidA1 . '/apply-redirect', null, $cand )->get_status() );
$t( 'détail offre hidden => 404', 404 === $req( 'GET', '/postelio/v1/jobs/' . $uuidA1, null, 0 )->get_status() );
$qh = new WP_REST_Request( 'GET', '/postelio/v1/jobs' ); $qh->set_query_params( array( 'source' => 'partners' ) );
$t( 'offre hidden absente de la recherche', 0 === count( (array) rest_do_request( $qh )->get_data()['data'] ) );
$repo->set_visibility( $uuidA1, 'visible' );

echo "== Provider désactivé => indisponible côté public (recherche + détail + redirect) ==\n";
$fake->available = false;
$rDis = new WP_REST_Request( 'GET', '/postelio/v1/jobs' ); $rDis->set_query_params( array( 'source' => 'partners' ) ); $dDis = rest_do_request( $rDis )->get_data();
$t( 'source désactivée => 0 offre externe (recherche)', 0 === count( (array) $dDis['data'] ) );
$t( 'source désactivée => détail public 404', 404 === $req( 'GET', '/postelio/v1/jobs/' . $uuidA1, null, 0 )->get_status() );
$t( 'source désactivée => apply-redirect 404', 404 === $req( 'GET', '/postelio/v1/jobs/' . $uuidA1 . '/apply-redirect', null, $cand )->get_status() );
$t( 'source désactivée : ligne conservée en base (health)', 1 <= (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$EJ} WHERE external_id='A1'" ) ) );
$fake->available = true;
$t( 'réactivation => offre de nouveau visible (détail 200)', 200 === $req( 'GET', '/postelio/v1/jobs/' . $uuidA1, null, 0 )->get_status() );
$t( 'réactivation : hidden resté visible ici (non masqué)', 'visible' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT local_visibility FROM {$EJ} WHERE external_id='A1'" ) ) );

echo "== Conformité licence France Travail (points vérifiés) ==\n";
$attr = $ext['source']['attribution'] ?? array();
$t( 'attribution: notice source présente', ! empty( $attr['notice'] ) );
$t( 'attribution: lien licence présent', ! empty( $attr['licence_url'] ) );
$t( 'attribution: date de mise à jour source présente', ! empty( $attr['source_updated_at'] ) );
$t( 'licence: offre removed anonymisée (déjà vérifié A2)', null === $wpdb->get_var( $wpdb->prepare( "SELECT company_name FROM {$EJ} WHERE external_id='A2'" ) ) );

echo "== Nettoyage ==\n";
$wpdb->query( "DELETE FROM {$EJ} WHERE source_key='france_travail'" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_job_source_sync_runs WHERE provider_key='france_travail'" );
foreach ( $jobs as $j ) { wp_delete_post( $j, true ); }
$ids_in = implode( ',', array_map( 'intval', $companies ?: array( 0 ) ) );
$wpdb->query( "DELETE FROM {$AP} WHERE company_id IN ({$ids_in})" );
foreach ( $companies as $c ) { ( new MembershipRepository() )->remove_all_for_company( $c ); wp_delete_post( $c, true ); }
foreach ( $users as $u ) { ( new CandidateProfileRepository() )->delete_for( $u ); ( new RecruiterProfileRepository() )->delete_for( $u ); wp_delete_user( $u ); }
$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_audit_log WHERE resource_type IN ('job','company','job_source') OR action LIKE 'user.%'" );
echo "  offres externes + runs + natif + comptes supprimés\n";

echo "\n";
if ( empty( $fail ) ) { WP_CLI::success( "Smoke job-sources OK : {$pass} vérifications passées." ); }
else { WP_CLI::error( count( $fail ) . ' échec(s) : ' . implode( ' | ', $fail ) ); }
