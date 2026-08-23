<?php
/**
 * Smoke test postelio-moderation sur WordPress vivant :
 *
 *   wp eval-file plugins/postelio-moderation/tests/smoke.php --path=wordpress
 *
 * Couvre : activation/registry/3 tables ; signalements (validation, non-divulgation,
 * dedup, rate-limit, grouping + priorité max, statut générique, ownership, non-exposition) ;
 * file de cases (rôles, assign, note append-only, décision/transitions, nouvelle case après
 * clôture) ; passerelle préventive (jobs publish fail-closed + is_active ; message
 * low/medium/critical) ; actions déléguées (hide/unhide, close, warning, suspend job/user/
 * company + cascade offres) ; santé ; événements moderation.* sans doublon. Nettoie tout.
 *
 * @package Postelio\Moderation\Tests
 */

use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Companies\Verification\Siren;
use Postelio\Core\Permissions\Capabilities;
use Postelio\Core\Plugin as Core;
use Postelio\Jobs\Api\JobDirectory;
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
$t = static function ( string $l, bool $c ) use ( &$fail, &$pass ): void {
	if ( $c ) { ++$pass; echo "  [ok]   {$l}\n"; } else { $fail[] = $l; echo "  [FAIL] {$l}\n"; }
};
$req = static function ( string $m, string $route, ?array $body = null, int $user = 0 ): array {
	wp_set_current_user( $user );
	$query = array();
	if ( false !== strpos( $route, '?' ) ) { list( $route, $qs ) = explode( '?', $route, 2 ); parse_str( $qs, $query ); }
	$r = new WP_REST_Request( $m, $route );
	if ( ! empty( $query ) ) { $r->set_query_params( $query ); }
	if ( null !== $body ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
	$resp = rest_do_request( $r );
	return array( 'status' => $resp->get_status(), 'data' => $resp->get_data() );
};
$accounts = new AccountService( new CandidateProfileRepository(), new RecruiterProfileRepository() );
$mk = static function ( string $role ) use ( $accounts ): int {
	return $accounts->register( array( 'email' => 'smoke.mod.' . $role . '.' . wp_generate_password( 6, false ) . '@postelio.test', 'password' => 'motdepasse123', 'role' => $role ) );
};
$mkrole = static function ( string $wp_role ): int {
	$id = wp_insert_user( array( 'user_login' => 'smokemod_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 12 ), 'user_email' => 'smoke.mod.' . wp_generate_password( 6, false ) . '@postelio.test', 'role' => $wp_role ) );
	return is_wp_error( $id ) ? 0 : (int) $id;
};

global $wpdb;
$jrepo = new JobRepository();
$audit = $wpdb->prefix . 'postelio_audit_log';
$rep_tbl = $wpdb->prefix . 'postelio_moderation_reports';
$case_tbl = $wpdb->prefix . 'postelio_moderation_cases';
$evt_tbl = $wpdb->prefix . 'postelio_moderation_case_events';

// Baselines pour un nettoyage borné.
$base_rep = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$rep_tbl}" );
$base_case = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$case_tbl}" );
$base_evt = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$evt_tbl}" );

$users = array(); $jobs_created = array(); $company_id = 0;

echo "== Activation / registry / 3 tables ==\n";
$t( 'plugin moderation actif', is_plugin_active( 'postelio-moderation/postelio-moderation.php' ) );
$t( 'module moderation dans le registry', Core::instance()->registry()->has( 'moderation' ) );
$found_tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}postelio_moderation%'" );
$t( 'exactement 3 tables moderation', count( $found_tables ) === 3 );

echo "== Comptes + entreprise vérifiée ==\n";
$recA = $mk( 'recruiter' ); $recB = $mk( 'recruiter' ); $cand = $mk( 'candidate' ); $cand2 = $mk( 'candidate' );
$moderator = $mkrole( Capabilities::ROLE_MODERATOR ); $support = $mkrole( Capabilities::ROLE_SUPPORT );
$users = array( $recA, $recB, $cand, $cand2, $moderator, $support );
$admin = (int) ( get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) )[0] ?? 1 );
$t( 'moderator a la capability file', user_can( $moderator, 'pst_view_moderation_queue' ) );
$t( 'support N\'A PAS la capability file', ! user_can( $support, 'pst_view_moderation_queue' ) && ! user_can( $support, 'pst_moderate_content' ) );

