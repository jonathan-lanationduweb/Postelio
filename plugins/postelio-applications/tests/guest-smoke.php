<?php
/**
 * Smoke test du parcours candidature GUEST (sans compte) — double opt-in.
 *
 * Couvre : upload CV guest, validations de soumission (présélection obligatoire, consentement),
 * soumission 202 générique, non-visibilité recruteur avant confirmation, absence d'accès par
 * UUID seul, e-mail de confirmation (jeton signé), confirmation → matérialisation (compte
 * invité + candidature réelle), ré-attribution du CV, jeton consommé, rattachement à un compte
 * existant, dédoublonnage, rate limiting, purge RGPD. Vérifie Mailpit si disponible.
 *
 * @package Postelio\Applications\Tests
 */

use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Companies\Verification\ManualVerificationProvider;
use Postelio\Companies\Verification\Siren;
use Postelio\Companies\Verification\VerificationService;
use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Users\Api\GuestOnboarding;
use Postelio\Users\Profiles\CandidateProfileRepository;
use Postelio\Users\Profiles\RecruiterProfileRepository;
use Postelio\Users\Users\AccountService;
use Postelio\Users\Verification\EmailVerification;

if ( ! defined( 'ABSPATH' ) ) { echo "WP-CLI requis.\n"; exit( 1 ); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
add_filter( 'postelio/require_email_verification', '__return_false' );

$fail = array(); $pass = 0;
$t = static function ( string $l, bool $c ) use ( &$fail, &$pass ): void {
	if ( $c ) { ++$pass; echo "  [ok]   {$l}\n"; } else { $fail[] = $l; echo "  [FAIL] {$l}\n"; }
};
$rt = wp_generate_password( 8, false );
// Isolation du rate limiting (IP unique par run).
add_filter( 'postelio/auth/client_ip', static function () use ( $rt ) { return 'guestsmoke.' . $rt; } );

// Capture du message de confirmation (récupère le jeton) + relais vers Mailpit si dispo.
$GLOBALS['pst_cap'] = array();
add_filter( 'postelio/notifications/email_provider', static function () {
	return new class() implements \Postelio\Notifications\Email\EmailProvider {
		public function name(): string { return 'capture'; }
		public function send( \Postelio\Notifications\Email\EmailMessage $m ): \Postelio\Notifications\Email\DeliveryResult {
			$GLOBALS['pst_cap'] = array( 'to' => $m->to, 'subject' => $m->subject, 'cta' => $m->cta_url, 'body' => $m->body_text );
			@wp_mail( $m->to, $m->subject, $m->body_text ); // relais Mailpit (best-effort)
			return \Postelio\Notifications\Email\DeliveryResult::success( 'capture' );
		}
	};
} );

$req = static function ( string $m, string $route, ?array $body = null, int $user = 0 ): array {
	wp_set_current_user( $user );
	$r = new WP_REST_Request( $m, $route );
	if ( null !== $body ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
	$resp = rest_do_request( $r );
	return array( 'status' => $resp->get_status(), 'data' => $resp->get_data() );
};
$reqfile = static function ( string $route, array $file, int $user = 0 ): array {
	wp_set_current_user( $user );
	$r = new WP_REST_Request( 'POST', $route );
	$r->set_file_params( array( 'file' => $file ) );
	$resp = rest_do_request( $r );
	return array( 'status' => $resp->get_status(), 'data' => $resp->get_data() );
};

global $wpdb;
$acc  = new AccountService( new CandidateProfileRepository(), new RecruiterProfileRepository() );
$siren = static function (): string { $s = (string) wp_rand( 100000000, 999999998 ); while ( ! Siren::is_valid_siren( $s ) ) { $s = str_pad( (string) ( ( (int) $s ) + 1 ), 9, '0', STR_PAD_LEFT ); } return $s; };
$jrepo = new JobRepository();
$created_users = array(); $created_jobs = array(); $created_companies = array();

echo "== Activation / schéma ==\n";
$t( 'plugin applications actif', is_plugin_active( 'postelio-applications/postelio-applications.php' ) );
$gtbl = $wpdb->prefix . 'postelio_guest_applications';
$t( "table {$gtbl} existe", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $gtbl ) ) === $gtbl );
$t( 'schema applications = 2', (string) get_option( 'postelio_applications_schema' ) === '2' );

