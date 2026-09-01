<?php
/**
 * Smoke test postelio-alerts sur WordPress vivant :
 *   wp eval-file plugins/postelio-alerts/tests/smoke.php --path=wordpress
 *
 * Couvre : activation/tables/schema ; favoris (idempotence, quota, ownership, suspendu, natif+
 * externe, indisponible, référence invalide) ; recherches (create/update/delete, filtres stricts,
 * dédup, quotas, ownership) ; alertes (matching natif+externe, deliveries UNIQUE, dédup, curseur,
 * published_after, preview sans effet, run-now anti-spam, limite de sécurité) ; notifications
 * (catégorie job_alert, digest unique in-app, e-mail vérifié vs non, aucun wp_mail direct) ;
 * API (401/403/404, pas d'id SQL). Nettoie tout.
 *
 * @package Postelio\Alerts\Tests
 */

use Postelio\Alerts\Alerts\AlertDispatcher;
use Postelio\Alerts\Searches\SavedSearchRepository;
use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Companies\Members\MembershipRepository;
use Postelio\Companies\Verification\ManualVerificationProvider;
use Postelio\Companies\Verification\Siren;
use Postelio\Companies\Verification\VerificationService;
use Postelio\Core\Plugin as Core;
use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Users\Api\UserDirectory;
use Postelio\Users\Api\UserModeration;
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
$mk  = static function ( string $role ) use ( $acc ): int { return $acc->register( array( 'email' => 'al.' . $role . '.' . wp_generate_password( 6, false ) . '@postelio.test', 'password' => 'motdepasse123', 'role' => $role ) ); };
$siren = static function (): string { $s = (string) wp_rand( 100000000, 999999998 ); while ( ! Siren::is_valid_siren( $s ) ) { $s = str_pad( (string) ( ( (int) $s ) + 1 ), 9, '0', STR_PAD_LEFT ); } return $s; };

global $wpdb;
$jrepo    = new JobRepository();
$fav_tbl  = $wpdb->prefix . 'postelio_job_favorites';
$ss_tbl   = $wpdb->prefix . 'postelio_saved_searches';
$del_tbl  = $wpdb->prefix . 'postelio_alert_deliveries';
$ext_tbl  = $wpdb->prefix . 'postelio_external_jobs';
$notif    = $wpdb->prefix . 'postelio_notifications';
$notif_dl = $wpdb->prefix . 'postelio_notification_deliveries';
$audit    = $wpdb->prefix . 'postelio_audit_log';
$companies = array(); $jobs = array(); $ext_uuids = array();

// Capture des événements de digest / anomalie.
$captured = array();
Core::instance()->events()->on( 'job_alert.matches_found', static function ( $p ) use ( &$captured ) { $captured['matches'][] = $p; } );
Core::instance()->events()->on( 'job_alert.run_failed', static function ( $p ) use ( &$captured ) { $captured['failed'][] = $p; } );

$mkCompanyVerified = static function ( int $rec ) use ( $req, $siren, &$companies ): int {
	$c = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'Co ' . wp_generate_password( 4, false ), 'legal' => array( 'siren' => $siren() ) ), $rec );
	$cuuid = $c['data']['data']['uuid']; $cid = CompanyDirectory::id_from_uuid( $cuuid );
	$svc = new VerificationService( new CompanyRepository(), new ManualVerificationProvider() );
	$svc->request( $cid, 1 ); $svc->decide( $cid, 1, 'verified' );
	$companies[] = $cid; return $cid;
};
$mkJob = static function ( int $rec, string $ville ) use ( $req, $jrepo, &$jobs ): array {
	$j = $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'Poste ' . wp_generate_password( 4, false ), 'ville' => $ville, 'contrat' => 'CDI' ), $rec );
	$juuid = $j['data']['data']['uuid'];
	$req( 'POST', '/postelio/v1/jobs/' . $juuid . '/publish', null, $rec );
	$jid = $jrepo->get_by_uuid( $juuid )['id']; $jobs[] = $jid; return array( $juuid, $jid );
};
$seedExternal = static function ( string $ville, string $published_at ) use ( $wpdb, $ext_tbl, &$ext_uuids ): string {
	$uuid = wp_generate_uuid4();
	$wpdb->insert( $ext_tbl, array(
		'public_uuid' => $uuid, 'source_key' => 'smoke_src', 'external_id' => 'ext-' . wp_generate_password( 8, false ),
		'sync_status' => 'active', 'local_visibility' => 'visible', 'title' => 'Offre externe ' . wp_generate_password( 4, false ),
		'company_name' => 'Partenaire SA', 'ville' => $ville, 'application_mode' => 'external_redirect', 'alternance' => 0,
		'mapping_version' => 1, 'source_published_at' => $published_at, 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
	) );
	$ext_uuids[] = $uuid; return $uuid;
};