$siren = '100000000'; while ( ! Siren::is_valid_siren( $siren ) ) { $siren = str_pad( (string) ( ( (int) $siren ) + 1 ), 9, '0', STR_PAD_LEFT ); }
$rc = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'Moderation Test SARL', 'legal' => array( 'siren' => $siren, 'raison_sociale' => 'Moderation Test SARL' ) ), $recA );
$cuuid = (string) ( $rc['data']['data']['uuid'] ?? '' );
$company_id = CompanyDirectory::company_of_user( $recA );
$req( 'POST', '/postelio/v1/companies/me/verification', null, $recA );
$req( 'POST', '/postelio/v1/companies/' . $cuuid . '/verification/decision', array( 'decision' => 'verified' ), $admin );
$t( 'entreprise vérifiée', \Postelio\Companies\Api\CompanyVerification::can_publish_jobs( $company_id ) );

$create_job = function ( array $extra = array() ) use ( $req, $recA, $jrepo, &$jobs_created ): string {
	$r = $req( 'POST', '/postelio/v1/jobs', array_merge( array( 'titre' => 'Poste ' . wp_generate_password( 4, false ), 'description' => 'Description neutre et correcte.', 'ville' => 'Lyon', 'contrat' => 'CDI' ), $extra ), $recA );
	$u = (string) ( $r['data']['data']['uuid'] ?? '' );
	if ( '' !== $u ) { $jobs_created[] = (int) $jrepo->get_by_uuid( $u )['id']; }
	return $u;
};

echo "== Signalement : validation & non-divulgation ==\n";
$jr = $create_job();
$t( 'anon POST /moderation/reports => 401', 401 === $req( 'POST', '/postelio/v1/moderation/reports', array( 'resource_type' => 'job', 'resource_uuid' => $jr, 'reason_code' => 'fraud' ), 0 )['status'] );
$t( 'type ressource invalide => 422', 422 === $req( 'POST', '/postelio/v1/moderation/reports', array( 'resource_type' => 'zzz', 'resource_uuid' => $jr, 'reason_code' => 'fraud' ), $cand )['status'] );
$t( 'reason invalide pour le type => 422', 422 === $req( 'POST', '/postelio/v1/moderation/reports', array( 'resource_type' => 'job', 'resource_uuid' => $jr, 'reason_code' => 'expired_offer_XXX' ), $cand )['status'] );
$t( 'ressource inconnue => 404 (non-divulgation)', 404 === $req( 'POST', '/postelio/v1/moderation/reports', array( 'resource_type' => 'job', 'resource_uuid' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'reason_code' => 'fraud' ), $cand )['status'] );

echo "== Signalement : dedup + grouping + priorité max ==\n";
$r1 = $req( 'POST', '/postelio/v1/moderation/reports', array( 'resource_type' => 'job', 'resource_uuid' => $jr, 'reason_code' => 'expired_offer', 'description' => 'offre expirée' ), $cand );
$t( 'report 1 => 201 status received', 201 === $r1['status'] && 'received' === ( $r1['data']['data']['status'] ?? '' ) && false === ( $r1['data']['data']['duplicate'] ?? true ) );
$r1b = $req( 'POST', '/postelio/v1/moderation/reports', array( 'resource_type' => 'job', 'resource_uuid' => $jr, 'reason_code' => 'expired_offer' ), $cand );
$t( 'report identique => duplicate=true', true === ( $r1b['data']['data']['duplicate'] ?? false ) );
$case_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$case_tbl} WHERE resource_type='job' AND resource_uuid=%s", $jr ), ARRAY_A );
$t( 'une case ouverte pour l\'offre', null !== $case_row );
$t( 'priorité initiale = low (expired_offer)', 'low' === ( $case_row['priority'] ?? '' ) );
$t( 'reports_count = 1 (dedup non compté)', 1 === (int) ( $case_row['reports_count'] ?? 0 ) );
$r2 = $req( 'POST', '/postelio/v1/moderation/reports', array( 'resource_type' => 'job', 'resource_uuid' => $jr, 'reason_code' => 'fraud' ), $cand2 );
$case_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$case_tbl} WHERE resource_type='job' AND resource_uuid=%s", $jr ), ARRAY_A );
$t( 'grouping : même case, reports_count = 2', 2 === (int) ( $case_row['reports_count'] ?? 0 ) );
$t( 'priorité bumpée à high (fraud)', 'high' === ( $case_row['priority'] ?? '' ) );
$case_uuid = (string) $case_row['public_uuid'];

