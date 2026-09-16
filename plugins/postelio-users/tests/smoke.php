<?php
/**
 * Smoke test postelio-users sur WordPress vivant :
 *
 *   wp eval-file plugins/postelio-users/tests/smoke.php --path=wordpress
 *
 * Exerce l'inscription, la connexion, les profils, les préférences, l'export,
 * la vérification e-mail, l'anonymisation, les permissions et l'auth Bearer,
 * via le routeur REST interne (rest_do_request). Nettoie les comptes créés.
 *
 * @package Postelio\Users\Tests
 */

use Postelio\Core\Permissions\Capabilities;
use Postelio\Core\Plugin as Core;
use Postelio\Users\Api\UserDirectory;
use Postelio\Users\Api\UserModeration;
use Postelio\Users\Auth\TokenAuthenticator;
use Postelio\Users\Auth\TokenService;
use Postelio\Users\Users\AccountService;
use Postelio\Users\Users\AccountStatusGuard;
use Postelio\Users\Verification\EmailVerification;

if ( ! defined( 'ABSPATH' ) ) {
	echo "Doit être exécuté via WP-CLI.\n";
	exit( 1 );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$fail = array();
$pass = 0;
$t    = static function ( string $label, bool $cond ) use ( &$fail, &$pass ): void {
	if ( $cond ) {
		++$pass;
		echo "  [ok]   {$label}\n";
	} else {
		$fail[] = $label;
		echo "  [FAIL] {$label}\n";
	}
};

/**
 * Dispatch REST interne. $body encodé JSON ; $user = ID courant (0 = déconnecté).
 *
 * @return array{status:int, data:mixed}
 */
$req = static function ( string $method, string $route, ?array $body = null, int $user = 0 ): array {
	wp_set_current_user( $user );
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'Content-Type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$resp = rest_do_request( $r );
	return array( 'status' => $resp->get_status(), 'data' => $resp->get_data() );
};

global $wpdb;
$created = array();

echo "== Activation & schéma ==\n";
$t( 'plugin postelio-users actif', is_plugin_active( 'postelio-users/postelio-users.php' ) );
foreach ( array( 'postelio_candidate_profiles', 'postelio_recruiter_profiles' ) as $s ) {
	$table = $wpdb->prefix . $s;
	$t( "table {$table} existe", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
}
$t( 'schema users = 3', (string) get_option( 'postelio_users_schema' ) === '3' );
$t( 'colonne public_uuid présente', (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}postelio_candidate_profiles LIKE %s", 'public_uuid' ) ) );
$t( 'module users dans le registry', Core::instance()->registry()->has( 'users' ) );

echo "== Inscription ==\n";
$em_cand = 'smoke.cand.' . wp_generate_password( 6, false ) . '@postelio.test';
$em_rec  = 'smoke.rec.' . wp_generate_password( 6, false ) . '@postelio.test';

$r = $req( 'POST', '/postelio/v1/auth/register', array( 'email' => $em_cand, 'password' => 'motdepasse123', 'role' => 'candidate' ) );
$t( 'register candidat => 201', 201 === $r['status'] );
$cand_id = (int) ( $r['data']['data']['user']['id'] ?? 0 );
$cand_token = (string) ( $r['data']['data']['token'] ?? '' );
$created[] = $cand_id;
$t( 'register renvoie un token', '' !== $cand_token );
$t( 'candidat a le rôle postelio_candidate', user_can( $cand_id, 'pst_apply_job' ) );

$r = $req( 'POST', '/postelio/v1/auth/register', array( 'email' => $em_rec, 'password' => 'motdepasse123', 'role' => 'recruiter' ) );
$t( 'register recruteur => 201', 201 === $r['status'] );
$rec_id    = (int) ( $r['data']['data']['user']['id'] ?? 0 );
$created[] = $rec_id;

// Doublon + validation.
$r = $req( 'POST', '/postelio/v1/auth/register', array( 'email' => $em_cand, 'password' => 'motdepasse123', 'role' => 'candidate' ) );
$t( 'register doublon => 409 conflict', 409 === $r['status'] && 'conflict' === ( $r['data']['error']['code'] ?? '' ) );
$r = $req( 'POST', '/postelio/v1/auth/register', array( 'email' => 'x', 'password' => '123', 'role' => 'candidate' ) );
$t( 'register invalide => 422 validation_error', 422 === $r['status'] && 'validation_error' === ( $r['data']['error']['code'] ?? '' ) );

echo "== Connexion ==\n";
$r = $req( 'POST', '/postelio/v1/auth', array( 'email' => $em_cand, 'password' => 'motdepasse123' ) );
$t( 'login OK => 200 + token', 200 === $r['status'] && ! empty( $r['data']['data']['token'] ) );
$r = $req( 'POST', '/postelio/v1/auth', array( 'email' => $em_cand, 'password' => 'FAUX' ) );
$t( 'login mauvais mdp => 401', 401 === $r['status'] && 'unauthenticated' === ( $r['data']['error']['code'] ?? '' ) );

echo "== /me enrichi ==\n";
$r = $req( 'GET', '/postelio/v1/me', null, $cand_id );
$t( '/me => 200', 200 === $r['status'] );
$t( '/me enrichi: role=candidate', 'candidate' === ( $r['data']['data']['role'] ?? '' ) );
$t( '/me enrichi: capabilities présentes', in_array( 'pst_apply_job', (array) ( $r['data']['data']['capabilities'] ?? array() ), true ) );
$t( '/me enrichi: bloc settings', isset( $r['data']['data']['settings']['langue'] ) );
$r = $req( 'GET', '/postelio/v1/me', null, 0 );
$t( '/me déconnecté => 401', 401 === $r['status'] );

echo "== Profil candidat (self) ==\n";
$r = $req( 'GET', '/postelio/v1/candidates/me/profile', null, $cand_id );
$t( 'GET profil candidat => 200', 200 === $r['status'] );
$cand_uuid = (string) ( $r['data']['data']['public_uuid'] ?? '' );
$t( 'profil expose un public_uuid (v4)', (bool) preg_match( '/^[0-9a-f-]{36}$/i', $cand_uuid ) );
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}postelio_audit_log WHERE action='candidate.profile_updated'" );
$r = $req( 'PUT', '/postelio/v1/candidates/me/profile', array( 'metier' => 'Développeur', 'ville' => 'Lyon', 'a_propos' => 'Bonjour' ), $cand_id );
$t( 'PUT profil candidat => 200', 200 === $r['status'] );
$t( 'PUT persiste metier', 'Développeur' === ( $r['data']['data']['metier'] ?? '' ) );
$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}postelio_audit_log WHERE action='candidate.profile_updated'" );
$t( 'événement candidate.profile_updated audité', $after === $before + 1 );

echo "== Permissions ==\n";
$r = $req( 'GET', '/postelio/v1/recruiters/me/profile', null, $cand_id );
$t( 'candidat sur /recruiters/me/profile => 403', 403 === $r['status'] );
$r = $req( 'GET', '/postelio/v1/candidates/' . $cand_uuid, null, $cand_id );
$t( 'candidat sur vue recruteur => 403', 403 === $r['status'] );
$r = $req( 'GET', '/postelio/v1/recruiters/me/profile', null, $rec_id );
$t( 'recruteur sur son profil => 200', 200 === $r['status'] );

echo "== M1 — inscription non vérifiée par défaut ==\n";
$t( 'recruteur fraîchement inscrit => e-mail NON vérifié', ! EmailVerification::is_verified( $rec_id ) );
$t( 'recruteur non vérifié => pas de pst_email_verified', ! user_can( $rec_id, 'pst_email_verified' ) );
$r = $req( 'GET', '/postelio/v1/candidates/' . $cand_uuid, null, $rec_id );
$t( 'M1 : recruteur NON vérifié sur vue candidat => 403', 403 === $r['status'] );

echo "== H2 — vue recruteur d'un candidat (autorisation fine) ==\n";
// On vérifie l'e-mail du recruteur pour dépasser le cadre M1 et isoler H2 lui-même.
update_user_meta( $rec_id, AccountService::META_EMAIL_VERIFIED, current_time( 'mysql', true ) );
clean_user_cache( $rec_id );
$r = $req( 'GET', '/postelio/v1/candidates/' . $cand_uuid, null, $rec_id );
$t( 'H2 : recruteur vérifié SANS entreprise => 404 (le rôle seul ne suffit pas)', 404 === $r['status'] );
$r = $req( 'GET', '/postelio/v1/candidates/' . wp_generate_uuid4(), null, $rec_id );
$t( 'UUID inconnu => 404', 404 === $r['status'] );
// Chemins autorisés (entreprise réelle, visibilités masque/candidatees/recruteurs,
// blocked_companies prioritaire) : couverts par le smoke postelio-applications.

echo "== Préférences ==\n";
$r = $req( 'PUT', '/postelio/v1/me/settings', array( 'langue' => 'en', 'notifications' => array( 'conseils' => true ) ), $cand_id );
$t( 'PUT settings => 200 + langue en', 200 === $r['status'] && 'en' === ( $r['data']['data']['langue'] ?? '' ) );

echo "== Export RGPD ==\n";
$r = $req( 'GET', '/postelio/v1/me/export', null, $cand_id );
$t( 'export => 200 + account+profile', 200 === $r['status'] && isset( $r['data']['data']['account'], $r['data']['data']['profile'] ) );

echo "== Vérification e-mail (mécanisme) ==\n";
$svc  = new AccountService(
	new \Postelio\Users\Profiles\CandidateProfileRepository(),
	new \Postelio\Users\Profiles\RecruiterProfileRepository()
);
$tok  = $svc->issue_email_verification( $cand_id );
$t( 'verify avec mauvais jeton => false', false === $svc->verify_email( $cand_id, 'faux' ) );
$t( 'verify avec bon jeton => true', true === $svc->verify_email( $cand_id, $tok ) );
$t( 'is_email_verified => true', $svc->is_email_verified( $cand_id ) );

echo "== Auth Bearer (app/Tauri) ==\n";
$auth = new TokenAuthenticator( new TokenService() );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $cand_token;
$t( 'Bearer valide résout le candidat', $cand_id === $auth->authenticate( false ) );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer 7.faux.faux';
$t( 'Bearer invalide => valeur entrante (false)', false === $auth->authenticate( false ) );
unset( $_SERVER['HTTP_AUTHORIZATION'] );

echo "== Capability virtuelle pst_email_verified (contrat D12) ==\n";
$t( 'candidat vérifié => a pst_email_verified', user_can( $cand_id, 'pst_email_verified' ) );
delete_user_meta( $rec_id, AccountService::META_EMAIL_VERIFIED );
clean_user_cache( $rec_id );
$t( 'recruteur non vérifié => n\'a PAS pst_email_verified', ! user_can( $rec_id, 'pst_email_verified' ) );

echo "== H1 — étanchéité de la suspension (auth native + capabilities) ==\n";
$admin_id = (int) ( get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) )[0] ?? 1 );
$em_susp  = 'smoke.susp.' . wp_generate_password( 6, false ) . '@postelio.test';
$r        = $req( 'POST', '/postelio/v1/auth/register', array( 'email' => $em_susp, 'password' => 'motdepasse123', 'role' => 'recruiter' ) );
$susp_id  = (int) ( $r['data']['data']['user']['id'] ?? 0 );
$created[] = $susp_id;
// Actif : la connexion native fonctionne et la capability métier est présente.
$t( 'actif : wp_authenticate (wp-login/REST/XML-RPC) OK', wp_authenticate( $em_susp, 'motdepasse123' ) instanceof WP_User );
$t( 'actif : possède pst_view_company_applications', user_can( $susp_id, 'pst_view_company_applications' ) );
// Suspension via le contrat réel (révoque jetons + sessions), sans toucher au rôle.
UserModeration::suspend( UserDirectory::public_uuid( $susp_id ), $admin_id );
clean_user_cache( $susp_id );
$t( 'suspendu : statut = suspended', AccountService::STATUS_SUSPENDED === AccountService::status( $susp_id ) );
$t( 'suspendu : rôle recruteur CONSERVÉ (statut ≠ rôle)', in_array( Capabilities::ROLE_RECRUITER, (array) get_userdata( $susp_id )->roles, true ) );
// Couche 1 — aucune nouvelle session native même avec le bon mot de passe.
$auth_susp = wp_authenticate( $em_susp, 'motdepasse123' );
$t( 'suspendu : wp_authenticate REFUSÉ (bon mdp)', is_wp_error( $auth_susp ) && AccountStatusGuard::ERROR_CODE === $auth_susp->get_error_code() );
$t( 'suspendu : POST /auth => 403 Compte indisponible', 403 === $req( 'POST', '/postelio/v1/auth', array( 'email' => $em_susp, 'password' => 'motdepasse123' ) )['status'] );
// Couche 2 — une session/cookie/Bearer déjà établi perd toutes les capabilities métier.
$t( 'suspendu : perd pst_view_company_applications', ! user_can( $susp_id, 'pst_view_company_applications' ) );
$t( 'suspendu : perd pst_email_verified', ! user_can( $susp_id, 'pst_email_verified' ) );
wp_set_current_user( $susp_id );
$t( 'suspendu : current_user_can(pst_send_message) => false', ! current_user_can( 'pst_send_message' ) );
wp_set_current_user( 0 );
$t( 'suspendu : capabilities WP natives conservées (read)', user_can( $susp_id, 'read' ) );
// Réactivation : accès normal restauré immédiatement, sans réparer le rôle.
UserModeration::unsuspend( UserDirectory::public_uuid( $susp_id ), $admin_id );
clean_user_cache( $susp_id );
$t( 'réactivé : statut = active', AccountService::STATUS_ACTIVE === AccountService::status( $susp_id ) );
$t( 'réactivé : retrouve pst_view_company_applications', user_can( $susp_id, 'pst_view_company_applications' ) );
$t( 'réactivé : wp_authenticate de nouveau OK', wp_authenticate( $em_susp, 'motdepasse123' ) instanceof WP_User );