echo "== Activation / registry / tables / schema ==\n";
$t( 'plugin actif', is_plugin_active( 'postelio-alerts/postelio-alerts.php' ) );
$t( 'module alerts dans le registry', Core::instance()->registry()->has( 'alerts' ) );
foreach ( array( 'postelio_job_favorites', 'postelio_saved_searches', 'postelio_alert_deliveries' ) as $s ) {
	$tbl = $wpdb->prefix . $s;
	$t( "table {$tbl}", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
}
$t( 'schema alerts = 1', (string) get_option( 'postelio_alerts_schema' ) === '1' );
$t( 'ancre 07h30 planifiée', (bool) wp_next_scheduled( 'postelio_job_alerts_dispatch' ) );

echo "== Comptes / fixtures ==\n";
$candA = $mk( 'candidate' ); $candB = $mk( 'candidate' ); $candUnv = $mk( 'candidate' ); $candSusp = $mk( 'candidate' );
$recA  = $mk( 'recruiter' );
$users = array( $candA, $candB, $candUnv, $candSusp, $recA );
$cidA  = $mkCompanyVerified( $recA );
list( $juuidLyon, $jidLyon )   = $mkJob( $recA, 'Lyon' );
list( $juuidParis, $jidParis ) = $mkJob( $recA, 'Paris' );
$extLyon = $seedExternal( 'Lyon', current_time( 'mysql', true ) );

// Recherche du candidat suspendu créée TANT QU'IL EST ACTIF (la suspension bloque les mutations).
$ssSusp = (string) ( $req( 'POST', '/postelio/v1/me/saved-searches', array( 'filters' => array( 'ville' => 'Lyon' ), 'alert_frequency' => 'daily' ), $candSusp )['data']['data']['uuid'] ?? '' );
UserModeration::suspend( UserDirectory::public_uuid( $candSusp ) );

// Rembobine le curseur d'une recherche pour inclure les offres déjà publiées (simule des offres
// parues après la création de l'alerte, sans dépendre de l'horloge).
$rewind = static function ( string $uuid ) use ( $wpdb, $ss_tbl ): void {
	$wpdb->update( $ss_tbl, array( 'cursor_ts' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'public_uuid' => $uuid ) );
};

echo "== Favoris : sécurité & CRUD (§34) ==\n";
$t( 'anon POST favori => 401', 401 === $req( 'POST', '/postelio/v1/me/favorites/jobs/' . $juuidLyon, null, 0 )['status'] );
$t( 'recruteur (pas la cap) => 403', 403 === $req( 'POST', '/postelio/v1/me/favorites/jobs/' . $juuidLyon, null, $recA )['status'] );
$a1 = $req( 'POST', '/postelio/v1/me/favorites/jobs/' . $juuidLyon, null, $candA );
$t( 'candidat ajoute favori natif => 201', 201 === $a1['status'] && 'native' === ( $a1['data']['data']['source'] ?? '' ) );
$t( 'favori natif disponible', true === ( $a1['data']['data']['available'] ?? null ) );
$a2 = $req( 'POST', '/postelio/v1/me/favorites/jobs/' . $juuidLyon, null, $candA );
$t( 'ajout en double => 200 non créé (idempotent)', 200 === $a2['status'] && false === ( $a2['data']['data']['created'] ?? null ) );
$t( 'une seule ligne favori', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$fav_tbl} WHERE candidate_user_id=%d", $candA ) ) );
$ae = $req( 'POST', '/postelio/v1/me/favorites/jobs/' . $extLyon, null, $candA );
$t( 'favori externe => 201 source external', 201 === $ae['status'] && 'external' === ( $ae['data']['data']['source'] ?? '' ) );
$lst = $req( 'GET', '/postelio/v1/me/favorites/jobs', null, $candA );
$t( 'liste favoris = 2 (paginée)', 2 === (int) ( $lst['data']['meta']['pagination']['total'] ?? 0 ) );
$t( 'liste sans id SQL', false === strpos( wp_json_encode( $lst['data']['data'] ), '"candidate_user_id"' ) );
$t( 'candidat B ne voit pas les favoris de A', 0 === (int) ( $req( 'GET', '/postelio/v1/me/favorites/jobs', null, $candB )['data']['meta']['pagination']['total'] ?? -1 ) );
$t( 'référence inconnue => 404', 404 === $req( 'POST', '/postelio/v1/me/favorites/jobs/' . wp_generate_uuid4(), null, $candA )['status'] );
$rem = $req( 'DELETE', '/postelio/v1/me/favorites/jobs/' . $juuidLyon, null, $candA );
$t( 'retrait favori => 200', 200 === $rem['status'] );
$t( 'retrait inexistant => 200 (idempotent)', 200 === $req( 'DELETE', '/postelio/v1/me/favorites/jobs/' . $juuidLyon, null, $candA )['status'] );
$t( 'reste 1 favori (externe)', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$fav_tbl} WHERE candidate_user_id=%d", $candA ) ) );

echo "== Favoris : offre indisponible (§12) ==\n";
$jrepo->set_status( $jidParis, 'expired' );
$req( 'POST', '/postelio/v1/me/favorites/jobs/' . $juuidParis, null, $candB );
$viewP = $req( 'GET', '/postelio/v1/me/favorites/jobs', null, $candB )['data']['data'][0] ?? array();
$t( 'favori d\'offre expirée conservé + available:false', ( $viewP['job_uuid'] ?? '' ) === $juuidParis && false === ( $viewP['available'] ?? null ) );

echo "== Favoris : suspension + quota ==\n";
$t( 'candidat suspendu : mutation favori => 403', 403 === $req( 'POST', '/postelio/v1/me/favorites/jobs/' . $juuidLyon, null, $candSusp )['status'] );
add_filter( 'postelio/alerts/max_favorites', static function () { return 1; } );
$q1 = $req( 'POST', '/postelio/v1/me/favorites/jobs/' . $juuidLyon, null, $candA ); // candA a déjà 1 (externe)
$q1d = (array) ( $q1['data']['error']['details'] ?? array() );
$t( 'quota favoris atteint => 409', 409 === $q1['status'] && 'favorites_quota' === ( $q1d['reason'] ?? '' ) );
remove_all_filters( 'postelio/alerts/max_favorites' );

echo "== Recherches sauvegardées (§35) ==\n";
$c1 = $req( 'POST', '/postelio/v1/me/saved-searches', array( 'name' => 'Lyon', 'filters' => array( 'ville' => 'Lyon' ), 'alert_frequency' => 'daily' ), $candA );
$t( 'create => 201', 201 === $c1['status'] );
$ssA = (string) ( $c1['data']['data']['uuid'] ?? '' );
$t( 'alerte active + next_run_at planifié', true === ( $c1['data']['data']['alert_active'] ?? null ) && ! empty( $c1['data']['data']['next_run_at'] ) );
$t( 'pas d\'id SQL / hash exposé', false === strpos( wp_json_encode( $c1['data']['data'] ), 'filters_hash' ) && false === strpos( wp_json_encode( $c1['data']['data'] ), '"id"' ) );
$t( 'filtre inconnu => 422', 422 === $req( 'POST', '/postelio/v1/me/saved-searches', array( 'filters' => array( 'bogus' => 'x' ) ), $candA )['status'] );
$dup = $req( 'POST', '/postelio/v1/me/saved-searches', array( 'name' => 'Autre nom', 'filters' => array( 'ville' => 'Lyon' ) ), $candA );
$dupd = (array) ( $dup['data']['error']['details'] ?? array() );
$t( 'filtres identiques => 409 + pointe l\'existante', 409 === $dup['status'] && $ssA === ( $dupd['saved_search_uuid'] ?? '' ) );
$t( 'candidat B GET recherche de A => 404', 404 === $req( 'GET', '/postelio/v1/me/saved-searches/' . $ssA, null, $candB )['status'] );
$upd = $req( 'PUT', '/postelio/v1/me/saved-searches/' . $ssA, array( 'name' => 'Dev Lyon (maj)', 'alert_frequency' => 'weekly' ), $candA );
$t( 'update nom + fréquence => 200 weekly', 200 === $upd['status'] && 'weekly' === ( $upd['data']['data']['alert_frequency'] ?? '' ) );
$t( 'liste recherches = 1', 1 === count( (array) $req( 'GET', '/postelio/v1/me/saved-searches', null, $candA )['data']['data'] ) );

echo "== Quotas recherches / alertes actives ==\n";
add_filter( 'postelio/alerts/max_saved_searches', static function () { return 1; } );
$t( 'quota recherches => 409', 409 === $req( 'POST', '/postelio/v1/me/saved-searches', array( 'filters' => array( 'ville' => 'Nantes' ) ), $candA )['status'] );
remove_all_filters( 'postelio/alerts/max_saved_searches' );
add_filter( 'postelio/alerts/max_active_alerts', static function () { return 1; } );
$act = $req( 'POST', '/postelio/v1/me/saved-searches', array( 'filters' => array( 'ville' => 'Nantes' ), 'alert_frequency' => 'daily' ), $candA );
$actd = (array) ( $act['data']['error']['details'] ?? array() );
$t( 'quota alertes actives => 409', 409 === $act['status'] && 'active_alerts_quota' === ( $actd['reason'] ?? '' ) );
$t( 'même filtres mais alerte désactivée => 201', 201 === $req( 'POST', '/postelio/v1/me/saved-searches', array( 'filters' => array( 'ville' => 'Nantes' ), 'alert_frequency' => 'disabled' ), $candA )['status'] );
remove_all_filters( 'postelio/alerts/max_active_alerts' );

echo "== Matching / deliveries / dédup (§36) ==\n";
$rewind( $ssA ); // inclut l'offre native + externe déjà publiées (Lyon)
$captured = array();
$run1 = $req( 'POST', '/postelio/v1/me/saved-searches/' . $ssA . '/run-now', null, $candA );
$t( 'run-now => 200', 200 === $run1['status'] );
$t( 'matching natif + externe (>=2)', (int) ( $run1['data']['data']['matched'] ?? 0 ) >= 2 );
$t( 'digest émis UNE fois', isset( $captured['matches'] ) && 1 === count( $captured['matches'] ) );
$t( 'digest : match_count = nb nouvelles offres', (int) ( $captured['matches'][0]['match_count'] ?? 0 ) === (int) ( $run1['data']['data']['matched'] ?? -1 ) );
$t( 'digest : échantillon <= 5', count( (array) ( $captured['matches'][0]['sample'] ?? array() ) ) <= 5 );
$t( 'deliveries créées', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$del_tbl} d JOIN {$ss_tbl} s ON s.id=d.saved_search_id WHERE s.public_uuid=%s", $ssA ) ) >= 2 );
$captured = array();
$run2 = $req( 'POST', '/postelio/v1/me/saved-searches/' . $ssA . '/run-now', null, $candA );
$t( 'second run : 0 nouvelle (dédup deliveries)', 0 === (int) ( $run2['data']['data']['matched'] ?? -1 ) );
$t( 'second run : aucun digest', empty( $captured['matches'] ) );

echo "== Curseur / published_after ==\n";
$ssId = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$ss_tbl} WHERE public_uuid=%s", $ssA ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$del_tbl} WHERE saved_search_id=%d", $ssId ) ); // on efface les deliveries
$future = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
$wpdb->update( $ss_tbl, array( 'cursor_ts' => $future ), array( 'id' => $ssId ) );
$captured = array();
$run3 = $req( 'POST', '/postelio/v1/me/saved-searches/' . $ssA . '/run-now', null, $candA );
$t( 'curseur futur => 0 offre (published_after borne)', 0 === (int) ( $run3['data']['data']['matched'] ?? -1 ) );
$wpdb->update( $ss_tbl, array( 'cursor_ts' => null ), array( 'id' => $ssId ) );

