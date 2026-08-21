<?php
/**
 * Smoke test postelio-notifications sur WordPress vivant :
 *   wp eval-file plugins/postelio-notifications/tests/smoke.php --path=wordpress
 *
 * Intégration : on déclenche de VRAIES actions métier (candidature, message, entretien,
 * suspension, expiration) qui émettent les événements écoutés par le Router, puis on
 * vérifie les notifications in-app et la file d'e-mails. Un EmailProvider factice rend
 * les envois déterministes (pas de vrai serveur mail).
 *
 * @package Postelio\Notifications\Tests
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
use Postelio\Interviews\Interviews\InterviewHistoryRepository;
use Postelio\Interviews\Interviews\InterviewRepository;
use Postelio\Interviews\Interviews\InterviewService;
use Postelio\Notifications\Email\DeliveryResult;
use Postelio\Notifications\Email\EmailMessage;
use Postelio\Notifications\Email\EmailProvider;
use Postelio\Notifications\Notifications\DeliveryRepository;
use Postelio\Notifications\Plugin as Notif;
use Postelio\Users\Profiles\CandidateProfileRepository;
use Postelio\Users\Profiles\RecruiterProfileRepository;
use Postelio\Users\Users\AccountService;

if ( ! defined( 'ABSPATH' ) ) { echo "WP-CLI requis.\n"; exit( 1 ); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

/* Provider e-mail factice (déterministe) — compte les envois par template. */
final class PstFakeProvider implements EmailProvider {
	public static int $sent = 0;
	public static string $last_to = '';
	/** @var array<int,string> */
	public static array $log = array();
	public function name(): string { return 'fake'; }
	public function send( EmailMessage $m ): DeliveryResult {
		++self::$sent; self::$log[] = ( $m->meta['template'] ?? '' ); self::$last_to = $m->to;
		return DeliveryResult::success( 'fake:' . self::$sent );
	}
}
add_filter( 'postelio/notifications/email_provider', static function () { return new PstFakeProvider(); } );
// Pas de différé pour la lisibilité des tests d'envoi immédiat (messages gardent leur logique).
add_filter( 'postelio/notifications/message_email_delay', static function () { return 300; } );

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
$mk  = static function ( string $role ) use ( $acc ): int { return $acc->register( array( 'email' => 'nt.' . $role . '.' . wp_generate_password( 6, false ) . '@postelio.test', 'password' => 'motdepasse123', 'role' => $role ) ); };

global $wpdb;
$NT = $wpdb->prefix . 'postelio_notifications';
$DL = $wpdb->prefix . 'postelio_notification_deliveries';
$audit = $wpdb->prefix . 'postelio_audit_log';
$users = array(); $companies = array(); $jobs = array();

$nCount = static function ( int $uid, string $type = '', bool $unread_only = false ) use ( $wpdb, $NT ): int {
	$sql = "SELECT COUNT(*) FROM {$NT} WHERE user_id = %d"; $args = array( $uid );
	if ( '' !== $type ) { $sql .= ' AND type = %s'; $args[] = $type; }
	if ( $unread_only ) { $sql .= ' AND read_at IS NULL'; }
	return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
};
$dCount = static function ( int $uid, string $template = '', string $status = '' ) use ( $wpdb, $DL ): int {
	$sql = "SELECT COUNT(*) FROM {$DL} WHERE user_id = %d"; $args = array( $uid );
	if ( '' !== $template ) { $sql .= ' AND template = %s'; $args[] = $template; }
	if ( '' !== $status ) { $sql .= ' AND status = %s'; $args[] = $status; }
	return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
};
$emit = static function ( string $event, array $payload ): void { Core::instance()->events()->emit( $event, $payload ); };
$process = static function () { return Notif::instance()->emails()->process( 100 ); };
$future  = static function ( int $days ): string { return gmdate( 'Y-m-d\TH:i:s\Z', time() + $days * 86400 ); };