echo "== Préparation (recruteur + entreprise vérifiée + offre publiée) ==\n";
$rec  = $acc->register( array( 'email' => 'g.rec.' . $rt . '@postelio.test', 'password' => 'motdepasse123', 'role' => 'recruiter' ) );
$created_users[] = $rec;
$c = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'Guest Co ' . $rt, 'legal' => array( 'siren' => $siren(), 'raison_sociale' => 'Guest Co' ) ), $rec );
$cid = CompanyDirectory::id_from_uuid( (string) $c['data']['data']['uuid'] );
$created_companies[] = $cid;
$svc = new VerificationService( new CompanyRepository(), new ManualVerificationProvider() );
$svc->request( $cid, 1 ); $svc->decide( $cid, 1, 'verified' );
$questions = array( array( 'id' => 'permis', 'label' => 'Permis B ?', 'type' => 'oui_non', 'required' => true ) );
$j = $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'Poste Guest', 'description' => 'Description neutre et correcte.', 'ville' => 'Lyon', 'contrat' => 'CDI', 'questions_preselection' => $questions ), $rec );
$juuid = (string) $j['data']['data']['uuid'];
$req( 'POST', '/postelio/v1/jobs/' . $juuid . '/publish', null, $rec );
$jid = (int) $jrepo->get_by_uuid( $juuid )['id']; $created_jobs[] = $jid;
$t( 'offre publiée', 'published' === (string) $jrepo->get_by_uuid( $juuid )['status'] );

echo "== Upload CV guest (public) ==\n";
$pdf  = "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<<>>\n%%EOF\n";
$tmpp = wp_tempnam( 'pst_gcv_' ); file_put_contents( $tmpp, $pdf );
$cvfile = array( 'tmp_name' => $tmpp, 'name' => 'CV.pdf', 'size' => filesize( $tmpp ), 'error' => UPLOAD_ERR_OK );
$cvres  = $reqfile( '/postelio/v1/guest/files/cv', $cvfile );
$t( 'upload CV guest => 201 + cv_reference', 201 === $cvres['status'] && ! empty( $cvres['data']['data']['cv_reference'] ) );
$t( 'réponse CV guest n\'expose ni storage_key ni chemin', ! isset( $cvres['data']['data']['storage_key'], $cvres['data']['data']['storage_path'], $cvres['data']['data']['path'] ) );
$cv_uuid = (string) ( $cvres['data']['data']['cv_reference'] ?? '' );

echo "== Soumission guest : validations ==\n";
$email = 'guest.' . $rt . '@postelio.test';
$base  = array( 'first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => $email, 'consent' => true, 'cv_reference' => $cv_uuid );
$t( 'présélection obligatoire manquante => 422', 422 === $req( 'POST', '/postelio/v1/jobs/' . $juuid . '/guest-applications', array_merge( $base, array( 'screening_answers' => array() ) ) )['status'] );
$t( 'consentement manquant => 422', 422 === $req( 'POST', '/postelio/v1/jobs/' . $juuid . '/guest-applications', array_merge( $base, array( 'consent' => false, 'screening_answers' => array( 'permis' => 'oui' ) ) ) )['status'] );
$t( 'e-mail invalide => 422', 422 === $req( 'POST', '/postelio/v1/jobs/' . $juuid . '/guest-applications', array_merge( $base, array( 'email' => 'pas-un-email', 'screening_answers' => array( 'permis' => 'oui' ) ) ) )['status'] );

echo "== Soumission guest valide (double opt-in) ==\n";
$r = $req( 'POST', '/postelio/v1/jobs/' . $juuid . '/guest-applications', array_merge( $base, array( 'screening_answers' => array( 'permis' => 'oui' ), 'message' => 'Bonjour' ) ) );
$t( 'candidature guest => 202 générique', 202 === $r['status'] && true === ( $r['data']['data']['submitted'] ?? false ) );
$t( 'réponse ne contient NI jeton NI uuid', ! isset( $r['data']['data']['token'], $r['data']['data']['uuid'] ) );
$grow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$gtbl} WHERE job_id=%d AND email=%s ORDER BY id DESC LIMIT 1", $jid, $email ), ARRAY_A );
$t( 'candidature guest en base = pending_email', $grow && 'pending_email' === $grow['status'] );
$t( 'jeton stocké HACHÉ (jamais en clair)', $grow && 64 === strlen( (string) $grow['token_hash'] ) );
$t( 'consentement horodaté (RGPD)', $grow && '0000-00-00 00:00:00' !== (string) $grow['consent_at'] );
$t( 'recruteur ne voit AUCUNE candidature réelle avant confirmation', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}postelio_applications WHERE job_id=%d", $jid ) ) );