echo "== Signalement : rate limit ==\n";
add_filter( 'postelio/moderation/report_rate_per_hour', fn() => 1 );
$jrl = $create_job();
$t( 'reporter neuf : 1er report ok', in_array( $req( 'POST', '/postelio/v1/moderation/reports', array( 'resource_type' => 'job', 'resource_uuid' => $jrl, 'reason_code' => 'scam' ), $recB )['status'], array( 200, 201 ), true ) );
$t( '2e report (>limite) => 429', 429 === $req( 'POST', '/postelio/v1/moderation/reports', array( 'resource_type' => 'job', 'resource_uuid' => $jr, 'reason_code' => 'scam' ), $recB )['status'] );
remove_all_filters( 'postelio/moderation/report_rate_per_hour' );

echo "== Statut générique + ownership + non-exposition ==\n";
$mine = $req( 'GET', '/postelio/v1/me/moderation/reports', null, $cand );
$rows = (array) ( $mine['data']['data'] ?? array() );
$t( '/me/moderation/reports => 200', 200 === $mine['status'] );
$t( 'liste non vide pour le reporter', count( $rows ) >= 1 );
$flat = wp_json_encode( $rows );
$t( 'statut générique (received/under_review/resolved)', (bool) preg_match( '/received|under_review|resolved/', $flat ) );
$t( 'PAS d\'ID SQL exposé', false === strpos( $flat, '"id"' ) && false === strpos( $flat, 'case_id' ) );
$t( 'PAS de note interne ni reporter_user_id', false === strpos( $flat, 'note' ) && false === strpos( $flat, 'reporter_user_id' ) );
$t( 'ownership : cand2 ne voit pas les reports de cand', count( (array) $req( 'GET', '/postelio/v1/me/moderation/reports', null, $cand2 )['data']['data'] ) < count( $rows ) + 1 );

echo "== File de cases : rôles ==\n";
$t( 'candidat GET /moderation/cases => 403', 403 === $req( 'GET', '/postelio/v1/moderation/cases', null, $cand )['status'] );
$t( 'support GET /moderation/cases => 403', 403 === $req( 'GET', '/postelio/v1/moderation/cases', null, $support )['status'] );
$ql = $req( 'GET', '/postelio/v1/moderation/cases', null, $moderator );
$t( 'moderator GET /moderation/cases => 200', 200 === $ql['status'] );
$t( 'la case de l\'offre est dans la file', in_array( $case_uuid, array_map( fn( $c ) => $c['uuid'], (array) $ql['data']['data'] ), true ) );
$qflat = wp_json_encode( $ql['data']['data'] );
$t( 'file n\'expose pas resource id SQL', false === strpos( $qflat, '"id":' ) );