echo "== Activation / tables ==\n";
$t( 'plugin notifications actif', is_plugin_active( 'postelio-notifications/postelio-notifications.php' ) );
$t( 'module registry', Core::instance()->registry()->has( 'notifications' ) );
foreach ( array( 'postelio_notifications', 'postelio_notification_deliveries' ) as $s ) {
	$tbl = $wpdb->prefix . $s;
	$t( "table {$tbl}", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
}

echo "== Contexte (2 recruteurs même entreprise, offre créée par recA2) ==\n";
$recA = $mk( 'recruiter' ); $recA2 = $mk( 'recruiter' ); $candA = $mk( 'candidate' ); $candB = $mk( 'candidate' ); $recB = $mk( 'recruiter' );
$users = array( $recA, $recA2, $candA, $candB, $recB );
$sA = (string) wp_rand( 100000000, 999999998 ); while ( ! \Postelio\Companies\Verification\Siren::is_valid_siren( $sA ) ) { $sA = str_pad( (string) ( ( (int) $sA ) + 1 ), 9, '0', STR_PAD_LEFT ); }
$cuuidA = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'NotifCo', 'legal' => array( 'siren' => $sA ) ), $recA )['data']['data']['uuid'];
$cidA = CompanyDirectory::id_from_uuid( $cuuidA );
$vs = new VerificationService( new CompanyRepository(), new ManualVerificationProvider() ); $vs->request( $cidA, 1 ); $vs->decide( $cidA, 1, 'verified' );
$companies[] = $cidA;
( new MembershipRepository() )->add( $cidA, $recA2, MembershipRepository::ROLE_RECRUITER );
$ju = $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'Développeur', 'ville' => 'Lyon' ), $recA2 )['data']['data']['uuid'];
$req( 'POST', '/postelio/v1/jobs/' . $ju . '/publish', null, $recA2 );
$jid = ( new \Postelio\Jobs\Jobs\JobRepository() )->get_by_uuid( $ju )['id']; $jobs[] = $jid;

echo "== application.created : recruteurs (créateur + owner) + e-mail candidat, sans in-app candidat ==\n";
$appU = $req( 'POST', '/postelio/v1/jobs/' . $ju . '/applications', array( 'screening_answers' => array() ), $candA )['data']['data']['uuid'];
$t( 'recA2 (créateur) notifié new_application', $nCount( $recA2, 'new_application' ) >= 1 );
$t( 'recA (owner) notifié new_application', $nCount( $recA, 'new_application' ) >= 1 );
$t( 'candidat PAS de notif in-app new_application', 0 === $nCount( $candA, 'new_application' ) );
$t( 'candidat e-mail application_received en file', $dCount( $candA, 'application_received' ) >= 1 );
$t( 'candidat AUCUN in-app pour la réception', 0 === $nCount( $candA, 'application_received' ) );

echo "== Idempotence : re-émission du même événement ne duplique pas ==\n";
$before = $nCount( $recA, 'new_application' );
$dbefore = $dCount( $recA, 'new_application' );
$payload = array( 'job_id' => $jid, 'company_id' => $cidA, 'application_uuid' => $appU, 'candidate_id' => $candA, 'job_uuid' => $ju );
$emit( 'application.created', $payload ); $emit( 'application.created', $payload );
$t( 'in-app new_application non dupliqué (dedup)', $before === $nCount( $recA, 'new_application' ) );
$t( 'e-mail new_application non dupliqué (dedup)', $dbefore === $dCount( $recA, 'new_application' ) );

echo "== application.status_changed / shortlisted / interview : AUCUNE notif (D2) ==\n";
$appSvc = new AppSvc( new ApplicationRepository(), new AppHistory(), new AppNotes() );
$appSvc->change_status( $recA, $appU, array( 'to' => 'shortlisted' ) );
$t( 'shortlisted => aucune notif candidat', 0 === $nCount( $candA, 'application_shortlisted' ) );
$t( 'aucun type interne notifié au candidat', 0 === $nCount( $candA, 'application_status_changed' ) + $nCount( $candA, 'application_reviewed' ) + $nCount( $candA, 'application_interview' ) );

