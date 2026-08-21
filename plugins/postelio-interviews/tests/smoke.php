<?php
/**
 * Smoke test postelio-interviews sur WordPress vivant :
 *   wp eval-file plugins/postelio-interviews/tests/smoke.php --path=wordpress
 *
 * Scénario complet : proposition, confirmation, modification/reconfirmation, réalisé,
 * autre créneau (candidat) + acceptation (recruteur), annulation, refus, doublon,
 * candidature terminale, listes/filtres, historique, permissions, events/audit.
 *
 * @package Postelio\Interviews\Tests
 */

use Postelio\Applications\Applications\ApplicationRepository;
use Postelio\Applications\Applications\ApplicationService as AppSvc;
use Postelio\Applications\Applications\HistoryRepository as AppHistory;
use Postelio\Applications\Applications\NoteRepository as AppNotes;
use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Companies\Members\MembershipRepository;
use Postelio\Companies\Verification\ManualVerificationProvider;
use Postelio\Companies\Verification\VerificationService;
use Postelio\Core\Plugin as Core;
use Postelio\Interviews\Api\InterviewDirectory;
use Postelio\Interviews\Interviews\InterviewRepository;
use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Users\Profiles\CandidateProfileRepository;
use Postelio\Users\Profiles\RecruiterProfileRepository;
use Postelio\Users\Users\AccountService;

if ( ! defined( 'ABSPATH' ) ) { echo "WP-CLI requis.\n"; exit( 1 ); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$fail = array(); $pass = 0;
$t = static function ( string $l, bool $c ) use ( &$fail, &$pass ): void { if ( $c ) { ++$pass; echo "  [ok]   {$l}\n"; } else { $fail[] = $l; echo "  [FAIL] {$l}\n"; } };
$req = static function ( string $m, string $route, ?array $body, int $user ): array {
	wp_set_current_user( $user );
	$r = new WP_REST_Request( $m, $route );
	if ( null !== $body ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
	$x = rest_do_request( $r );
	return array( 'status' => $x->get_status(), 'data' => $x->get_data() );
};
$acc = new AccountService( new CandidateProfileRepository(), new RecruiterProfileRepository() );
$mk  = static function ( string $role ) use ( $acc ): int { return $acc->register( array( 'email' => 'iv.' . $role . '.' . wp_generate_password( 6, false ) . '@postelio.test', 'password' => 'motdepasse123', 'role' => $role ) ); };

global $wpdb;
$audit   = $wpdb->prefix . 'postelio_audit_log';
$ivRepo  = new InterviewRepository();
$appSvc  = new AppSvc( new ApplicationRepository(), new AppHistory(), new AppNotes() );
$users = array(); $companies = array(); $jobs = array();

$mkCompany = static function ( int $rec ) use ( $req, &$companies ): array {
	$s = (string) wp_rand( 100000000, 999999998 );
	while ( ! \Postelio\Companies\Verification\Siren::is_valid_siren( $s ) ) { $s = str_pad( (string) ( ( (int) $s ) + 1 ), 9, '0', STR_PAD_LEFT ); }
	$c   = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'Co ' . wp_generate_password( 4, false ), 'legal' => array( 'siren' => $s ) ), $rec );
	$cid = CompanyDirectory::id_from_uuid( $c['data']['data']['uuid'] );
	$vs  = new VerificationService( new CompanyRepository(), new ManualVerificationProvider() ); $vs->request( $cid, 1 ); $vs->decide( $cid, 1, 'verified' );
	$companies[] = $cid; return array( $c['data']['data']['uuid'], $cid );
};
$future = static function ( int $days ) : string { return gmdate( 'Y-m-d\TH:i:s\Z', time() + $days * 86400 ); };