echo "== Révocation de toutes les sessions (/auth/logout-all) ==\n";
$svc_tok  = new TokenService();
$sess_tok = $svc_tok->issue( $cand_id );
$t( 'jeton frais valide', $cand_id === $svc_tok->validate( $sess_tok['token'] ) );
$r = $req( 'POST', '/postelio/v1/auth/logout-all', null, $cand_id );
$t( 'logout-all => 200 revoked_all', 200 === $r['status'] && true === ( $r['data']['data']['revoked_all'] ?? false ) );
$t( 'tous les jetons révoqués après logout-all', 0 === $svc_tok->validate( $sess_tok['token'] ) );

echo "== lost-password (anti-énumération) ==\n";
$r = $req( 'POST', '/postelio/v1/auth/lost-password', array( 'email' => $em_cand ) );
$t( 'lost-password email connu => 200 sent', 200 === $r['status'] && true === ( $r['data']['data']['sent'] ?? false ) );
$r = $req( 'POST', '/postelio/v1/auth/lost-password', array( 'email' => 'inconnu@nope.test' ) );
$t( 'lost-password email inconnu => 200 (pas d\'énumération)', 200 === $r['status'] );

echo "== Suppression/anonymisation (préparée) ==\n";
$em_tmp = 'smoke.del.' . wp_generate_password( 6, false ) . '@postelio.test';
$r      = $req( 'POST', '/postelio/v1/auth/register', array( 'email' => $em_tmp, 'password' => 'motdepasse123', 'role' => 'candidate' ) );
$tmp_id    = (int) ( $r['data']['data']['user']['id'] ?? 0 );
$tmp_token = (string) ( $r['data']['data']['token'] ?? '' );
$created[] = $tmp_id;

