<?php
/**
 * Smoke test postelio-applications sur WordPress vivant :
 *   wp eval-file plugins/postelio-applications/tests/smoke.php --path=wordpress
 *
 * @package Postelio\Applications\Tests
 */

use Postelio\Applications\Applications\ApplicationRepository;
use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Companies\Members\MembershipRepository;
use Postelio\Companies\Verification\ManualVerificationProvider;
use Postelio\Companies\Verification\Siren;
use Postelio\Companies\Verification\VerificationService;
use Postelio\Core\Plugin as Core;
use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Users\Profiles\CandidateProfileRepository;
use Postelio\Users\Profiles\RecruiterProfileRepository;
use Postelio\Users\Users\AccountService;

if ( ! defined( 'ABSPATH' ) ) { echo "WP-CLI requis.\n"; exit( 1 ); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$fail = array(); $pass = 0;
$t = static function ( string $l, bool $c ) use ( &$fail, &$pass ): void { if ( $c ) { ++$pass; echo "  [ok]   {$l}\n"; } else { $fail[] = $l; echo "  [FAIL] {$l}\n"; } };
$req = static function ( string $m, string $route, ?array $body = null, int $user = 0 ): array {
	wp_set_current_user( $user );
	$q = array();
	if ( false !== strpos( $route, '?' ) ) { list( $route, $qs ) = explode( '?', $route, 2 ); parse_str( $qs, $q ); }
	$r = new WP_REST_Request( $m, $route );
	if ( $q ) { $r->set_query_params( $q ); }
	if ( null !== $body ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
	$resp = rest_do_request( $r );
	return array( 'status' => $resp->get_status(), 'data' => $resp->get_data() );
};
$acc = new AccountService( new CandidateProfileRepository(), new RecruiterProfileRepository() );
$mk  = static function ( string $role ) use ( $acc ): int { return $acc->register( array( 'email' => 'sa.' . $role . '.' . wp_generate_password( 6, false ) . '@postelio.test', 'password' => 'motdepasse123', 'role' => $role ) ); };
$siren = static function (): string { $s = (string) wp_rand( 100000000, 999999998 ); while ( ! Siren::is_valid_siren( $s ) ) { $s = str_pad( (string) ( ( (int) $s ) + 1 ), 9, '0', STR_PAD_LEFT ); } return $s; };

global $wpdb;
$repo = new ApplicationRepository();
$jrepo = new JobRepository();
$audit = $wpdb->prefix . 'postelio_audit_log';
$users = array(); $companies = array(); $jobs = array();

// D1 (Lot 09) : capture des payloads d'événements pour vérifier l'enrichissement UUID.
$captured = array();
\Postelio\Core\Plugin::instance()->events()->on( 'application.created', static function ( $payload ) use ( &$captured ) { $captured['created'] = $payload; } );
\Postelio\Core\Plugin::instance()->events()->on( 'application.rejected', static function ( $payload ) use ( &$captured ) { $captured['rejected'] = $payload; } );

$mkCompanyVerified = static function ( int $rec ) use ( $req, $siren, &$companies ): array {
	$c = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'Co ' . wp_generate_password( 4, false ), 'legal' => array( 'siren' => $siren() ) ), $rec );
	$cuuid = $c['data']['data']['uuid']; $cid = CompanyDirectory::id_from_uuid( $cuuid );
	$svc = new VerificationService( new CompanyRepository(), new ManualVerificationProvider() );
	$svc->request( $cid, 1 ); $svc->decide( $cid, 1, 'verified' );
	$companies[] = $cid; return array( $cuuid, $cid );
};
$mkJob = static function ( int $rec, array $questions = array() ) use ( $req, $jrepo, &$jobs ): array {
	$j = $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'Poste ' . wp_generate_password( 4, false ), 'ville' => 'Lyon', 'contrat' => 'CDI', 'questions_preselection' => $questions ), $rec );
	$juuid = $j['data']['data']['uuid'];
	$req( 'POST', '/postelio/v1/jobs/' . $juuid . '/publish', null, $rec );
	$jid = $jrepo->get_by_uuid( $juuid )['id']; $jobs[] = $jid; return array( $juuid, $jid );
};