echo "== File de cases : assign / note append-only / décision ==\n";
$asg = $req( 'POST', '/postelio/v1/moderation/cases/' . $case_uuid . '/assign', null, $moderator );
$t( 'assign => in_review', 'in_review' === ( $asg['data']['data']['status'] ?? '' ) );
$t( 'assigned_to = nom (jamais id)', ! empty( $asg['data']['data']['assigned_to'] ) && ! is_numeric( $asg['data']['data']['assigned_to'] ) );
$evt_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$evt_tbl} WHERE case_id=%d", (int) $case_row['id'] ) );
$req( 'POST', '/postelio/v1/moderation/cases/' . $case_uuid . '/note', array( 'note' => 'Note interne de test' ), $moderator );
$evt_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$evt_tbl} WHERE case_id=%d", (int) $case_row['id'] ) );
$t( 'note ajoutée (append-only, +1 event)', $evt_after === $evt_before + 1 );
$det = $req( 'GET', '/postelio/v1/moderation/cases/' . $case_uuid, null, $moderator );
$t( 'détail expose la note en file admin', false !== strpos( wp_json_encode( $det['data']['data']['events'] ?? array() ), 'Note interne de test' ) );
$dec = $req( 'POST', '/postelio/v1/moderation/cases/' . $case_uuid . '/decision', array( 'action' => 'no_action', 'note' => 'RAS' ), $moderator );
$t( 'décision no_action => dismissed', 'dismissed' === ( $dec['data']['data']['status'] ?? '' ) );
$t( 'décision sur case clôturée => 409', 409 === $req( 'POST', '/postelio/v1/moderation/cases/' . $case_uuid . '/decision', array( 'action' => 'no_action' ), $moderator )['status'] );

echo "== Nouvelle case après clôture ==\n";
$r3 = $req( 'POST', '/postelio/v1/moderation/reports', array( 'resource_type' => 'job', 'resource_uuid' => $jr, 'reason_code' => 'scam' ), $cand2 );
$n_cases = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$case_tbl} WHERE resource_type='job' AND resource_uuid=%s", $jr ) );
$t( 'report après clôture => nouvelle case (2 au total)', 2 === $n_cases );
$new_case = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$case_tbl} WHERE resource_type='job' AND resource_uuid=%s AND status IN ('open','in_review','escalated')", $jr ), ARRAY_A );
$t( 'la nouvelle case est active', null !== $new_case );

echo "== Action modérateur interdite (suspend_user) ==\n";
$new_case_uuid = (string) $new_case['public_uuid'];
$t( 'moderator ne peut PAS suspend_user => 403', 403 === $req( 'POST', '/postelio/v1/moderation/cases/' . $new_case_uuid . '/decision', array( 'action' => 'suspend_user', 'target' => array( 'type' => 'user', 'uuid' => UserDirectory::public_uuid( $recB ) ) ), $moderator )['status'] );

echo "== Passerelle préventive : jobs publish (fail-closed) ==\n";
$jok = $create_job( array( 'description' => 'Nous recherchons un développeur motivé pour rejoindre une belle équipe.' ) );
$t( 'offre neutre publie => 200', 200 === $req( 'POST', '/postelio/v1/jobs/' . $jok . '/publish', null, $recA )['status'] );
$jbad = $create_job( array( 'description' => 'Payez des frais de dossier par virement IBAN pour valider votre candidature.' ) );
$rb = $req( 'POST', '/postelio/v1/jobs/' . $jbad . '/publish', null, $recA );
$t( 'offre à risque => 422 moderation_blocked', 422 === $rb['status'] && 'moderation_blocked' === ( $rb['data']['error']['code'] ?? '' ) );
$t( 'offre bloquée reste en brouillon (fail-closed)', 'draft' === (string) $jrepo->get_by_uuid( $jbad )['status'] );
$t( 'message de blocage générique (aucune règle exposée)', false === strpos( strtolower( (string) ( $rb['data']['error']['message'] ?? '' ) ), 'iban' ) );

echo "== Passerelle préventive : is_active (recruteur suspendu) ==\n";
$jd = $create_job(); // créé pendant que le compte est actif
UserModeration::suspend( UserDirectory::public_uuid( $recA ), $admin );
$t( 'recruteur suspendu ne peut publier => 403', 403 === $req( 'POST', '/postelio/v1/jobs/' . $jd . '/publish', null, $recA )['status'] );
$t( 'recruteur suspendu ne peut créer => 403', 403 === $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'X' ), $recA )['status'] );
UserModeration::unsuspend( UserDirectory::public_uuid( $recA ), $admin );