echo "== E-mail de confirmation + jeton ==\n";
$cap = $GLOBALS['pst_cap'];
parse_str( (string) wp_parse_url( (string) ( $cap['cta'] ?? '' ), PHP_URL_QUERY ), $q );
$guest_uuid = (string) $grow['public_uuid'];
$token      = (string) ( $q['token'] ?? '' );
$t( 'e-mail de confirmation émis au bon destinataire', ( $cap['to'] ?? '' ) === $email );
$t( 'sujet = confirmation de candidature', false !== strpos( (string) ( $cap['subject'] ?? '' ), 'Confirmez' ) );
$t( 'lien porte uuid + token', (string) ( $q['uuid'] ?? '' ) === $guest_uuid && '' !== $token );

echo "== Pas d'accès par UUID seul ==\n";
$t( 'confirm sans jeton => 422', 422 === $req( 'POST', '/postelio/v1/applications/guest/confirm', array( 'uuid' => $guest_uuid, 'token' => '' ) )['status'] );
$t( 'confirm avec UUID + mauvais jeton => 409 (générique)', 409 === $req( 'POST', '/postelio/v1/applications/guest/confirm', array( 'uuid' => $guest_uuid, 'token' => 'mauvais-jeton' ) )['status'] );

echo "== Confirmation => matérialisation (compte invité) ==\n";
$conf = $req( 'POST', '/postelio/v1/applications/guest/confirm', array( 'uuid' => $guest_uuid, 'token' => $token ) );
$t( 'confirmation => 200 confirmed', 200 === $conf['status'] && true === ( $conf['data']['data']['confirmed'] ?? false ) );
$t( 'compte = invited (nouveau candidat)', 'invited' === ( $conf['data']['data']['account'] ?? '' ) );
$t( 'lien de réclamation (définir mot de passe) fourni', ! empty( $conf['data']['data']['claim_url'] ) );
$uid = GuestOnboarding::find_candidate( $email );
$created_users[] = $uid;
$t( 'compte candidat invité créé', $uid > 0 );
$t( 'compte marqué invité (non réclamé)', GuestOnboarding::is_invited( $uid ) );
$t( 'e-mail vérifié par le double opt-in', EmailVerification::is_verified( $uid ) );
$t( 'candidature RÉELLE créée + visible recruteur', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}postelio_applications WHERE job_id=%d AND candidate_user_id=%d", $jid, $uid ) ) );
$cvrow = $wpdb->get_row( $wpdb->prepare( "SELECT owner_user_id FROM {$wpdb->prefix}postelio_files WHERE public_uuid=%s", $cv_uuid ), ARRAY_A );
$t( 'CV guest ré-attribué au nouveau compte', $cvrow && (int) $cvrow['owner_user_id'] === $uid );
$t( 'candidature guest marquée linked', 'linked' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$gtbl} WHERE public_uuid=%s", $guest_uuid ) ) );
$t( 'jeton consommé : re-confirmation => 409', 409 === $req( 'POST', '/postelio/v1/applications/guest/confirm', array( 'uuid' => $guest_uuid, 'token' => $token ) )['status'] );

echo "== Rattachement à un compte EXISTANT ==\n";
$em_ex = 'exist.' . $rt . '@postelio.test';
$ex_id = $acc->register( array( 'email' => $em_ex, 'password' => 'motdepasse123', 'role' => 'candidate' ) );
$created_users[] = $ex_id;
$j2 = $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'Poste 2', 'description' => 'Autre offre correcte.', 'ville' => 'Paris', 'contrat' => 'CDI', 'questions_preselection' => array() ), $rec );
$juuid2 = (string) $j2['data']['data']['uuid'];
$req( 'POST', '/postelio/v1/jobs/' . $juuid2 . '/publish', null, $rec );
$jid2 = (int) $jrepo->get_by_uuid( $juuid2 )['id']; $created_jobs[] = $jid2;
$users_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
$req( 'POST', '/postelio/v1/jobs/' . $juuid2 . '/guest-applications', array( 'first_name' => 'Exi', 'last_name' => 'Stant', 'email' => $em_ex, 'consent' => true, 'screening_answers' => array() ) );
$g2   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$gtbl} WHERE job_id=%d AND email=%s", $jid2, $em_ex ), ARRAY_A );
parse_str( (string) wp_parse_url( (string) ( $GLOBALS['pst_cap']['cta'] ?? '' ), PHP_URL_QUERY ), $q2 );
$conf2 = $req( 'POST', '/postelio/v1/applications/guest/confirm', array( 'uuid' => (string) $g2['public_uuid'], 'token' => (string) ( $q2['token'] ?? '' ) ) );
$t( 'compte existant => account=existing', 'existing' === ( $conf2['data']['data']['account'] ?? '' ) );
$t( 'aucun doublon d\'utilisateur créé', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) === $users_before );
$t( 'candidature rattachée au compte existant', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}postelio_applications WHERE job_id=%d AND candidate_user_id=%d", $jid2, $ex_id ) ) );

