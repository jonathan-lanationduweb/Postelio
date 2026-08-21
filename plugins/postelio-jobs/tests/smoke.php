<?php
/**
 * Smoke test postelio-jobs sur WordPress vivant :
 *
 *   wp eval-file plugins/postelio-jobs/tests/smoke.php --path=wordpress
 *
 * Couvre : CPT/registry, sécurité, brouillon, protection entreprise non vérifiée
 * (D1), publication, cycle de vie (fill/archive/duplicate/suspend), transitions
 * interdites, expiration cron, visibilité publique/non-exposition, UUID, events/audit.
 * Nettoie tout ce qui est créé.
 *
 * @package Postelio\Jobs\Tests
 */

use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Companies\Verification\Siren;
use Postelio\Core\Plugin as Core;
use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Jobs\Lifecycle\Expiration;
use Postelio\Users\Profiles\CandidateProfileRepository;
use Postelio\Users\Profiles\RecruiterProfileRepository;
use Postelio\Users\Users\AccountService;

if ( ! defined( 'ABSPATH' ) ) { echo "WP-CLI requis.\n"; exit( 1 ); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$fail = array(); $pass = 0;
$t = static function ( string $l, bool $c ) use ( &$fail, &$pass ): void {
	if ( $c ) { ++$pass; echo "  [ok]   {$l}\n"; } else { $fail[] = $l; echo "  [FAIL] {$l}\n"; }
};
$req = static function ( string $m, string $route, ?array $body = null, int $user = 0 ): array {
	wp_set_current_user( $user );
	$r = new WP_REST_Request( $m, $route );
	if ( null !== $body ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
	$resp = rest_do_request( $r );
	return array( 'status' => $resp->get_status(), 'data' => $resp->get_data() );
};
$accounts = new AccountService( new CandidateProfileRepository(), new RecruiterProfileRepository() );
$mk = static function ( string $role ) use ( $accounts ): int {
	return $accounts->register( array( 'email' => 'smoke.jobs.' . $role . '.' . wp_generate_password( 6, false ) . '@postelio.test', 'password' => 'motdepasse123', 'role' => $role ) );
};

global $wpdb;
$repo = new JobRepository();
$audit = $wpdb->prefix . 'postelio_audit_log';
$users = array(); $jobs_created = array(); $company_id = 0;

echo "== Activation / registry / CPT ==\n";
$t( 'plugin jobs actif', is_plugin_active( 'postelio-jobs/postelio-jobs.php' ) );
$t( 'CPT postelio_job enregistré', post_type_exists( 'postelio_job' ) );
$t( 'module jobs dans le registry', Core::instance()->registry()->has( 'jobs' ) );

echo "== Comptes + entreprise (non vérifiée) ==\n";
$recA = $mk( 'recruiter' ); $recB = $mk( 'recruiter' ); $cand = $mk( 'candidate' );
$users = array( $recA, $recB, $cand );
$admin = (int) ( get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) )[0] ?? 1 );
$siren = '100000000'; while ( ! Siren::is_valid_siren( $siren ) ) { $siren = str_pad( (string) ( ( (int) $siren ) + 1 ), 9, '0', STR_PAD_LEFT ); }
$r = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'Jobs Test SARL', 'legal' => array( 'siren' => $siren, 'raison_sociale' => 'Jobs Test SARL' ) ), $recA );
$cuuid = (string) ( $r['data']['data']['uuid'] ?? '' );
$company_id = CompanyDirectory::company_of_user( $recA );
$t( 'entreprise créée (non vérifiée)', $company_id > 0 && 'unverified' === ( $r['data']['data']['verification']['status'] ?? '' ) );

echo "== Sécurité création offre ==\n";
$t( 'anon POST /jobs => 401', 401 === $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'X' ), 0 )['status'] );
$t( 'candidat POST /jobs => 403', 403 === $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'X' ), $cand )['status'] );