echo "== Activation / registry / tables ==\n";
$t( 'plugin actif', is_plugin_active( 'postelio-applications/postelio-applications.php' ) );
$t( 'module applications dans le registry', Core::instance()->registry()->has( 'applications' ) );
foreach ( array( 'postelio_applications', 'postelio_application_history', 'postelio_recruiter_notes' ) as $s ) {
	$tbl = $wpdb->prefix . $s;
	$t( "table {$tbl}", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
}
$t( 'schema applications = 1', (string) get_option( 'postelio_applications_schema' ) === '1' );

echo "== Comptes / entreprises / offres ==\n";
$cand = $mk( 'candidate' ); $cand2 = $mk( 'candidate' ); $cand3 = $mk( 'candidate' );
$recA = $mk( 'recruiter' ); $recB = $mk( 'recruiter' );
$users = array( $cand, $cand2, $cand3, $recA, $recB );
$admin = (int) ( get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) )[0] ?? 1 );
list( $cuuidA, $cidA ) = $mkCompanyVerified( $recA );
list( $cuuidB, $cidB ) = $mkCompanyVerified( $recB );
$questions = array(
	array( 'id' => 'permis', 'label' => 'Permis B ?', 'type' => 'oui_non', 'required' => true ),
	array( 'id' => 'exp', 'label' => 'Années exp ?', 'type' => 'nombre', 'required' => false ),
);
list( $juuidA, $jidA ) = $mkJob( $recA, $questions );
list( $juuidB, $jidB ) = $mkJob( $recB, array( array( 'id' => 'dispo', 'label' => 'Dispo ?', 'type' => 'texte', 'required' => true ) ) );

echo "== Sécurité création ==\n";
$t( 'anon apply => 401', 401 === $req( 'POST', '/postelio/v1/jobs/' . $juuidA . '/applications', array(), 0 )['status'] );
$t( 'recruteur (pas candidat) apply => 403', 403 === $req( 'POST', '/postelio/v1/jobs/' . $juuidA . '/applications', array(), $recA )['status'] );
delete_user_meta( $cand3, AccountService::META_EMAIL_VERIFIED ); clean_user_cache( $cand3 );
$t( 'candidat e-mail non vérifié => 403', 403 === $req( 'POST', '/postelio/v1/jobs/' . $juuidA . '/applications', array(), $cand3 )['status'] );
update_user_meta( $cand3, AccountService::META_EMAIL_VERIFIED, current_time( 'mysql', true ) ); clean_user_cache( $cand3 );

echo "== Candidature (brouillon d'offre non candidateable / présélection) ==\n";
$t( 'présélection obligatoire manquante => 422', 422 === $req( 'POST', '/postelio/v1/jobs/' . $juuidA . '/applications', array( 'screening_answers' => array() ), $cand )['status'] );
// NB : le CV est facultatif ici ; l'intégration réelle CV↔candidature est couverte
// par le smoke de postelio-files (contrat FileCvContract).
$r = $req( 'POST', '/postelio/v1/jobs/' . $juuidA . '/applications', array( 'message' => 'Bonjour', 'screening_answers' => array( 'permis' => 'oui', 'exp' => 4 ), 'status' => 'selected' ), $cand );
$t( 'apply => 201', 201 === $r['status'] );
$appU = (string) ( $r['data']['data']['uuid'] ?? '' );
$t( 'statut forcé ignoré (new)', 'new' === ( $r['data']['data']['status'] ?? '' ) );
$t( 'réponse expose uuid, pas id interne', preg_match( '/^[0-9a-f-]{36}$/i', $appU ) && ! isset( $r['data']['data']['id'] ) );
$aid = (int) $repo->get_by_uuid( $appU )['id'];