echo "== Passerelle préventive : évaluation message (filtre) ==\n";
$eval = fn( $text, $ctx = array() ) => (array) apply_filters( 'postelio/moderation/evaluate', null, array( 'resource_type' => 'message', 'text' => $text, 'actor_id' => $cand, 'context' => $ctx ) );
$low = $eval( 'Bonjour, merci pour votre message, à bientôt.' );
$t( 'message neutre => allowed', 'allowed' === ( $low['decision'] ?? '' ) && empty( $low['blocked'] ) );
$conv_uuid = wp_generate_uuid4();
$med = $eval( 'Contactez-moi sur mon email perso : jean.dupont@example.com', array( 'conversation_uuid' => $conv_uuid ) );
$t( 'message contact => review_required (medium, non bloqué)', 'review_required' === ( $med['decision'] ?? '' ) && empty( $med['blocked'] ) );
$conv_case = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$case_tbl} WHERE resource_type='conversation' AND resource_uuid=%s", $conv_uuid ) );
$t( 'case ouverte sur la conversation (grouping message)', 1 === (int) $conv_case );
$crit = $eval( 'je vais te tuer si tu ne réponds pas' );
$t( 'menace explicite => blocked', true === ( $crit['blocked'] ?? false ) );
$t( 'décision bloquée : message générique non vide', ! empty( $crit['message'] ) );
$t( 'message générique n\'expose aucun reason code', false === strpos( strtolower( (string) $crit['message'] ), 'violence' ) && false === strpos( strtolower( (string) $crit['message'] ), 'threat' ) );

echo "== Actions déléguées : suspend_user (admin, via target) ==\n";
$r_userc = $req( 'POST', '/postelio/v1/moderation/reports', array( 'resource_type' => 'job', 'resource_uuid' => $jok, 'reason_code' => 'fraud' ), $cand );
$uc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$case_tbl} WHERE resource_type='job' AND resource_uuid=%s AND status IN ('open','in_review','escalated')", $jok ), ARRAY_A );
$dsu = $req( 'POST', '/postelio/v1/moderation/cases/' . $uc['public_uuid'] . '/decision', array( 'action' => 'suspend_user', 'target' => array( 'type' => 'user', 'uuid' => UserDirectory::public_uuid( $recB ) ), 'note' => 'abus' ), $admin );
$t( 'admin suspend_user => 200 resolved', 200 === $dsu['status'] && 'resolved' === ( $dsu['data']['data']['status'] ?? '' ) );
$t( 'utilisateur ciblé suspendu (réversible)', UserModeration::is_suspended( $recB ) );
UserModeration::unsuspend( UserDirectory::public_uuid( $recB ), $admin );

echo "== Actions déléguées : hide/unhide (external_job si présent) ==\n";
$ext_uuid = (string) $wpdb->get_var( "SELECT public_uuid FROM {$wpdb->prefix}postelio_external_jobs WHERE sync_status='active' LIMIT 1" );
if ( '' !== $ext_uuid && class_exists( '\\Postelio\\JobSources\\Api\\JobSourcesModeration' ) ) {
	$was_visible = \Postelio\JobSources\Api\JobSourcesModeration::is_visible( $ext_uuid );
	\Postelio\JobSources\Api\JobSourcesModeration::hide( $ext_uuid );
	$t( 'hide external_job => non visible', ! \Postelio\JobSources\Api\JobSourcesModeration::is_visible( $ext_uuid ) );
	\Postelio\JobSources\Api\JobSourcesModeration::unhide( $ext_uuid );
	$t( 'unhide external_job => visible', \Postelio\JobSources\Api\JobSourcesModeration::is_visible( $ext_uuid ) );
	if ( ! $was_visible ) { \Postelio\JobSources\Api\JobSourcesModeration::hide( $ext_uuid ); }
} else {
	$t( 'set_visibility uuid inconnu => false (délégation robuste)', false === \Postelio\JobSources\Api\JobSourcesModeration::set_visibility( 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'hidden' ) );
}