echo "== Brouillon (autorisé sans entreprise vérifiée — D1) ==\n";
$payload = array( 'titre' => 'Développeur PHP', 'description' => 'Poste back-end.', 'ville' => 'Lyon', 'contrat' => 'CDI', 'categorie' => 'informatique', 'salaire_annuel' => 42000, 'missions' => array( 'Développer', 'Tester' ), 'email_reception' => 'rh@jobs.test', 'questions_preselection' => array( 'Disponibilité ?' ) );
$r = $req( 'POST', '/postelio/v1/jobs', $payload, $recA );
$t( 'création brouillon => 201', 201 === $r['status'] );
$juuid = (string) ( $r['data']['data']['uuid'] ?? '' );
$t( 'statut initial draft', 'draft' === ( $r['data']['data']['status'] ?? '' ) );
$t( 'réponse expose uuid, pas id interne', preg_match( '/^[0-9a-f-]{36}$/i', $juuid ) && ! isset( $r['data']['data']['id'] ) );
$jid = (int) $repo->get_by_uuid( $juuid )['id']; $jobs_created[] = $jid;

echo "== Brouillon NON public ==\n";
$t( 'GET /jobs/{uuid} brouillon (public) => 404', 404 === $req( 'GET', '/postelio/v1/jobs/' . $juuid, null, 0 )['status'] );

echo "== Protection publication : entreprise non vérifiée (D1) ==\n";
$r = $req( 'POST', '/postelio/v1/jobs/' . $juuid . '/publish', null, $recA );
$t( 'publish sans entreprise vérifiée => 403', 403 === $r['status'] );

echo "== Vérification entreprise (admin) puis publication ==\n";
$req( 'POST', '/postelio/v1/companies/me/verification', null, $recA );
$req( 'POST', '/postelio/v1/companies/' . $cuuid . '/verification/decision', array( 'decision' => 'verified' ), $admin );
$t( 'entreprise vérifiée -> can_publish_jobs=true', \Postelio\Companies\Api\CompanyVerification::can_publish_jobs( $company_id ) );
$r = $req( 'POST', '/postelio/v1/jobs/' . $juuid . '/publish', null, $recA );
$t( 'publish => 200 published', 200 === $r['status'] && 'published' === ( $r['data']['data']['status'] ?? '' ) );
$t( 'date_expiration renseignée', ! empty( $r['data']['data']['date_expiration'] ) );

echo "== Visibilité publique + non-exposition ==\n";
$r = $req( 'GET', '/postelio/v1/jobs/' . $juuid, null, 0 );
$pub = $r['data']['data'] ?? array(); $flat = wp_json_encode( $pub );
$t( 'GET public offre publiée => 200', 200 === $r['status'] );
$t( 'public: company résumé présent', ! empty( $pub['company']['uuid'] ) );
$t( 'public: PAS email_reception', false === strpos( $flat, 'email_reception' ) );
$t( 'public: PAS questions_preselection', false === strpos( $flat, 'questions_preselection' ) );
$t( 'public: PAS d\'id interne', ! isset( $pub['id'] ) && ! isset( $pub['author_id'] ) );
$t( 'public: PAS de status brut', ! isset( $pub['status'] ) );

echo "== Liste publique + /jobs/me ==\n";
$t( 'offre présente dans /jobs (public)', in_array( $juuid, array_map( fn( $x ) => $x['uuid'], (array) $req( 'GET', '/postelio/v1/jobs', null, 0 )['data']['data'] ), true ) );
$t( '/jobs/me (recruteur) liste ses offres', count( (array) $req( 'GET', '/postelio/v1/jobs/me', null, $recA )['data']['data'] ) >= 1 );

echo "== Édition + ownership ==\n";
$t( 'PUT /jobs/{uuid} (owner) => 200', 200 === $req( 'PUT', '/postelio/v1/jobs/' . $juuid, array( 'titre' => 'Dev PHP (senior)' ), $recA )['status'] );
$t( 'recruteur non membre édite => 403', 403 === $req( 'PUT', '/postelio/v1/jobs/' . $juuid, array( 'titre' => 'pirate' ), $recB )['status'] );
$t( 'uuid immuable après édition', $juuid === (string) $repo->get( $jid )['uuid'] );

echo "== Duplication ==\n";
$r = $req( 'POST', '/postelio/v1/jobs/' . $juuid . '/duplicate', null, $recA );
$dupUuid = (string) ( $r['data']['data']['uuid'] ?? '' );
$t( 'duplicate => 201 nouveau brouillon', 201 === $r['status'] && 'draft' === ( $r['data']['data']['status'] ?? '' ) && $dupUuid !== $juuid );
$jobs_created[] = (int) $repo->get_by_uuid( $dupUuid )['id'];