echo "== Activation / tables / registry ==\n";
$t( 'plugin interviews actif', is_plugin_active( 'postelio-interviews/postelio-interviews.php' ) );
$t( 'module interviews registry', Core::instance()->registry()->has( 'interviews' ) );
foreach ( array( 'postelio_interviews', 'postelio_interview_history' ) as $s ) {
	$tbl = $wpdb->prefix . $s;
	$t( "table {$tbl}", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
}
$t( 'schema interviews = 1', (string) get_option( 'postelio_interviews_schema' ) === '1' );

echo "== Contexte (entreprise, offre, candidature) ==\n";
$recA = $mk( 'recruiter' ); $recB = $mk( 'recruiter' ); $candA = $mk( 'candidate' ); $candB = $mk( 'candidate' );
$users = array( $recA, $recB, $candA, $candB );
list( $cuuidA, $cidA ) = $mkCompany( $recA );
list( $cuuidB, $cidB ) = $mkCompany( $recB );
$j  = $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'Développeur', 'ville' => 'Lyon' ), $recA ); $ju = $j['data']['data']['uuid'];
$req( 'POST', '/postelio/v1/jobs/' . $ju . '/publish', null, $recA );
$jid = ( new JobRepository() )->get_by_uuid( $ju )['id']; $jobs[] = $jid;
$appU = $req( 'POST', '/postelio/v1/jobs/' . $ju . '/applications', array( 'screening_answers' => array() ), $candA )['data']['data']['uuid'];
$appId = (int) ( new ApplicationRepository() )->get_by_uuid( $appU )['id'];

echo "== Proposition d'entretien (recruteur, visio) ==\n";
$prop = $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/interviews', array(
	'type' => 'video', 'scheduled_at' => $future( 3 ), 'duration_minutes' => 45, 'timezone' => 'Europe/Paris',
	'video_data' => array( 'meeting_url' => 'https://meet.example.com/entretien-1', 'provider' => 'Jitsi' ),
	'instructions' => 'Merci de tester votre micro <script>alert(1)</script> avant.',
), $recA );
$t( 'proposition => 201', 201 === $prop['status'] );
$iv = (string) ( $prop['data']['data']['uuid'] ?? '' );
$t( 'entretien a un uuid, pas d\'id interne', preg_match( '/^[0-9a-f-]{36}$/i', $iv ) && ! isset( $prop['data']['data']['id'] ) );
$t( 'statut proposed', 'proposed' === ( $prop['data']['data']['status'] ?? '' ) );
$t( 'scheduled_at ISO 8601 UTC (Z)', (bool) preg_match( '/T.*Z$/', (string) ( $prop['data']['data']['scheduled_at'] ?? '' ) ) );
$t( 'XSS neutralisé dans instructions', false === strpos( (string) ( $prop['data']['data']['instructions'] ?? '' ), '<script>' ) );
$t( 'url visio conservée', 'https://meet.example.com/entretien-1' === ( $prop['data']['data']['video']['meeting_url'] ?? '' ) );
$t( 'candidature passée au pipeline interview', 'interview' === ( new ApplicationRepository() )->get_by_uuid( $appU )['status'] );

echo "== §B Plusieurs entretiens par candidature + doublon actif refusé ==\n";
$multi = $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/interviews', array( 'type' => 'phone', 'scheduled_at' => $future( 4 ), 'duration_minutes' => 30, 'phone_data' => array( 'phone_number' => '0600000000' ) ), $recA );
$t( 'entretien successif (autre créneau/type) autorisé => 201', 201 === $multi['status'] );
$ivMulti = (string) ( $multi['data']['data']['uuid'] ?? '' );
$dup = $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/interviews', array(
	'type' => 'video', 'scheduled_at' => $future( 3 ), 'duration_minutes' => 45, 'video_data' => array( 'meeting_url' => 'https://meet.example.com/entretien-1' ),
), $recA );
$t( 'doublon actif strictement identique (même créneau+type) refusé => 409', 409 === $dup['status'] );
$req( 'POST', '/postelio/v1/companies/me/interviews/' . $ivMulti . '/cancel', null, $recA ); // on écarte le 2e pour la suite