echo "== Preview (sans effet) & limite de sécurité (§10) ==\n";
$wpdb->query( $wpdb->prepare( "DELETE FROM {$del_tbl} WHERE saved_search_id=%d", $ssId ) );
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$del_tbl}" );
$pv = $req( 'POST', '/postelio/v1/me/saved-searches/' . $ssA . '/preview', null, $candA );
$t( 'preview => 200 avec count', 200 === $pv['status'] && isset( $pv['data']['data']['count'] ) );
$t( 'preview ne crée aucune delivery', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$del_tbl}" ) === $before );
add_filter( 'postelio/alerts/match_per_page', static function () { return 1; } );
add_filter( 'postelio/alerts/match_max_pages', static function () { return 1; } );
$rewind( $ssA );
$captured = array();
$req( 'POST', '/postelio/v1/me/saved-searches/' . $ssA . '/run-now', null, $candA );
$t( 'limite de sécurité atteinte => anomalie loguée (event)', ! empty( $captured['failed'] ) && 'result_cap' === ( $captured['failed'][0]['reason'] ?? '' ) );
remove_all_filters( 'postelio/alerts/match_per_page' );
remove_all_filters( 'postelio/alerts/match_max_pages' );

echo "== Suspension : run bloqué (§17) ==\n";
$t( 'recherche du suspendu bien créée avant suspension', '' !== $ssSusp );
$t( 'candidat suspendu : run-now => 403', 403 === $req( 'POST', '/postelio/v1/me/saved-searches/' . $ssSusp . '/run-now', null, $candSusp )['status'] );

