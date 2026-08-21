<?php
/**
 * Smoke test postelio-messaging sur WordPress vivant :
 *   wp eval-file plugins/postelio-messaging/tests/smoke.php --path=wordpress
 *
 * @package Postelio\Messaging\Tests
 */

use Postelio\Applications\Applications\ApplicationService as AppSvc;
use Postelio\Applications\Applications\ApplicationRepository;
use Postelio\Applications\Applications\HistoryRepository as AppHistory;
use Postelio\Applications\Applications\NoteRepository as AppNotes;
use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Companies\Members\MembershipRepository;
use Postelio\Companies\Verification\ManualVerificationProvider;
use Postelio\Companies\Verification\VerificationService;
use Postelio\Core\Plugin as Core;
use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Messaging\Api\MessagingDirectory;
use Postelio\Messaging\Conversations\ConversationRepository;
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
$mk  = static function ( string $role ) use ( $acc ): int { return $acc->register( array( 'email' => 'sm.' . $role . '.' . wp_generate_password( 6, false ) . '@postelio.test', 'password' => 'motdepasse123', 'role' => $role ) ); };

global $wpdb;
$convRepo = new ConversationRepository();
$audit = $wpdb->prefix . 'postelio_audit_log';
$users = array(); $companies = array(); $jobs = array();

$mkCompany = static function ( int $rec ) use ( $req, &$companies ): array {
	$s = (string) wp_rand( 100000000, 999999998 );
	while ( ! \Postelio\Companies\Verification\Siren::is_valid_siren( $s ) ) { $s = str_pad( (string) ( ( (int) $s ) + 1 ), 9, '0', STR_PAD_LEFT ); }
	$c   = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'Co ' . wp_generate_password( 4, false ), 'legal' => array( 'siren' => $s ) ), $rec );
	$cid = CompanyDirectory::id_from_uuid( $c['data']['data']['uuid'] );
	$vs  = new VerificationService( new CompanyRepository(), new ManualVerificationProvider() ); $vs->request( $cid, 1 ); $vs->decide( $cid, 1, 'verified' );
	$companies[] = $cid; return array( $c['data']['data']['uuid'], $cid );
};

echo "== Activation / tables / registry ==\n";
$t( 'plugin messaging actif', is_plugin_active( 'postelio-messaging/postelio-messaging.php' ) );
$t( 'module messaging registry', Core::instance()->registry()->has( 'messaging' ) );
foreach ( array( 'postelio_conversations', 'postelio_conversation_participants', 'postelio_messages' ) as $s ) {
	$tbl = $wpdb->prefix . $s;
	$t( "table {$tbl}", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
}
$t( 'schema messaging = 1', (string) get_option( 'postelio_messaging_schema' ) === '1' );

echo "== Contexte (entreprise A, candidat, offre, candidature) ==\n";
$recA = $mk( 'recruiter' ); $recB = $mk( 'recruiter' ); $candA = $mk( 'candidate' ); $candB = $mk( 'candidate' );
$users = array( $recA, $recB, $candA, $candB );
list( $cuuidA, $cidA ) = $mkCompany( $recA );
list( $cuuidB, $cidB ) = $mkCompany( $recB );
$j  = $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'Développeur', 'ville' => 'Lyon' ), $recA ); $ju = $j['data']['data']['uuid'];
$req( 'POST', '/postelio/v1/jobs/' . $ju . '/publish', null, $recA );
$jid = ( new JobRepository() )->get_by_uuid( $ju )['id']; $jobs[] = $jid;
$appR = $req( 'POST', '/postelio/v1/jobs/' . $ju . '/applications', array( 'screening_answers' => array() ), $candA );
$appU = $appR['data']['data']['uuid'];
$t( 'candidature créée', 201 === $appR['status'] );

echo "== Ouverture de conversation (recruteur, depuis la candidature) ==\n";
$op = $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/conversation', null, $recA );
$t( 'open => 201', 201 === $op['status'] );
$conv = (string) ( $op['data']['data']['uuid'] ?? '' );
$t( 'conversation a un uuid, pas d\'id interne', preg_match( '/^[0-9a-f-]{36}$/i', $conv ) && ! isset( $op['data']['data']['id'] ) );
$t( 'sujet = titre de l\'offre', 'Développeur' === ( $op['data']['data']['subject'] ?? '' ) );