echo "== Vue candidat + confirmation ==\n";
$listCand = $req( 'GET', '/postelio/v1/me/interviews', null, $candA );
$candUuids = array_column( (array) $listCand['data']['data'], 'uuid' );
$t( 'candidat retrouve ses entretiens (dont le 1er)', 200 === $listCand['status'] && in_array( $iv, $candUuids, true ) );
$detCand = $req( 'GET', '/postelio/v1/me/interviews/' . $iv, null, $candA );
$t( 'candidat voit actions confirm/decline/reschedule', in_array( 'confirm', (array) ( $detCand['data']['data']['actions'] ?? array() ), true ) );
$t( 'candidat ne voit pas la vue recruteur (pas de bloc candidate)', ! isset( $detCand['data']['data']['candidate'] ) );
$conf = $req( 'POST', '/postelio/v1/me/interviews/' . $iv . '/confirm', null, $candA );
$t( 'confirmation => 200 confirmed', 200 === $conf['status'] && 'confirmed' === ( $conf['data']['data']['status'] ?? '' ) );

echo "== Modification substantielle (recruteur) → reconfirmation ==\n";
$mod = $req( 'PUT', '/postelio/v1/companies/me/interviews/' . $iv, array( 'scheduled_at' => $future( 6 ) ), $recA );
$t( 'modif date d\'un confirmé => repasse proposed', 200 === $mod['status'] && 'proposed' === ( $mod['data']['data']['status'] ?? '' ) );
$conf2 = $req( 'POST', '/postelio/v1/me/interviews/' . $iv . '/confirm', null, $candA );
$t( 'reconfirmation candidat => confirmed', 'confirmed' === ( $conf2['data']['data']['status'] ?? '' ) );

echo "== Réalisé (recruteur) ==\n";
$comp = $req( 'POST', '/postelio/v1/companies/me/interviews/' . $iv . '/complete', null, $recA );
$t( 'complete => 200 completed', 200 === $comp['status'] && 'completed' === ( $comp['data']['data']['status'] ?? '' ) );

echo "== Nouvel entretien sur place + autre créneau (candidat) + acceptation (recruteur) ==\n";
$onsite = $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/interviews', array(
	'type' => 'onsite', 'scheduled_at' => $future( 8 ), 'duration_minutes' => 60, 'timezone' => 'Europe/Paris',
	'location_data' => array( 'address' => '10 rue de la Paix', 'postal_code' => '75002', 'city' => 'Paris', 'contact' => 'Accueil', 'access_instructions' => '2e étage' ),
), $recA );
$t( 'entretien onsite créé (ancien terminé)', 201 === $onsite['status'] && 'onsite' === ( $onsite['data']['data']['type'] ?? '' ) );
$iv2 = (string) $onsite['data']['data']['uuid'];
$t( 'adresse structurée conservée', '75002' === ( $onsite['data']['data']['location']['postal_code'] ?? '' ) );
$resc = $req( 'POST', '/postelio/v1/me/interviews/' . $iv2 . '/reschedule', array( 'scheduled_at' => $future( 10 ), 'message' => 'Plutôt en fin de semaine ?' ), $candA );
$t( 'candidat propose un autre créneau => reschedule_requested', 200 === $resc['status'] && 'reschedule_requested' === ( $resc['data']['data']['status'] ?? '' ) );
$t( 'proposition candidat visible', 'candidate' === ( $resc['data']['data']['proposed']['by'] ?? '' ) );
$acceptR = $req( 'POST', '/postelio/v1/companies/me/interviews/' . $iv2 . '/accept-reschedule', null, $recA );
$t( 'recruteur accepte le créneau => confirmed', 200 === $acceptR['status'] && 'confirmed' === ( $acceptR['data']['data']['status'] ?? '' ) );
$t( 'proposition consommée (plus de bloc proposed)', ! isset( $acceptR['data']['data']['proposed'] ) );