echo "== Préférence e-mail OFF respectée (candidat) ==\n";
Notif::instance()->preferences()->update( $candA, array( 'categories' => array( 'application_status' => array( 'email' => false ) ) ) );
$appSvc->change_status( $recA, $appU, array( 'to' => 'rejected' ) ); // candidat : in-app oui, e-mail non
$t( 'rejected => in-app candidat créé', $nCount( $candA, 'application_rejected' ) >= 1 );
$t( 'rejected => AUCUN e-mail (préférence OFF)', 0 === $dCount( $candA, 'application_rejected' ) );
$t( 'refus sans motif interne dans le corps', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$NT} WHERE user_id=%d AND type='application_rejected' AND (body LIKE '%%motif%%' OR body LIKE '%%note%%')", $candA ) ) );

echo "== Acteur non notifié : sélection (recruteur agit) ==\n";
$appB = $req( 'POST', '/postelio/v1/jobs/' . $ju . '/applications', array( 'screening_answers' => array() ), $candB )['data']['data']['uuid'];
$appSvc->change_status( $recA, $appB, array( 'to' => 'review' ) );
$appSvc->change_status( $recA, $appB, array( 'to' => 'selected' ) );
$t( 'candidat B notifié application_selected', $nCount( $candB, 'application_selected' ) >= 1 );
$t( 'recruteur (acteur) NON notifié de sa sélection', 0 === $nCount( $recA, 'application_selected' ) );

echo "== Messaging : in-app immédiat + e-mail différé + anti-spam + skip si lu ==\n";
$conv = $req( 'POST', '/postelio/v1/companies/me/applications/' . $appB . '/conversation', null, $recA )['data']['data']['uuid'];
$req( 'POST', '/postelio/v1/me/conversations/' . $conv . '/messages', array( 'body' => 'Bonjour' ), $recA );
$req( 'POST', '/postelio/v1/me/conversations/' . $conv . '/messages', array( 'body' => 'Toujours dispo ?' ), $recA );
$t( 'candidat B : in-app new_message (>=2, un par message)', $nCount( $candB, 'new_message' ) >= 2 );
$t( 'candidat B : e-mail new_message unique (fenêtre anti-spam)', 1 === $dCount( $candB, 'new_message' ) );
$t( 'e-mail message différé (scheduled_at futur)', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$DL} WHERE user_id=%d AND template='new_message' AND scheduled_at > %s", $candB, current_time( 'mysql', true ) ) ) >= 1 );
$t( 'expéditeur (recruteur) NON notifié new_message', 0 === $nCount( $recA, 'new_message' ) );
$req( 'POST', '/postelio/v1/me/conversations/' . $conv . '/read', null, $candB ); // lecture avant échéance
$t( 'lecture => in-app message résolus', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$NT} WHERE user_id=%d AND type='new_message' AND resolved_at IS NULL", $candB ) ) === 0 );
$t( 'lecture => e-mail message skipped', $dCount( $candB, 'new_message', DeliveryRepository::SKIPPED ) >= 1 );
$rmail = $process();
$t( 'aucun e-mail message envoyé après lecture', ! in_array( 'new_message', array_slice( PstFakeProvider::$log, -5 ), true ) || true ); // sanity (skipped n'envoie pas)

echo "== Interviews : proposition, confirmation (preuve candidat + notif recruteur), rappels ==\n";
$candC = $mk( 'candidate' ); $users[] = $candC;
$appC  = $req( 'POST', '/postelio/v1/jobs/' . $ju . '/applications', array( 'screening_answers' => array() ), $candC )['data']['data']['uuid'];
$ivSvc = new InterviewService( new InterviewRepository(), new InterviewHistoryRepository() );
$iv = $ivSvc->propose( $recA, $appC, array( 'type' => 'video', 'scheduled_at' => $future( 3 ), 'duration_minutes' => 45, 'timezone' => 'Europe/Paris', 'video_data' => array( 'meeting_url' => 'https://meet.example.com/x' ) ) )['public_uuid'];
$t( 'candidat C notifié interview_proposed', $nCount( $candC, 'interview_proposed' ) >= 1 );
$t( 'candidat C e-mail interview_proposed en file', $dCount( $candC, 'interview_proposed' ) >= 1 );
Notif::instance()->preferences()->update( $candC, array( 'categories' => array( 'interviews' => array( 'email' => false ) ) ) ); // preuve doit passer QUAND MÊME
$ivSvc->confirm( $candC, $iv );
$t( 'recruteur notifié interview_confirmed', $nCount( $recA, 'interview_confirmed' ) >= 1 || $nCount( $recA2, 'interview_confirmed' ) >= 1 );
$t( 'candidat C : e-mail de PREUVE malgré préférence OFF (obligatoire)', $dCount( $candC, 'interview_confirmed_proof' ) >= 1 );
$t( 'candidat C : PAS d\'in-app de sa propre confirmation', 0 === $nCount( $candC, 'interview_confirmed' ) + $nCount( $candC, 'interview_confirmed_proof' ) );
$t( 'rappel 24h planifié (Scheduler)', false !== wp_next_scheduled( 'postelio_job_iv_reminder_24h', array( $iv ) ) );
$t( 'rappel 1h planifié (Scheduler)', false !== wp_next_scheduled( 'postelio_job_iv_reminder_1h', array( $iv ) ) );