echo "== Doublon (contrainte candidat/offre) ==\n";
$t( 'seconde candidature même offre => 409', 409 === $req( 'POST', '/postelio/v1/jobs/' . $juuidA . '/applications', array( 'screening_answers' => array( 'permis' => 'non' ) ), $cand )['status'] );
// Concurrence : insertion directe en base doit violer la contrainte unique.
$dup = $repo->insert( array( 'candidate_user_id' => $cand, 'job_id' => $jidA, 'job_uuid' => $juuidA, 'company_id' => $cidA, 'company_uuid' => $cuuidA, 'status' => 'new', 'job_revision' => 1, 'job_snapshot' => array(), 'screening_answers' => array() ) );
$t( 'INSERT concurrent bloqué par la contrainte (retourne 0)', 0 === $dup );

echo "== Snapshot d'offre (revision figée) ==\n";
$rev_at_apply = (int) $repo->get( $aid )['job_revision'];
$req( 'PUT', '/postelio/v1/jobs/' . $juuidA, array( 'titre' => 'Poste MODIFIÉ' ), $recA ); // revision +1
$detail = $req( 'GET', '/postelio/v1/me/applications/' . $appU, null, $cand )['data']['data'];
$t( 'candidature garde sa revision d\'origine', (int) $detail['job']['revision'] === $rev_at_apply );
$t( 'snapshot titre ≠ titre courant modifié', 'Poste MODIFIÉ' !== ( $detail['job']['titre'] ?? '' ) );

echo "== Vue candidat ==\n";
$t( 'GET /me/applications liste >=1', count( (array) $req( 'GET', '/postelio/v1/me/applications', null, $cand )['data']['data'] ) >= 1 );
$mine = $req( 'GET', '/postelio/v1/me/applications/' . $appU, null, $cand );
$t( 'GET détail => 200 + timeline', 200 === $mine['status'] && isset( $mine['data']['data']['timeline'] ) );
$t( 'candidat NE voit PAS de notes', ! isset( $mine['data']['data']['notes'] ) );
$t( 'autre candidat => 404', 404 === $req( 'GET', '/postelio/v1/me/applications/' . $appU, null, $cand2 )['status'] );

echo "== Vue recruteur + ownership A/B ==\n";
$rc = $req( 'GET', '/postelio/v1/companies/me/applications/' . $appU, null, $recA );
$t( 'recruteur A voit la candidature => 200', 200 === $rc['status'] );
$t( 'recruteur voit screening_answers', isset( $rc['data']['data']['screening_answers'] ) );
$t( 'recruteur voit candidat (profil_uuid+nom)', ! empty( $rc['data']['data']['candidate']['display_name'] ) );
$t( 'recruteur B (autre entreprise) => 404', 404 === $req( 'GET', '/postelio/v1/companies/me/applications/' . $appU, null, $recB )['status'] );
$listA = (array) $req( 'GET', '/postelio/v1/companies/me/applications', null, $recA )['data']['data'];
$t( 'liste entreprise A contient la candidature', in_array( $appU, array_map( fn( $x ) => $x['uuid'], $listA ), true ) );
$listB = (array) $req( 'GET', '/postelio/v1/companies/me/applications', null, $recB )['data']['data'];
$t( 'liste entreprise B ne contient PAS la candidature', ! in_array( $appU, array_map( fn( $x ) => $x['uuid'], $listB ), true ) );