$r = $req( 'DELETE', '/postelio/v1/me', null, $tmp_id );
$t( 'DELETE /me => 200 deleted', 200 === $r['status'] && true === ( $r['data']['data']['deleted'] ?? false ) );
$t( 'statut = deleted', AccountService::status( $tmp_id ) === AccountService::STATUS_DELETED );
$t( 'e-mail anonymisé', false !== strpos( (string) get_userdata( $tmp_id )->user_email, '@postelio.invalid' ) );
$t( 'profil candidat purgé', null === ( new \Postelio\Users\Profiles\CandidateProfileRepository() )->get_by_user( $tmp_id ) );
$t( 'jetons révoqués après suppression', 0 === ( new TokenService() )->validate( $tmp_token ) );
// Connexion impossible après suppression (statut deleted + mot de passe réinitialisé).
$r = $req( 'POST', '/postelio/v1/auth', array( 'email' => $em_tmp, 'password' => 'motdepasse123' ) );
$t( 'connexion impossible après suppression (401/403)', in_array( $r['status'], array( 401, 403 ), true ) );

echo "== Nettoyage ==\n";
foreach ( array_unique( array_filter( $created ) ) as $uid ) {
	( new \Postelio\Users\Profiles\CandidateProfileRepository() )->delete_for( $uid );
	( new \Postelio\Users\Profiles\RecruiterProfileRepository() )->delete_for( $uid );
	wp_delete_user( $uid );
}
$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_audit_log WHERE action IN ('user.created','user.deleted','candidate.profile_updated','user.updated','plugin.registered')" );
echo "  comptes de test supprimés\n";

echo "\n";
if ( empty( $fail ) ) {
	WP_CLI::success( "Smoke users OK : {$pass} vérifications passées." );
} else {
	WP_CLI::error( count( $fail ) . ' échec(s) : ' . implode( ' | ', $fail ) );
}