echo "== Dédoublonnage (même offre + e-mail) ==\n";
$em_dup = 'dup.' . $rt . '@postelio.test';
$req( 'POST', '/postelio/v1/jobs/' . $juuid2 . '/guest-applications', array( 'first_name' => 'A', 'last_name' => 'B', 'email' => $em_dup, 'consent' => true, 'screening_answers' => array() ) );
$req( 'POST', '/postelio/v1/jobs/' . $juuid2 . '/guest-applications', array( 'first_name' => 'A', 'last_name' => 'B', 'email' => $em_dup, 'consent' => true, 'screening_answers' => array() ) );
$t( 'deux soumissions identiques => une seule ligne guest (renvoi)', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$gtbl} WHERE job_id=%d AND email=%s AND status='pending_email'", $jid2, $em_dup ) ) );

echo "== Rate limiting (anti-spam) ==\n";
$em_rl = 'rl.' . $rt . '@postelio.test';
$codes = array();
for ( $i = 0; $i < 6; $i++ ) {
	$codes[] = $req( 'POST', '/postelio/v1/jobs/' . $juuid2 . '/guest-applications', array( 'first_name' => 'R', 'last_name' => 'L', 'email' => $em_rl, 'consent' => true, 'screening_answers' => array() ) )['status'];
}
$t( 'guest apply : 5 acceptés puis 429 (par IP+e-mail)', 202 === $codes[4] && 429 === $codes[5] );

echo "== Purge RGPD (non confirmées expirées) ==\n";
$wpdb->query( $wpdb->prepare( "UPDATE {$gtbl} SET token_expires=%d WHERE email=%s", time() - 10, $em_dup ) );
$purged = ( new \Postelio\Applications\Applications\GuestApplicationRepository() )->purge_expired( time() );
$t( 'purge supprime les candidatures guest expirées non confirmées', $purged >= 1 && null === $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$gtbl} WHERE email=%s AND status='pending_email'", $em_dup ) ) );

echo "== Mailpit (si disponible) ==\n";
$mp = wp_remote_get( 'http://127.0.0.1:8025/api/v1/search?query=' . rawurlencode( 'to:' . $email ), array( 'timeout' => 3 ) );
if ( ! is_wp_error( $mp ) && 200 === (int) wp_remote_retrieve_response_code( $mp ) ) {
	$body = json_decode( (string) wp_remote_retrieve_body( $mp ), true );
	$t( 'Mailpit a reçu l\'e-mail de confirmation guest', is_array( $body ) && (int) ( $body['messages_count'] ?? count( $body['messages'] ?? array() ) ) >= 1 );
} else {
	echo "  [skip] Mailpit indisponible — vérification de capture déjà couverte plus haut\n";
}

echo "== Nettoyage ==\n";
$wpdb->query( "DELETE FROM {$gtbl} WHERE email LIKE '%" . esc_sql( $rt ) . "%'" );
$ap = $wpdb->prefix . 'postelio_applications';
$ids_in = implode( ',', array_map( 'intval', $created_jobs ?: array( 0 ) ) );
$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_application_history WHERE application_id IN (SELECT id FROM {$ap} WHERE job_id IN ({$ids_in}))" );
$wpdb->query( "DELETE FROM {$ap} WHERE job_id IN ({$ids_in})" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_files WHERE public_uuid='" . esc_sql( $cv_uuid ) . "'" );
foreach ( $created_jobs as $jj ) { wp_delete_post( $jj, true ); }
foreach ( $created_companies as $cc ) { wp_delete_post( $cc, true ); }
foreach ( array_unique( array_filter( $created_users ) ) as $u ) { ( new CandidateProfileRepository() )->delete_for( $u ); ( new RecruiterProfileRepository() )->delete_for( $u ); wp_delete_user( $u ); }
$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_audit_log WHERE action LIKE 'application.%' OR action LIKE 'user.%' OR action LIKE 'company.%' OR action LIKE 'job.%' OR action LIKE 'cv.%'" );
echo "  nettoyé\n";

echo "\n";
if ( empty( $fail ) ) { WP_CLI::success( "Smoke guest-application OK : {$pass} vérifications passées." ); }
else { WP_CLI::error( count( $fail ) . ' échec(s) : ' . implode( ' | ', $fail ) ); }