echo "== Annulation (recruteur) ==\n";
$cancel = $req( 'POST', '/postelio/v1/companies/me/interviews/' . $iv2 . '/cancel', array( 'reason' => 'Poste pourvu' ), $recA );
$t( 'annulation => 200 cancelled', 200 === $cancel['status'] && 'cancelled' === ( $cancel['data']['data']['status'] ?? '' ) );

echo "== Refus candidat ==\n";
$phone = $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/interviews', array( 'type' => 'phone', 'scheduled_at' => $future( 12 ), 'duration_minutes' => 20, 'phone_data' => array( 'phone_number' => '+33 6 12 34 56 78', 'who_calls' => 'recruiter_calls' ) ), $recA );
$iv3 = (string) $phone['data']['data']['uuid'];
$t( 'entretien téléphone créé', 201 === $phone['status'] && 'recruiter_calls' === ( $phone['data']['data']['phone']['who_calls'] ?? '' ) );
$decl = $req( 'POST', '/postelio/v1/me/interviews/' . $iv3 . '/decline', array( 'message' => 'Non disponible' ), $candA );
$t( 'refus => 200 declined', 200 === $decl['status'] && 'declined' === ( $decl['data']['data']['status'] ?? '' ) );

echo "== §A Annulation par le candidat (après confirmation) ==\n";
$ivc = (string) $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/interviews', array( 'type' => 'video', 'scheduled_at' => $future( 14 ), 'duration_minutes' => 30, 'video_data' => array( 'meeting_url' => 'https://meet.example.com/cc' ) ), $recA )['data']['data']['uuid'];
$req( 'POST', '/postelio/v1/me/interviews/' . $ivc . '/confirm', null, $candA );
$t( 'candidat voit l\'action cancel sur un confirmé', in_array( 'cancel', (array) ( $req( 'GET', '/postelio/v1/me/interviews/' . $ivc, null, $candA )['data']['data']['actions'] ?? array() ), true ) );
$t( 'candidat B ne peut pas annuler l\'entretien de A => 404', 404 === $req( 'POST', '/postelio/v1/me/interviews/' . $ivc . '/cancel', array( 'reason' => 'x' ), $candB )['status'] );
$cc = $req( 'POST', '/postelio/v1/me/interviews/' . $ivc . '/cancel', array( 'reason' => 'Empêchement' ), $candA );
$t( 'candidat annule son entretien confirmé => 200 cancelled', 200 === $cc['status'] && 'cancelled' === ( $cc['data']['data']['status'] ?? '' ) );
$t( 'recruteur voit ensuite cancelled', 'cancelled' === ( $req( 'GET', '/postelio/v1/companies/me/interviews/' . $ivc, null, $recA )['data']['data']['status'] ?? '' ) );
$histCc = array_column( (array) ( $req( 'GET', '/postelio/v1/companies/me/interviews/' . $ivc, null, $recA )['data']['data']['history'] ?? array() ), 'actor_role' );
$t( 'historique : annulation par le candidat (actor_role candidate)', in_array( 'candidate', $histCc, true ) );

echo "== Validation payload ==\n";
$badUrl = $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/interviews', array( 'type' => 'video', 'scheduled_at' => $future( 3 ), 'duration_minutes' => 30, 'video_data' => array( 'meeting_url' => 'javascript:alert(1)' ) ), $recA );
$t( 'url visio malveillante refusée => 422', 422 === $badUrl['status'] );
$badDate = $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/interviews', array( 'type' => 'video', 'scheduled_at' => '2000-01-01T10:00:00Z', 'duration_minutes' => 30, 'video_data' => array( 'meeting_url' => 'https://x.example.com/a' ) ), $recA );
$t( 'date passée refusée => 422', 422 === $badDate['status'] );
$badDur = $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/interviews', array( 'type' => 'video', 'scheduled_at' => $future( 3 ), 'duration_minutes' => 3, 'video_data' => array( 'meeting_url' => 'https://x.example.com/a' ) ), $recA );
$t( 'durée absurde refusée => 422', 422 === $badDur['status'] );