echo "== Concurrence : 1 conversation par candidature ==\n";
$op2 = $req( 'POST', '/postelio/v1/companies/me/applications/' . $appU . '/conversation', null, $recA );
$t( 'seconde ouverture => même conversation', ( $op2['data']['data']['uuid'] ?? '' ) === $conv );
$appId = (int) ( new ApplicationRepository() )->get_by_uuid( $appU )['id'];
$cnt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}postelio_conversations WHERE application_id = %d", $appId ) );
$t( 'une seule conversation en base', 1 === $cnt );

echo "== Envoi recruteur → non-lu candidat ==\n";
$snd = $req( 'POST', '/postelio/v1/me/conversations/' . $conv . '/messages', array( 'body' => 'Bonjour, êtes-vous disponible ?', 'sender_user_id' => 999999 ), $recA );
$t( 'envoi recruteur => 201', 201 === $snd['status'] );
$t( 'sender ignoré du body (rôle recruteur)', 'recruiter' === ( $snd['data']['data']['sender_role'] ?? '' ) );
$listCand = $req( 'GET', '/postelio/v1/me/conversations', null, $candA )['data']['data'];
$myConv = null; foreach ( (array) $listCand as $c ) { if ( $c['uuid'] === $conv ) { $myConv = $c; } }
$t( 'candidat voit la conversation', null !== $myConv );
$t( 'candidat unread_count = 1', $myConv && 1 === (int) $myConv['unread_count'] );
$t( 'interlocuteur candidat = entreprise', $myConv && 'company' === ( $myConv['interlocutor']['type'] ?? '' ) );

echo "== Lecture candidat + read ==\n";
$msgs = $req( 'GET', '/postelio/v1/me/conversations/' . $conv . '/messages', null, $candA );
$t( 'candidat lit les messages (1)', 200 === $msgs['status'] && count( (array) $msgs['data']['data'] ) === 1 );
$t( 'message pas is_mine côté candidat', false === ( $msgs['data']['data'][0]['is_mine'] ?? true ) );
$rd = $req( 'POST', '/postelio/v1/me/conversations/' . $conv . '/read', null, $candA );
$t( 'read => unread 0', 0 === (int) ( $rd['data']['data']['unread_count'] ?? -1 ) );

echo "== Réponse candidat → non-lu recruteur ==\n";
$rep = $req( 'POST', '/postelio/v1/me/conversations/' . $conv . '/messages', array( 'body' => 'Oui, disponible dès lundi.' ), $candA );
$t( 'réponse candidat => 201 (rôle candidate)', 201 === $rep['status'] && 'candidate' === ( $rep['data']['data']['sender_role'] ?? '' ) );
$listRec = $req( 'GET', '/postelio/v1/me/conversations', null, $recA )['data']['data'];
$recConv = null; foreach ( (array) $listRec as $c ) { if ( $c['uuid'] === $conv ) { $recConv = $c; } }
$t( 'recruteur unread_count = 1', $recConv && 1 === (int) $recConv['unread_count'] );
$t( 'interlocuteur recruteur = candidat', $recConv && 'candidate' === ( $recConv['interlocutor']['type'] ?? '' ) );
$req( 'POST', '/postelio/v1/me/conversations/' . $conv . '/read', null, $recA );
$recConv2 = null; foreach ( (array) $req( 'GET', '/postelio/v1/me/conversations', null, $recA )['data']['data'] as $c ) { if ( $c['uuid'] === $conv ) { $recConv2 = $c; } }
$t( 'recruteur unread après lecture = 0', $recConv2 && 0 === (int) $recConv2['unread_count'] );
$t( 'MessagingDirectory::unread_count recruteur = 0', 0 === MessagingDirectory::unread_count( $recA ) );

echo "== Historique + ordre ==\n";
$hist = $req( 'GET', '/postelio/v1/me/conversations/' . $conv . '/messages', null, $recA )['data']['data'];
$t( 'historique = 2 messages', count( (array) $hist ) === 2 );
$t( 'ordre chronologique (recruteur puis candidat)', ( $hist[0]['sender_role'] ?? '' ) === 'recruiter' && ( $hist[1]['sender_role'] ?? '' ) === 'candidate' );

echo "== XSS neutralisé ==\n";
$xss = $req( 'POST', '/postelio/v1/me/conversations/' . $conv . '/messages', array( 'body' => '<script>alert(1)</script>Rappel SECRETXSS' ), $recA );
$t( 'message XSS accepté', 201 === $xss['status'] );
$t( 'pas de <script> renvoyé', false === strpos( (string) ( $xss['data']['data']['body'] ?? '' ), '<script>' ) );
$t( 'texte conservé', false !== strpos( (string) ( $xss['data']['data']['body'] ?? '' ), 'SECRETXSS' ) );