echo "== Notifications (§37) ==\n";
$cats = \Postelio\Notifications\Notifications\PreferenceService::catalog();
$t( 'catégorie job_alert enregistrée (extensible)', isset( $cats['job_alert'] ) && false === $cats['job_alert']['marketing'] );
$prefs = new \Postelio\Notifications\Notifications\PreferenceService();
$t( 'job_alert résolue pour un candidat', $prefs->allows( $candA, 'job_alert', 'in_app' ) );
$t( 'template job_alert_digest enregistré', \Postelio\Notifications\Email\TemplateRegistry::exists( 'job_alert_digest' ) );
// Digest in-app unique + e-mail (candidat vérifié).
$wpdb->query( $wpdb->prepare( "DELETE FROM {$del_tbl} WHERE saved_search_id=%d", $ssId ) );
$rewind( $ssA );
$wpdb->delete( $notif, array( 'user_id' => $candA ) );
$wpdb->delete( $notif_dl, array( 'user_id' => $candA ) );
$req( 'POST', '/postelio/v1/me/saved-searches/' . $ssA . '/run-now', null, $candA );
$t( 'digest in-app UNIQUE (pas 1 par offre)', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notif} WHERE user_id=%d AND type='job_alert_digest'", $candA ) ) );
$t( 'e-mail digest en file (candidat vérifié)', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notif_dl} WHERE user_id=%d AND template='job_alert_digest'", $candA ) ) >= 1 );
// Candidat e-mail NON vérifié : in-app oui, e-mail non.
delete_user_meta( $candUnv, AccountService::META_EMAIL_VERIFIED ); clean_user_cache( $candUnv );
$ssUnv = (string) ( $req( 'POST', '/postelio/v1/me/saved-searches', array( 'filters' => array( 'ville' => 'Lyon' ), 'alert_frequency' => 'daily' ), $candUnv )['data']['data']['uuid'] ?? '' );
$rewind( $ssUnv );
$req( 'POST', '/postelio/v1/me/saved-searches/' . $ssUnv . '/run-now', null, $candUnv );
$t( 'non vérifié : in-app présent', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notif} WHERE user_id=%d AND type='job_alert_digest'", $candUnv ) ) >= 1 );
$t( 'non vérifié : aucun e-mail digest', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notif_dl} WHERE user_id=%d AND template='job_alert_digest'", $candUnv ) ) );
$t( 'aucun wp_mail direct dans postelio-alerts', false === strpos( (string) file_get_contents( POSTELIO_ALERTS_DIR . 'src/Alerts/MatchingService.php' ), 'wp_mail' ) );