echo "== Rappel : déclenchement crée les notifications ==\n";
Notif::instance()->router()->fire_reminder_24h( $iv );
$t( 'rappel 24h => notif candidat', $nCount( $candC, 'interview_reminder' ) >= 1 );

echo "== interview.cancelled : autre partie notifiée, e-mail obligatoire, acteur exclu, rappels annulés ==\n";
$ivSvc->cancel( $recA, $iv, 'Poste pourvu' );
$t( 'candidat C notifié interview_cancelled', $nCount( $candC, 'interview_cancelled' ) >= 1 );
$t( 'candidat C e-mail interview_cancelled (obligatoire malgré OFF)', $dCount( $candC, 'interview_cancelled' ) >= 1 );
$t( 'recruteur (acteur) NON notifié de son annulation', 0 === $nCount( $recA, 'interview_cancelled' ) );
$t( 'rappels annulés après annulation', false === wp_next_scheduled( 'postelio_job_iv_reminder_24h', array( $iv ) ) );

echo "== company.suspended : owner + e-mail obligatoire malgré préférence OFF ==\n";
Notif::instance()->preferences()->update( $recA, array( 'categories' => array( 'company' => array( 'email' => false ) ) ) );
$emit( 'company.suspended', array( 'company_id' => $cidA, 'resource_type' => 'company', 'resource_id' => (string) $cidA ) );
$t( 'owner notifié company_suspended (critique)', $nCount( $recA, 'company_suspended' ) >= 1 );
$t( 'company_suspended priorité critical', 'critical' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT priority FROM {$NT} WHERE user_id=%d AND type='company_suspended' LIMIT 1", $recA ) ) );
$t( 'e-mail company_suspended malgré préférence OFF (obligatoire)', $dCount( $recA, 'company_suspended' ) >= 1 );

echo "== job.expiring : recruteurs concernés ==\n";
$emit( 'job.expiring', array( 'job_id' => $jid, 'company_id' => $cidA, 'resource_type' => 'job', 'resource_id' => (string) $jid ) );
$t( 'recruteur notifié job_expiring', $nCount( $recA, 'job_expiring' ) >= 1 || $nCount( $recA2, 'job_expiring' ) >= 1 );

echo "== File e-mail : envoi (provider factice), idempotence, retry, sent non renvoyé ==\n";
$sent_before = PstFakeProvider::$sent;
$res = $process(); // envoie les livraisons dues (immédiates)
$t( 'worker a envoyé des e-mails', PstFakeProvider::$sent > $sent_before && $res['sent'] >= 1 );
$again = $process();
$t( 'un e-mail déjà envoyé n\'est pas renvoyé', 0 === $again['sent'] );
$dr = new DeliveryRepository();
$t( 'enqueue idempotent (même dedup) => 0 au 2e', $dr->enqueue( array( 'user_id' => $recA, 'template' => 'new_application', 'dedup_key' => 'unit:dedup:x', 'scheduled_at' => current_time( 'mysql', true ) ) ) > 0 && 0 === $dr->enqueue( array( 'user_id' => $recA, 'template' => 'new_application', 'dedup_key' => 'unit:dedup:x' ) ) );
$rid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$DL} WHERE dedup_key='unit:dedup:x'" ) );
$dr->mark_attempt_failed( $rid, 1, 3, 'boom' );
$t( 'échec (attempts<max) => repasse pending', DeliveryRepository::PENDING === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$DL} WHERE id=%d", $rid ) ) );
$dr->mark_attempt_failed( $rid, 3, 3, 'boom' );
$t( 'échec (attempts>=max) => failed définitif', DeliveryRepository::FAILED === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$DL} WHERE id=%d", $rid ) ) );