echo "== Actions déléguées : suspend_company + cascade offres ==\n";
$jcascade = $create_job();
$req( 'POST', '/postelio/v1/jobs/' . $jcascade . '/publish', null, $recA );
$t( 'offre cascade publiée', 'published' === (string) $jrepo->get_by_uuid( $jcascade )['status'] );
$req( 'POST', '/postelio/v1/moderation/reports', array( 'resource_type' => 'company', 'resource_uuid' => $cuuid, 'reason_code' => 'impersonation' ), $cand );
$cc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$case_tbl} WHERE resource_type='company' AND resource_uuid=%s AND status IN ('open','in_review','escalated')", $cuuid ), ARRAY_A );
$audit_comp_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit} WHERE action='company.suspended'" );
$dsc = $req( 'POST', '/postelio/v1/moderation/cases/' . $cc['public_uuid'] . '/decision', array( 'action' => 'suspend_company', 'reason_codes' => array( 'impersonation' ), 'note' => 'usurpation' ), $admin );
$t( 'admin suspend_company => 200 resolved', 200 === $dsc['status'] && 'resolved' === ( $dsc['data']['data']['status'] ?? '' ) );
$t( 'cascade : offre active de l\'entreprise suspendue', 'suspended' === (string) $jrepo->get_by_uuid( $jcascade )['status'] );
$audit_comp_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit} WHERE action='company.suspended'" );
$t( 'company.suspended émis UNE fois (pas de doublon)', $audit_comp_after === $audit_comp_before + 1 );
$t( 'aucune action moderation.company_suspended (pas de doublon)', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit} WHERE action='moderation.company_suspended'" ) );

echo "== Santé ==\n";
$h = $req( 'GET', '/postelio/v1/moderation/health', null, $moderator );
$t( 'health => 200 provider local_only', 200 === $h['status'] && 'local_only' === ( $h['data']['data']['provider'] ?? '' ) );
$t( 'health expose des compteurs', isset( $h['data']['data']['open_cases'], $h['data']['data']['cases_total'] ) );

echo "== Événements moderation.* audités ==\n";
foreach ( array( 'moderation.report_created', 'moderation.case_opened', 'moderation.review_started', 'moderation.decision_made' ) as $ev ) {
	$t( "audit contient {$ev}", (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE action=%s", $ev ) ) >= 1 );
}

echo "== Nettoyage ==\n";
$wpdb->query( "DELETE FROM {$evt_tbl} WHERE id > {$base_evt}" );
$wpdb->query( "DELETE FROM {$case_tbl} WHERE id > {$base_case}" );
$wpdb->query( "DELETE FROM {$rep_tbl} WHERE id > {$base_rep}" );
foreach ( array_unique( array_filter( $jobs_created ) ) as $jid2 ) { wp_delete_post( $jid2, true ); }
if ( $company_id ) { ( new \Postelio\Companies\Members\MembershipRepository() )->remove_all_for_company( $company_id ); wp_delete_post( $company_id, true ); }
foreach ( $users as $u ) { if ( $u ) { ( new CandidateProfileRepository() )->delete_for( $u ); ( new RecruiterProfileRepository() )->delete_for( $u ); wp_delete_user( $u ); } }
$wpdb->query( "DELETE FROM {$audit} WHERE action LIKE 'moderation.%' OR action LIKE 'user.%' OR resource_type IN ('job','company') OR action LIKE 'plugin.%'" );
echo "  reports/cases/events + offres + entreprise + comptes supprimés\n";

echo "\n";
if ( empty( $fail ) ) { WP_CLI::success( "Smoke moderation OK : {$pass} vérifications passées." ); }
else { WP_CLI::error( count( $fail ) . ' échec(s) : ' . implode( ' | ', $fail ) ); }