echo "== Sécurité (non-divulgation) ==\n";
$t( 'candidat B => 404', 404 === $req( 'GET', '/postelio/v1/me/conversations/' . $conv, null, $candB )['status'] );
$t( 'recruteur entreprise B => 404', 404 === $req( 'GET', '/postelio/v1/me/conversations/' . $conv, null, $recB )['status'] );
$t( 'anonyme => 401', 401 === $req( 'GET', '/postelio/v1/me/conversations/' . $conv, null, 0 )['status'] );
$t( 'uuid inconnu => 404', 404 === $req( 'GET', '/postelio/v1/me/conversations/' . wp_generate_uuid4(), null, $candA )['status'] );
$t( 'candidat B ne peut pas écrire => 404', 404 === $req( 'POST', '/postelio/v1/me/conversations/' . $conv . '/messages', array( 'body' => 'intrusion' ), $candB )['status'] );

echo "== Audit sans body ==\n";
$leak = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit} WHERE metadata LIKE '%SECRETXSS%' OR metadata LIKE '%disponible%'" );
$t( 'aucun body de message dans l\'audit', 0 === $leak );
foreach ( array( 'conversation.created', 'message.created', 'conversation.read' ) as $ev ) {
	$t( "audit contient {$ev}", (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE action = %s", $ev ) ) >= 1 );
}

echo "== Fermeture + envoi refusé ==\n";
$cl = $req( 'POST', '/postelio/v1/me/conversations/' . $conv . '/close', null, $recA );
$t( 'close => 200 status closed', 200 === $cl['status'] && 'closed' === ( $cl['data']['data']['status'] ?? '' ) );
$t( 'envoi après fermeture => 409', 409 === $req( 'POST', '/postelio/v1/me/conversations/' . $conv . '/messages', array( 'body' => 'après fermeture' ), $recA )['status'] );
$t( 'historique toujours lisible après fermeture', 200 === $req( 'GET', '/postelio/v1/me/conversations/' . $conv . '/messages', null, $candA )['status'] );
$t( 'audit contient conversation.closed', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit} WHERE action = 'conversation.closed'" ) >= 1 );

echo "== Historique préservé après workflow (offre pourvue + candidature sélectionnée) ==\n";
( new JobRepository() )->set_status( $jid, 'filled' );
$appSvc = new AppSvc( new ApplicationRepository(), new AppHistory(), new AppNotes() );
$appSvc->change_status( $recA, $appU, array( 'to' => 'review' ) );
$appSvc->change_status( $recA, $appU, array( 'to' => 'selected' ) );
$t( 'conversation consultable malgré offre pourvue + sélection (candidat)', 200 === $req( 'GET', '/postelio/v1/me/conversations/' . $conv, null, $candA )['status'] );
$t( 'conversation consultable (recruteur)', 200 === $req( 'GET', '/postelio/v1/me/conversations/' . $conv, null, $recA )['status'] );

echo "== Nettoyage ==\n";
$ids_in = implode( ',', array_map( 'intval', $companies ?: array( 0 ) ) );
$conv_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}postelio_conversations WHERE company_id IN ({$ids_in})" );
if ( $conv_ids ) {
	$ci = implode( ',', array_map( 'intval', $conv_ids ) );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_messages WHERE conversation_id IN ({$ci})" );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_conversation_participants WHERE conversation_id IN ({$ci})" );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_conversations WHERE id IN ({$ci})" );
}
$ap = $wpdb->prefix . 'postelio_applications';
$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_application_history WHERE application_id IN (SELECT id FROM {$ap} WHERE company_id IN ({$ids_in}))" );
$wpdb->query( "DELETE FROM {$ap} WHERE company_id IN ({$ids_in})" );
foreach ( $jobs as $jid2 ) { wp_delete_post( $jid2, true ); }
foreach ( $companies as $cid ) { ( new MembershipRepository() )->remove_all_for_company( $cid ); wp_delete_post( $cid, true ); }
foreach ( $users as $u ) { ( new CandidateProfileRepository() )->delete_for( $u ); ( new RecruiterProfileRepository() )->delete_for( $u ); wp_delete_user( $u ); }
$wpdb->query( "DELETE FROM {$audit} WHERE resource_type IN ('conversation','application','job','company') OR action LIKE 'user.%'" );
echo "  conversations + candidatures + offres + entreprises + comptes supprimés\n";

echo "\n";
if ( empty( $fail ) ) { WP_CLI::success( "Smoke messaging OK : {$pass} vérifications passées." ); }
else { WP_CLI::error( count( $fail ) . ' échec(s) : ' . implode( ' | ', $fail ) ); }