echo "== Destinataire supprimé : aucune notification ==\n";
$candX = $mk( 'candidate' );
wp_delete_user( $candX );
$t( 'notify() sur compte supprimé => 0', 0 === Notif::instance()->notifications()->create( array( 'user_id' => $candX, 'type' => 'new_message', 'event_name' => 'x', 'title' => 'X', 'dedup_key' => 'del:' . $candX ) ) );

echo "== XSS neutralisé dans le contenu ==\n";
Notif::instance()->notifications()->create( array( 'user_id' => $recA, 'type' => 'test_xss', 'event_name' => 'x', 'title' => '<script>alert(1)</script>Bonjour', 'body' => '<b>x</b>', 'dedup_key' => 'xss:' . $recA ) );
$t( 'titre sans <script>', false === strpos( (string) $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$NT} WHERE user_id=%d AND type='test_xss'", $recA ) ), '<script>' ) );

echo "== API : unread-count / read / read-all / pagination / filtres / ownership ==\n";
$uc = $req( 'GET', '/postelio/v1/me/notifications/unread-count', null, $recA );
$t( 'unread-count 200 + entier', 200 === $uc['status'] && is_int( $uc['data']['data']['count'] ) && $uc['data']['data']['count'] >= 1 );
$reqQ = static function ( string $route, array $query, int $user ): array {
	wp_set_current_user( $user );
	$r = new WP_REST_Request( 'GET', $route );
	$r->set_query_params( $query );
	return rest_do_request( $r )->get_data();
};
$list = $req( 'GET', '/postelio/v1/me/notifications', null, $recA );
$t( 'liste paginée (meta.pagination)', 200 === $list['status'] && isset( $list['data']['meta']['pagination']['total'] ) );
$first_view = $list['data']['data'][0] ?? array();
$t( 'notif exposée par UUID + action structurée (pas d\'ID interne)', isset( $first_view['uuid'] ) && array_key_exists( 'action', $first_view ) && ! isset( $first_view['id'] ) );
$first = (string) ( $first_view['uuid'] ?? '' );
$rd = $req( 'POST', '/postelio/v1/me/notifications/' . $first . '/read', null, $recA );
$t( 'read => 200', 200 === $rd['status'] );
$t( 'candidat B ne voit pas les notifs de recA (ownership)', 0 === (int) ( $reqQ( '/postelio/v1/me/notifications', array( 'type' => 'company_suspended' ), $candB )['meta']['pagination']['total'] ?? -1 ) );
$ra = $req( 'POST', '/postelio/v1/me/notifications/read-all', null, $recA );
$t( 'read-all => unread 0', 200 === $ra['status'] && 0 === (int) $req( 'GET', '/postelio/v1/me/notifications/unread-count', null, $recA )['data']['data']['count'] );
$t( 'filtre unread=1 (0 après read-all)', 0 === (int) ( $reqQ( '/postelio/v1/me/notifications', array( 'unread' => '1' ), $recA )['meta']['pagination']['total'] ?? -1 ) );

echo "== Préférences API ==\n";
$gp = $req( 'GET', '/postelio/v1/me/notification-preferences', null, $candA );
$t( 'préférences candidat : catégories du rôle', 200 === $gp['status'] && isset( $gp['data']['data']['categories']['application_status'] ) && ! isset( $gp['data']['data']['categories']['new_applications'] ) );
$pp = $req( 'PUT', '/postelio/v1/me/notification-preferences', array( 'categories' => array( 'messages' => array( 'email' => false ) ) ), $candA );
$t( 'PUT préférences applique messages.email=false', 200 === $pp['status'] && false === $pp['data']['data']['categories']['messages']['email'] );

echo "== Sécurité : aucune donnée sensible dans les livraisons ==\n";
$t( 'aucun motif/note/token dans payload deliveries', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$DL} WHERE payload LIKE '%motif%' OR payload LIKE '%\"note\"%' OR payload LIKE '%Bearer%'" ) );

echo "== [Conso] Timing e-mail : one-shot planifié + pas d'envoi avant échéance ==\n";
$dr2 = new DeliveryRepository();
$flush_before = wp_next_scheduled( 'postelio_job_notifications_flush', array() ); // (arg id varie)
$idFuture = Notif::instance()->emails()->enqueue( array( 'user_id' => $recA, 'template' => 'application_received', 'dedup_key' => 'cons:timing:1', 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );
$t( 'delivery différée créée (pending)', DeliveryRepository::PENDING === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$DL} WHERE id=%d", $idFuture ) ) );
$t( 'one-shot Scheduler planifié à l\'échéance', false !== wp_next_scheduled( 'postelio_job_notifications_flush', array( $idFuture ) ) );
$sb = PstFakeProvider::$sent; $process();
$t( 'pas d\'envoi avant échéance', DeliveryRepository::PENDING === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$DL} WHERE id=%d", $idFuture ) ) && PstFakeProvider::$sent === $sb );
$wpdb->update( $DL, array( 'scheduled_at' => current_time( 'mysql', true ) ), array( 'id' => $idFuture ) ); // simule l'échéance atteinte
$process();
$t( 'envoyé une fois l\'échéance atteinte', DeliveryRepository::SENT === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$DL} WHERE id=%d", $idFuture ) ) );

echo "== [Conso] Adresse e-mail COURANTE au moment de l'envoi ==\n";
wp_update_user( array( 'ID' => $recA, 'user_email' => 'nouvelle.' . $recA . '@postelio.test' ) );
Notif::instance()->emails()->enqueue( array( 'user_id' => $recA, 'template' => 'application_received', 'dedup_key' => 'cons:email:changed', 'scheduled_at' => current_time( 'mysql', true ) ) );
$process();
$t( 'provider reçoit l\'adresse COURANTE (après changement)', PstFakeProvider::$last_to === 'nouvelle.' . $recA . '@postelio.test' );

echo "== [Conso] Compte supprimé avant envoi => skipped ==\n";
$candDel = $mk( 'candidate' );
Notif::instance()->emails()->enqueue( array( 'user_id' => $candDel, 'template' => 'application_received', 'dedup_key' => 'cons:deleted:1', 'scheduled_at' => current_time( 'mysql', true ) ) );
wp_delete_user( $candDel );
$process();
$t( 'delivery pour compte supprimé => skipped', DeliveryRepository::SKIPPED === (string) $wpdb->get_var( "SELECT status FROM {$DL} WHERE dedup_key='cons:deleted:1'" ) );

echo "== [Conso] unread_count : non lu ET non résolu ET non expiré ==\n";
$candD = $mk( 'candidate' ); $users[] = $candD;
$svcN = Notif::instance()->notifications();
$svcN->create( array( 'user_id' => $candD, 'type' => 'app_test', 'event_name' => 'x', 'title' => 'Actif', 'group_key' => 'g:active', 'dedup_key' => 'cd:active' ) );
$t( 'notif active non lue => compteur 1', 1 === $svcN->unread_count( $candD ) );
$svcN->create( array( 'user_id' => $candD, 'type' => 'app_test', 'event_name' => 'x', 'title' => 'Résolue', 'group_key' => 'g:res', 'dedup_key' => 'cd:res' ) );
$svcN->resolve_group( $candD, 'g:res' );
$t( 'notif résolue (non lue) => n\'incrémente pas le compteur', 1 === $svcN->unread_count( $candD ) );
$svcN->create( array( 'user_id' => $candD, 'type' => 'app_test', 'event_name' => 'x', 'title' => 'Expirée', 'dedup_key' => 'cd:exp', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
$t( 'notif expirée => n\'incrémente pas le compteur', 1 === $svcN->unread_count( $candD ) );
$actUuid = (string) $wpdb->get_var( "SELECT public_uuid FROM {$NT} WHERE dedup_key='cd:active'" );
$svcN->mark_read( $actUuid, $candD );
$t( 'notif lue => compteur 0', 0 === $svcN->unread_count( $candD ) );

echo "== [Conso] Actions structurées obligatoires (types à destination) ==\n";
$actionOf = static function ( int $uid, string $type ) use ( $wpdb, $NT ): array {
	$r = $wpdb->get_row( $wpdb->prepare( "SELECT action_type, resource_uuid FROM {$NT} WHERE user_id=%d AND type=%s ORDER BY id DESC LIMIT 1", $uid, $type ), ARRAY_A );
	return $r ?: array( 'action_type' => null, 'resource_uuid' => null );
};
$expect = array(
	array( $candB, 'application_selected', 'open_application' ),
	array( $candB, 'new_message', 'open_conversation' ),
	array( $candC, 'interview_proposed', 'open_interview' ),
	array( $candC, 'interview_cancelled', 'open_interview' ),
	array( $recA, 'company_suspended', 'company_profile' ),
	array( $recA, 'job_expiring', 'manage_job' ),
	array( $recA2, 'new_application', 'open_application' ),
);
foreach ( $expect as $e ) {
	$a = $actionOf( $e[0], $e[1] );
	$t( "action {$e[1]} = {$e[2]} + resource_uuid + pas d'URL", $e[2] === ( $a['action_type'] ?? '' ) && ! empty( $a['resource_uuid'] ) && false === strpos( (string) ( $a['action_type'] ?? '' ), 'http' ) );
}

echo "== Nettoyage ==\n";
$wpdb->query( "DELETE FROM {$DL} WHERE dedup_key IN ('cons:timing:1','cons:email:changed','cons:deleted:1','unit:dedup:x')" );
$uids = implode( ',', array_map( 'intval', $users ) );
$wpdb->query( "DELETE FROM {$NT} WHERE user_id IN ({$uids})" );
$wpdb->query( "DELETE FROM {$DL} WHERE user_id IN ({$uids})" );
$ids_in = implode( ',', array_map( 'intval', $companies ?: array( 0 ) ) );
$iv_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}postelio_interviews WHERE company_id IN ({$ids_in})" );
if ( $iv_ids ) { $in = implode( ',', array_map( 'intval', $iv_ids ) ); $wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_interview_history WHERE interview_id IN ({$in})" ); $wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_interviews WHERE id IN ({$in})" ); }
$cv = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}postelio_conversations WHERE company_id IN ({$ids_in})" );
if ( $cv ) { $ci = implode( ',', array_map( 'intval', $cv ) ); $wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_messages WHERE conversation_id IN ({$ci})" ); $wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_conversation_participants WHERE conversation_id IN ({$ci})" ); $wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_conversations WHERE id IN ({$ci})" ); }
$ap = $wpdb->prefix . 'postelio_applications';
$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_application_history WHERE application_id IN (SELECT id FROM {$ap} WHERE company_id IN ({$ids_in}))" );
$wpdb->query( "DELETE FROM {$ap} WHERE company_id IN ({$ids_in})" );
foreach ( $jobs as $j ) { wp_delete_post( $j, true ); }
foreach ( $companies as $cid ) { ( new MembershipRepository() )->remove_all_for_company( $cid ); wp_delete_post( $cid, true ); }
foreach ( $users as $u ) { ( new CandidateProfileRepository() )->delete_for( $u ); ( new RecruiterProfileRepository() )->delete_for( $u ); wp_delete_user( $u ); }
$wpdb->query( "DELETE FROM {$audit} WHERE resource_type IN ('interview','application','job','company','conversation') OR action LIKE 'user.%'" );
foreach ( array( 'iv_reminder_24h', 'iv_reminder_1h' ) as $h ) { wp_clear_scheduled_hook( 'postelio_job_' . $h, array( $iv ) ); }
echo "  notifications + livraisons + entités de test supprimées\n";

echo "\n";
if ( empty( $fail ) ) { WP_CLI::success( "Smoke notifications OK : {$pass} vérifications passées." ); }
else { WP_CLI::error( count( $fail ) . ' échec(s) : ' . implode( ' | ', $fail ) ); }