echo "== Cycle de vie : fill puis archive ==\n";
$t( 'fill => 200 filled', 'filled' === ( $req( 'POST', '/postelio/v1/jobs/' . $juuid . '/fill', null, $recA )['data']['data']['status'] ?? '' ) );
$t( 'archive => 200 archived', 'archived' === ( $req( 'POST', '/postelio/v1/jobs/' . $juuid . '/archive', null, $recA )['data']['data']['status'] ?? '' ) );

echo "== Transitions interdites ==\n";
$t( 'publish depuis archived => 409 invalid_transition', 409 === ( $r2 = $req( 'POST', '/postelio/v1/jobs/' . $juuid . '/publish', null, $recA ) )['status'] && 'invalid_transition' === ( $r2['data']['error']['code'] ?? '' ) );

echo "== Expiration (cron) ==\n";
// deux offres publiées
$mkpub = function () use ( $req, $recA, $repo, &$jobs_created ) {
	$u = (string) ( $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'Poste ' . wp_generate_password( 4, false ), 'ville' => 'Lyon' ), $recA )['data']['data']['uuid'] ?? '' );
	$req( 'POST', '/postelio/v1/jobs/' . $u . '/publish', null, $recA );
	$id = (int) $repo->get_by_uuid( $u )['id']; $jobs_created[] = $id; return array( $u, $id );
};
[ $u2, $id2 ] = $mkpub(); // sera expiré
[ $u3, $id3 ] = $mkpub(); // sera bientôt-expiré
update_post_meta( $id2, JobRepository::META_DATE_EXP, gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
update_post_meta( $id3, JobRepository::META_DATE_EXP, gmdate( 'Y-m-d', strtotime( '+3 days' ) ) );
$counts = ( new Expiration( $repo ) )->run();
$t( 'offre échue => expired', 'expired' === $repo->get( $id2 )['status'] );
$t( 'offre proche échéance => expiring', 'expiring' === $repo->get( $id3 )['status'] );
$t( 'offre expirée masquée du public => 404', 404 === $req( 'GET', '/postelio/v1/jobs/' . $u2, null, 0 )['status'] );

echo "== Admin suspend ==\n";
$t( 'admin suspend (expiring->suspended) => 200', 'suspended' === ( $req( 'POST', '/postelio/v1/jobs/' . $u3 . '/status', array( 'decision' => 'suspended' ), $admin )['data']['data']['status'] ?? '' ) );
$t( 'candidat ne peut pas admin-status => 403', 403 === $req( 'POST', '/postelio/v1/jobs/' . $u3 . '/status', array( 'decision' => 'published' ), $cand )['status'] );

echo "== Événements / audit ==\n";
foreach ( array( 'job.created', 'job.published', 'job.filled', 'job.archived', 'job.expired', 'job.expiring', 'job.suspended' ) as $ev ) {
	$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE action = %s", $ev ) );
	$t( "audit contient {$ev}", $n >= 1 );
}

echo "== Nettoyage ==\n";
foreach ( array_unique( $jobs_created ) as $jid2 ) { wp_delete_post( $jid2, true ); }
if ( $company_id ) { ( new \Postelio\Companies\Members\MembershipRepository() )->remove_all_for_company( $company_id ); wp_delete_post( $company_id, true ); }
foreach ( $users as $u ) { ( new CandidateProfileRepository() )->delete_for( $u ); ( new RecruiterProfileRepository() )->delete_for( $u ); wp_delete_user( $u ); }
$wpdb->query( "DELETE FROM {$audit} WHERE resource_type IN ('job','company') OR action LIKE 'user.%' OR action LIKE 'plugin.%'" );
echo "  offres + entreprise + comptes supprimés\n";

echo "\n";
if ( empty( $fail ) ) { WP_CLI::success( "Smoke jobs OK : {$pass} vérifications passées." ); }
else { WP_CLI::error( count( $fail ) . ' échec(s) : ' . implode( ' | ', $fail ) ); }