echo "== Transitions de statut ==\n";
$t( 'candidat ne peut changer le statut => 403', 403 === $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/status', array( 'to' => 'review' ), $cand )['status'] );
$t( 'recruteur B ne peut pas (404)', 404 === $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/status', array( 'to' => 'review' ), $recB )['status'] );
$t( 'new → review => 200', 'review' === ( $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/status', array( 'to' => 'review' ), $recA )['data']['data']['status'] ?? '' ) );
$t( 'review → shortlisted', 'shortlisted' === ( $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/status', array( 'to' => 'shortlisted' ), $recA )['data']['data']['status'] ?? '' ) );
$t( 'shortlisted → interview', 'interview' === ( $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/status', array( 'to' => 'interview' ), $recA )['data']['data']['status'] ?? '' ) );
$t( 'interview → selected', 'selected' === ( $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/status', array( 'to' => 'selected' ), $recA )['data']['data']['status'] ?? '' ) );
$t( 'selected → review INTERDIT => 409', 409 === $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/status', array( 'to' => 'review' ), $recA )['status'] );

echo "== Refus + motif interne non exposé ==\n";
$rj = $req( 'POST', '/postelio/v1/jobs/' . $juuidA . '/applications', array( 'screening_answers' => array( 'permis' => 'oui' ) ), $cand2 );
$appU2 = $rj['data']['data']['uuid'];
$req( 'POST', '/postelio/v1/companies/me/applications/' . $appU2 . '/status', array( 'to' => 'rejected', 'reason' => 'Profil trop junior (interne)' ), $recA );
$candTl = $req( 'GET', '/postelio/v1/me/applications/' . $appU2, null, $cand2 )['data']['data'];
$t( 'candidat voit statut rejected', 'rejected' === $candTl['status'] );
$t( 'candidat NE voit PAS le motif interne', false === strpos( wp_json_encode( $candTl ), 'trop junior' ) );
$recTl = $req( 'GET', '/postelio/v1/companies/me/applications/' . $appU2, null, $recA )['data']['data'];
$t( 'recruteur VOIT le motif interne (timeline)', false !== strpos( wp_json_encode( $recTl['timeline'] ), 'trop junior' ) );

echo "== Notes privées ==\n";
$t( 'recruteur A ajoute une note => 201', 201 === $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/notes', array( 'body' => 'Bon profil' ), $recA )['status'] );
$t( 'notes listées (1)', count( (array) $req( 'GET', '/postelio/v1/companies/me/applications/' . $appU . '/notes', null, $recA )['data']['data'] ) === 1 );
$t( 'recruteur B ne peut pas lire les notes (404)', 404 === $req( 'GET', '/postelio/v1/companies/me/applications/' . $appU . '/notes', null, $recB )['status'] );
$t( 'candidat n\'a aucune route notes (403)', in_array( $req( 'GET', '/postelio/v1/companies/me/applications/' . $appU . '/notes', null, $cand )['status'], array( 403 ), true ) );

echo "== Retrait candidat ==\n";
$rw = $req( 'POST', '/postelio/v1/jobs/' . $juuidA . '/applications', array( 'screening_answers' => array( 'permis' => 'non' ) ), $cand3 );
$appU3 = $rw['data']['data']['uuid'];
$t( 'withdraw => 200 withdrawn', 'withdrawn' === ( $req( 'POST', '/postelio/v1/me/applications/' . $appU3 . '/withdraw', null, $cand3 )['data']['data']['status'] ?? '' ) );
$t( 'second withdraw => 409', 409 === $req( 'POST', '/postelio/v1/me/applications/' . $appU3 . '/withdraw', null, $cand3 )['status'] );
$t( 're-candidature après retrait interdite => 409', 409 === $req( 'POST', '/postelio/v1/jobs/' . $juuidA . '/applications', array( 'screening_answers' => array( 'permis' => 'oui' ) ), $cand3 )['status'] );

echo "== Filtres / pagination recruteur ==\n";
$t( 'filtre statut=selected', count( (array) $req( 'GET', '/postelio/v1/companies/me/applications?status=selected', null, $recA )['data']['data'] ) >= 1 );
$t( 'filtre job=jobA', count( (array) $req( 'GET', '/postelio/v1/companies/me/applications?job=' . $juuidA, null, $recA )['data']['data'] ) >= 1 );
$rp = $req( 'GET', '/postelio/v1/companies/me/applications?per_page=9999', null, $recA );
$t( 'per_page borné 100', ( $rp['data']['meta']['pagination']['per_page'] ?? 0 ) === 100 );

echo "== Offre expirée / filled après candidature ==\n";
$jrepo->set_status( $jidA, 'expired' );
$t( 'candidature reste consultable (candidat) malgré offre expirée', 200 === $req( 'GET', '/postelio/v1/me/applications/' . $appU, null, $cand )['status'] );
$t( 'candidature reste consultable (recruteur)', 200 === $req( 'GET', '/postelio/v1/companies/me/applications/' . $appU, null, $recA )['status'] );
$t( 'nouvelle candidature sur offre expirée => 409/invalid', in_array( $req( 'POST', '/postelio/v1/jobs/' . $juuidA . '/applications', array( 'screening_answers' => array( 'permis' => 'oui' ) ), $cand2 )['status'], array( 409 ), true ) );
$jrepo->set_status( $jidB, 'filled' );
$t( 'candidature sur offre filled => 409/invalid', in_array( $req( 'POST', '/postelio/v1/jobs/' . $juuidB . '/applications', array( 'screening_answers' => array( 'dispo' => 'oui' ) ), $cand )['status'], array( 409 ), true ) );

echo "== UUID inconnu / invalide ==\n";
$t( 'candidature inexistante (candidat) => 404', 404 === $req( 'GET', '/postelio/v1/me/applications/' . wp_generate_uuid4(), null, $cand )['status'] );
$t( 'candidature inexistante (recruteur) => 404', 404 === $req( 'GET', '/postelio/v1/companies/me/applications/' . wp_generate_uuid4(), null, $recA )['status'] );

echo "== Événements / audit ==\n";
foreach ( array( 'application.created', 'application.status_changed', 'application.reviewed', 'application.shortlisted', 'application.interview', 'application.selected', 'application.rejected', 'application.withdrawn' ) as $ev ) {
	$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE action = %s", $ev ) );
	$t( "audit contient {$ev}", $n >= 1 );
}
// D1 : les événements exposent les UUID publics (application_uuid + job_uuid).
$t( 'application.created porte application_uuid (36c)', isset( $captured['created']['application_uuid'] ) && (bool) preg_match( '/^[0-9a-f-]{36}$/i', (string) $captured['created']['application_uuid'] ) );
$t( 'application.created porte job_uuid (36c)', isset( $captured['created']['job_uuid'] ) && (bool) preg_match( '/^[0-9a-f-]{36}$/i', (string) $captured['created']['job_uuid'] ) );
$t( 'application.rejected porte application_uuid', isset( $captured['rejected']['application_uuid'] ) && (bool) preg_match( '/^[0-9a-f-]{36}$/i', (string) $captured['rejected']['application_uuid'] ) );

echo "== Nettoyage ==\n";
$ap = $wpdb->prefix . 'postelio_applications';
$ids_in = implode( ',', array_map( 'intval', $companies ?: array( 0 ) ) );
// Historique + notes des candidatures de ces entreprises, puis les candidatures.
$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_application_history WHERE application_id IN (SELECT id FROM {$ap} WHERE company_id IN ({$ids_in}))" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_recruiter_notes WHERE application_id IN (SELECT id FROM {$ap} WHERE company_id IN ({$ids_in}))" );
$wpdb->query( "DELETE FROM {$ap} WHERE company_id IN ({$ids_in})" );
foreach ( $jobs as $jid ) { wp_delete_post( $jid, true ); }
foreach ( $companies as $cid ) { ( new MembershipRepository() )->remove_all_for_company( $cid ); wp_delete_post( $cid, true ); }
foreach ( $users as $u ) { ( new CandidateProfileRepository() )->delete_for( $u ); ( new RecruiterProfileRepository() )->delete_for( $u ); wp_delete_user( $u ); }
$wpdb->query( "DELETE FROM {$audit} WHERE resource_type IN ('application','job','company') OR action LIKE 'user.%'" );
echo "  candidatures + offres + entreprises + comptes supprimés\n";

echo "\n";
if ( empty( $fail ) ) { WP_CLI::success( "Smoke applications OK : {$pass} vérifications passées." ); }
else { WP_CLI::error( count( $fail ) . ' échec(s) : ' . implode( ' | ', $fail ) ); }