echo "== Sécurité / non-divulgation ==\n";
$t( 'candidat B => 404', 404 === $req( 'GET', '/postelio/v1/me/interviews/' . $iv, null, $candB )['status'] );
$t( 'recruteur entreprise B => 404', 404 === $req( 'GET', '/postelio/v1/companies/me/interviews/' . $iv, null, $recB )['status'] );
$t( 'anonyme => 401', 401 === $req( 'GET', '/postelio/v1/me/interviews/' . $iv, null, 0 )['status'] );
$t( 'uuid inconnu => 404', 404 === $req( 'GET', '/postelio/v1/me/interviews/' . wp_generate_uuid4(), null, $candA )['status'] );
$t( 'recruteur B ne peut pas proposer sur candidature A => 404', 404 === $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/interviews', array( 'type' => 'phone', 'scheduled_at' => $future( 3 ), 'duration_minutes' => 30, 'phone_data' => array( 'phone_number' => '0600000000' ) ), $recB )['status'] );
$t( 'candidat ne peut pas proposer (403/404)', in_array( $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/interviews', array( 'type' => 'phone', 'scheduled_at' => $future( 3 ), 'duration_minutes' => 30, 'phone_data' => array( 'phone_number' => '0600000000' ) ), $candA )['status'], array( 401, 403, 404 ), true ) );

echo "== Historique + listes/filtres ==\n";
$det = $req( 'GET', '/postelio/v1/companies/me/interviews/' . $iv, null, $recA )['data']['data'];
$actionsHist = array_column( (array) ( $det['history'] ?? array() ), 'action' );
$t( 'historique contient created + confirmed + completed', in_array( 'created', $actionsHist, true ) && in_array( 'confirmed', $actionsHist, true ) && in_array( 'completed', $actionsHist, true ) );
wp_set_current_user( $candA );
$fr = new WP_REST_Request( 'GET', '/postelio/v1/me/interviews' );
$fr->set_query_params( array( 'status' => 'declined' ) );
$filtered = rest_do_request( $fr )->get_data();
$t( 'filtre status=declined', 1 === (int) ( $filtered['meta']['pagination']['total'] ?? -1 ) );
$listRec = $req( 'GET', '/postelio/v1/companies/me/interviews', null, $recA )['data'];
$t( 'liste recruteur paginée (meta.pagination)', isset( $listRec['meta']['pagination']['total'] ) && (int) $listRec['meta']['pagination']['total'] >= 3 );

echo "== Contrat public InterviewDirectory ==\n";
$ctx = InterviewDirectory::get_context( $iv );
$t( 'get_context renvoie company_name + scheduled_at + type', is_array( $ctx ) && ! empty( $ctx['company_name'] ) && ! empty( $ctx['scheduled_at'] ) && 'video' === $ctx['type'] );
$t( 'get_context ne fuit pas d\'id interne', is_array( $ctx ) && ! isset( $ctx['id'] ) );
$t( 'upcoming_count candidat >= 0', InterviewDirectory::upcoming_count( $candA ) >= 0 );

echo "== §D completed reste manuel (aucun passage automatique) ==\n";
$ivk = (string) $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/interviews', array( 'type' => 'video', 'scheduled_at' => $future( 2 ), 'duration_minutes' => 30, 'video_data' => array( 'meeting_url' => 'https://meet.example.com/keep' ) ), $recA )['data']['data']['uuid'];
$req( 'POST', '/postelio/v1/me/interviews/' . $ivk . '/confirm', null, $candA );
$cron = _get_cron_array(); $hasIvCron = false;
if ( is_array( $cron ) ) { foreach ( $cron as $hooks ) { foreach ( array_keys( (array) $hooks ) as $hook ) { if ( false !== strpos( (string) $hook, 'interview' ) ) { $hasIvCron = true; } } } }
$t( 'aucun cron d\'auto-complétion d\'entretien planifié', false === $hasIvCron );
$t( 'entretien confirmé reste confirmed (pas d\'auto-completed)', 'confirmed' === ( $req( 'GET', '/postelio/v1/companies/me/interviews/' . $ivk, null, $recA )['data']['data']['status'] ?? '' ) );

echo "== §C Offre filled/archived/suspended bloque une NOUVELLE proposition ==\n";
$jRepo = new JobRepository();
foreach ( array( 'filled', 'archived', 'suspended' ) as $st ) {
	$jRepo->set_status( $jid, $st );
	$res = $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/interviews', array( 'type' => 'phone', 'scheduled_at' => $future( 5 ), 'duration_minutes' => 30, 'phone_data' => array( 'phone_number' => '0600000000' ) ), $recA );
	$t( "offre {$st} => nouvelle proposition refusée 409", 409 === $res['status'] );
	$t( "entretien existant toujours consultable (offre {$st})", 200 === $req( 'GET', '/postelio/v1/companies/me/interviews/' . $ivk, null, $recA )['status'] );
}
$t( 'entretien existant encore confirmable/annulable après changement d\'offre', in_array( 'cancel', (array) ( $req( 'GET', '/postelio/v1/me/interviews/' . $ivk, null, $candA )['data']['data']['actions'] ?? array() ), true ) );
$jRepo->set_status( $jid, 'published' ); // on rétablit pour le test candidature terminale

echo "== Candidature terminale bloque la proposition (§32) ==\n";
$appSvc->change_status( $recA, $appU, array( 'to' => 'rejected' ) );
$blocked = $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/interviews', array( 'type' => 'phone', 'scheduled_at' => $future( 3 ), 'duration_minutes' => 30, 'phone_data' => array( 'phone_number' => '0600000000' ) ), $recA );
$t( 'candidature rejected => proposition refusée 409', 409 === $blocked['status'] );

echo "== Events / audit sans données privées ==\n";
foreach ( array( 'interview.proposed', 'interview.confirmed', 'interview.rescheduled', 'interview.cancelled', 'interview.declined', 'interview.completed' ) as $ev ) {
	$t( "audit contient {$ev}", (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE action = %s", $ev ) ) >= 1 );
}
$leak = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit} WHERE metadata LIKE '%meet.example.com%' OR metadata LIKE '%rue de la Paix%' OR metadata LIKE '%12 34 56%'" );
$t( 'aucune coordonnée privée (url/adresse/tel) dans l\'audit', 0 === $leak );

echo "== Nettoyage ==\n";
$ids_in = implode( ',', array_map( 'intval', $companies ?: array( 0 ) ) );
$iv_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}postelio_interviews WHERE company_id IN ({$ids_in})" );
if ( $iv_ids ) {
	$in = implode( ',', array_map( 'intval', $iv_ids ) );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_interview_history WHERE interview_id IN ({$in})" );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_interviews WHERE id IN ({$in})" );
}
$ap = $wpdb->prefix . 'postelio_applications';
$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_application_history WHERE application_id IN (SELECT id FROM {$ap} WHERE company_id IN ({$ids_in}))" );
$wpdb->query( "DELETE FROM {$ap} WHERE company_id IN ({$ids_in})" );
foreach ( $jobs as $jid2 ) { wp_delete_post( $jid2, true ); }
foreach ( $companies as $cid ) { ( new MembershipRepository() )->remove_all_for_company( $cid ); wp_delete_post( $cid, true ); }
foreach ( $users as $u ) { ( new CandidateProfileRepository() )->delete_for( $u ); ( new RecruiterProfileRepository() )->delete_for( $u ); wp_delete_user( $u ); }
$wpdb->query( "DELETE FROM {$audit} WHERE resource_type IN ('interview','application','job','company') OR action LIKE 'user.%'" );
echo "  entretiens + historique + candidatures + offres + entreprises + comptes supprimés\n";

echo "\n";
if ( empty( $fail ) ) { WP_CLI::success( "Smoke interviews OK : {$pass} vérifications passées." ); }
else { WP_CLI::error( count( $fail ) . ' échec(s) : ' . implode( ' | ', $fail ) ); }