echo "== RGPD : suppression de compte purge (§30) ==\n";
$req( 'POST', '/postelio/v1/me/favorites/jobs/' . $extLyon, null, $candB ); // candB a favoris + recherche (Paris expirée déjà)
$before_fav = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$fav_tbl} WHERE candidate_user_id=%d", $candB ) );
$t( 'candB a des favoris avant suppression', $before_fav >= 1 );
Core::instance()->events()->emit( 'user.deleted', array( 'id' => $candB, 'resource_type' => 'user', 'resource_id' => (string) $candB ) );
$t( 'favoris purgés après user.deleted', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$fav_tbl} WHERE candidate_user_id=%d", $candB ) ) );
$t( 'recherches purgées après user.deleted', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ss_tbl} WHERE candidate_user_id=%d", $candB ) ) );
// Export inclut favoris + recherches.
$export = $acc->export( $candA );
$t( 'export RGPD inclut favorites + saved_searches', array_key_exists( 'favorites', $export ) && array_key_exists( 'saved_searches', $export ) );

echo "== Nettoyage ==\n";
foreach ( $users as $uid ) {
	$wpdb->delete( $fav_tbl, array( 'candidate_user_id' => $uid ) );
	foreach ( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$ss_tbl} WHERE candidate_user_id=%d", $uid ) ) ) as $sid ) {
		$wpdb->delete( $del_tbl, array( 'saved_search_id' => $sid ) );
	}
	$wpdb->delete( $ss_tbl, array( 'candidate_user_id' => $uid ) );
	$wpdb->delete( $notif, array( 'user_id' => $uid ) );
	$wpdb->delete( $notif_dl, array( 'user_id' => $uid ) );
}
foreach ( $ext_uuids as $u ) { $wpdb->delete( $ext_tbl, array( 'public_uuid' => $u ) ); }
foreach ( $jobs as $jid ) { wp_delete_post( $jid, true ); }
foreach ( $companies as $cid ) { ( new MembershipRepository() )->remove_all_for_company( $cid ); wp_delete_post( $cid, true ); }
foreach ( $users as $uid ) { wp_delete_user( $uid ); }
$wpdb->query( "DELETE FROM {$audit} WHERE resource_type IN ('job_favorite','saved_search','job','company') OR action LIKE 'user.%' OR action LIKE 'favorite.%' OR action LIKE 'saved_search.%' OR action LIKE 'job_alert.%'" );
echo "  [ok]   nettoyage effectué\n";

echo "\n";
if ( empty( $fail ) ) {
	WP_CLI::success( "postelio-alerts smoke OK ({$pass} assertions)." );
} else {
	echo count( $fail ) . " ÉCHEC(S) sur " . ( $pass + count( $fail ) ) . " :\n";
	foreach ( $fail as $f ) { echo "  - {$f}\n"; }
	WP_CLI::error( 'Des assertions ont échoué.' );
}
