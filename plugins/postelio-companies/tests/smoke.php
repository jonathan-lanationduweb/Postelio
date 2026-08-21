<?php
/**
 * Smoke test postelio-companies sur WordPress vivant :
 *
 *   wp eval-file plugins/postelio-companies/tests/smoke.php --path=wordpress
 *
 * Couvre : migrations/CPT, création/lecture/màj entreprise, rattachement recruteur
 * (owner + RecruiterProfile.company_id via événement), workflow de vérification,
 * verrou des données légales, matrice de sécurité, UUID/D2, événements/audit.
 * Nettoie les entreprises et comptes créés.
 *
 * @package Postelio\Companies\Tests
 */

use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Companies\Companies\CompanyService;
use Postelio\Companies\Members\MembershipRepository;
use Postelio\Companies\Plugin as CompaniesPlugin;
use Postelio\Core\Plugin as Core;
use Postelio\Users\Profiles\CandidateProfileRepository;
use Postelio\Users\Profiles\RecruiterProfileRepository;
use Postelio\Users\Users\AccountService;

if ( ! defined( 'ABSPATH' ) ) {
	echo "Doit être exécuté via WP-CLI.\n";
	exit( 1 );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$fail = array();
$pass = 0;
$t    = static function ( string $label, bool $cond ) use ( &$fail, &$pass ): void {
	if ( $cond ) { ++$pass; echo "  [ok]   {$label}\n"; }
	else { $fail[] = $label; echo "  [FAIL] {$label}\n"; }
};

/** @return array{status:int, data:mixed} */
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

$accounts = new AccountService( new CandidateProfileRepository(), new RecruiterProfileRepository() );
$mk = static function ( string $role ) use ( $accounts ): int {
	$email = 'smoke.co.' . $role . '.' . wp_generate_password( 6, false ) . '@postelio.test';
	return $accounts->register( array( 'email' => $email, 'password' => 'motdepasse123', 'role' => $role ) );
};

global $wpdb;
$companies = new CompanyRepository();
$members   = new MembershipRepository();
$created_users = array();
$created_posts = array();

echo "== Activation / schéma / CPT ==\n";
$t( 'plugin actif', is_plugin_active( 'postelio-companies/postelio-companies.php' ) );
$t( 'CPT postelio_company enregistré', post_type_exists( 'postelio_company' ) );
$tbl = $wpdb->prefix . 'postelio_company_members';
$t( "table {$tbl} existe", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
$t( 'schema companies = 1', (string) get_option( CompaniesPlugin::SCHEMA_OPTION ) === '1' );
$t( 'module companies dans le registry', Core::instance()->registry()->has( 'companies' ) );

echo "== Comptes de test ==\n";
$recA = $mk( 'recruiter' );
$recB = $mk( 'recruiter' );
$cand = $mk( 'candidate' );
$created_users = array( $recA, $recB, $cand );
$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) );
$admin = (int) ( $admin_ids[0] ?? 1 );
$t( 'admin dispose de pst_verify_company', user_can( $admin, 'pst_verify_company' ) );

echo "== Sécurité : accès création ==\n";
$r = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'X' ), 0 );
$t( 'création non authentifiée => 401', 401 === $r['status'] );
$r = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'X' ), $cand );
$t( 'candidat ne peut créer => 403', 403 === $r['status'] );

echo "== Création entreprise (recruteur A) ==\n";
$payload = array(
	'nom'         => 'Fiduciaire Bellecour',
	'description' => 'Cabinet d\'expertise comptable à Lyon accompagnant TPE et PME.',
	'editorial'   => array( 'secteur' => 'Finance & Comptabilité', 'ville' => 'Lyon', 'effectif' => '10–49', 'email' => 'rh@fb.test', 'avantages' => array( 'Télétravail', 'Mutuelle', 'Formation' ), 'valeurs' => array( 'Rigueur', 'Proximité' ) ),
	'legal'       => array( 'raison_sociale' => 'Fiduciaire Bellecour', 'forme_juridique' => 'SARL', 'siren' => '552100554' ),
);
$r = $req( 'POST', '/postelio/v1/companies', $payload, $recA );
$t( 'création recruteur A => 201', 201 === $r['status'] );
$uuidA = (string) ( $r['data']['data']['uuid'] ?? '' );
$t( 'réponse expose uuid (v4)', (bool) preg_match( '/^[0-9a-f-]{36}$/i', $uuidA ) );
$t( 'réponse n\'expose PAS id interne', ! isset( $r['data']['data']['id'] ) );
$t( 'statut initial unverified', 'unverified' === ( $r['data']['data']['verification']['status'] ?? '' ) );
$compA = $companies->get_by_uuid( $uuidA );
$idA   = (int) $compA['id'];
$created_posts[] = $idA;
$t( 'recruteur A est owner', $members->is_owner( $idA, $recA ) );
$t( 'RecruiterProfile.company_id renseigné via événement', (int) ( ( new RecruiterProfileRepository() )->get_by_user( $recA )['company_id'] ?? 0 ) === $idA );

echo "== Unicité / doublons ==\n";
$r = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'Autre' ), $recA );
$t( 'A déjà rattaché => 409', 409 === $r['status'] );
$r = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'Clone', 'legal' => array( 'siren' => '552100554' ) ), $recB );
$t( 'SIREN en doublon (B) => 409', 409 === $r['status'] );

echo "== Lecture / mise à jour (owner) ==\n";
$r = $req( 'GET', '/postelio/v1/companies/me', null, $recA );
$t( 'GET /companies/me => 200', 200 === $r['status'] );
$t( 'owner view expose completion', isset( $r['data']['data']['completion']['pct'] ) );
$r = $req( 'PUT', '/postelio/v1/companies/me', array( 'editorial' => array( 'ville' => 'Villeurbanne' ) ), $recA );
$t( 'PUT editorial => 200 + persiste', 200 === $r['status'] && 'Villeurbanne' === ( $r['data']['data']['editorial']['ville'] ?? '' ) );

echo "== Sécurité : e-mail non vérifié ==\n";
delete_user_meta( $recB, AccountService::META_EMAIL_VERIFIED );
clean_user_cache( $recB );
$r = $req( 'POST', '/postelio/v1/companies', array( 'nom' => ' NoVerif' ), $recB );
$t( 'recruteur non vérifié (e-mail) ne peut créer => 403', 403 === $r['status'] );
update_user_meta( $recB, AccountService::META_EMAIL_VERIFIED, current_time( 'mysql', true ) );
clean_user_cache( $recB );

echo "== Sécurité : ownership (A ne modifie pas l'entreprise de B) ==\n";
// recB n'a pas d'entreprise -> /companies/me => 404
$r = $req( 'GET', '/postelio/v1/companies/me', null, $recB );
$t( 'recruteur sans entreprise => 404', 404 === $r['status'] );
// Service : un acteur non membre ne peut mettre à jour
$svc = new CompanyService( $companies, new \Postelio\Companies\Members\MembershipService( $members ) );
$forbidden = false;
try { $svc->update( $recB, $idA, array( 'nom' => 'Pirate' ) ); } catch ( \Postelio\Core\ApiError $e ) { $forbidden = ( 'forbidden' === $e->error_code() ); }
$t( 'non-membre ne peut modifier (forbidden)', $forbidden );

echo "== Sécurité : auto-déclaration verified impossible ==\n";
$r = $req( 'PUT', '/postelio/v1/companies/me', array( 'verification' => array( 'status' => 'verified' ) ), $recA );
$t( 'PUT verification ignoré (statut inchangé)', 'unverified' === $companies->get( $idA )['verification']['status'] );

echo "== Vérification : demande (provider manuel) ==\n";
$r = $req( 'POST', '/postelio/v1/companies/me/verification', null, $recA );
$t( 'demande => 200 + manual_review', 200 === $r['status'] && 'manual_review' === ( $r['data']['data']['status'] ?? '' ) );

echo "== Sécurité : SIREN invalide rejeté à l'écriture ==\n";
$r = $req( 'PUT', '/postelio/v1/companies/me', array( 'legal' => array( 'siren' => '111111111' ) ), $recA );
$t( 'SIREN invalide (Luhn) rejeté au PUT => 422', 422 === $r['status'] );
$t( 'SIREN déclaré inchangé (toujours valide)', '552100554' === ( $companies->get( $idA )['legal_declared']['siren'] ?? '' ) );

echo "== Vérification : décision admin ==\n";
$r = $req( 'POST', '/postelio/v1/companies/' . $uuidA . '/verification/decision', array( 'decision' => 'verified' ), $cand );
$t( 'candidat ne peut décider => 403', 403 === $r['status'] );
$r = $req( 'POST', '/postelio/v1/companies/' . wp_generate_uuid4() . '/verification/decision', array( 'decision' => 'verified' ), $admin );
$t( 'décision sur entreprise inexistante => 404', 404 === $r['status'] );
$r = $req( 'POST', '/postelio/v1/companies/' . $uuidA . '/verification/decision', array( 'decision' => 'verified' ), $admin );
$t( 'admin verified => 200 + statut verified', 200 === $r['status'] && 'verified' === ( $r['data']['data']['status'] ?? '' ) );
$compA = $companies->get( $idA );
$t( 'legal_verified figé (raison sociale)', 'Fiduciaire Bellecour' === ( $compA['legal_verified']['raison_sociale'] ?? '' ) );
$t( 'verified_at renseigné', ! empty( $compA['verification']['verified_at'] ) );

echo "== Verrou des données légales après vérification ==\n";
$r = $req( 'PUT', '/postelio/v1/companies/me', array( 'legal' => array( 'raison_sociale' => 'Pirate SARL' ) ), $recA );
$t( 'modif légale après verified => 403', 403 === $r['status'] );
$r = $req( 'PUT', '/postelio/v1/companies/me', array( 'editorial' => array( 'ville' => 'Lyon' ) ), $recA );
$t( 'éditorial reste modifiable après verified => 200', 200 === $r['status'] );

echo "== Vue publique par UUID (D2) ==\n";
$r = $req( 'GET', '/postelio/v1/companies/' . $uuidA, null, 0 );
$t( 'public GET par uuid => 200', 200 === $r['status'] );
$t( 'public: verified=true', true === ( $r['data']['data']['verified'] ?? false ) );
$t( 'public: legal vérifié exposé', 'Fiduciaire Bellecour' === ( $r['data']['data']['legal']['raison_sociale'] ?? '' ) );
$t( 'public: PAS d\'id interne', ! isset( $r['data']['data']['id'] ) );
$r = $req( 'GET', '/postelio/v1/companies/' . wp_generate_uuid4(), null, 0 );
$t( 'uuid inconnu => 404', 404 === $r['status'] );

echo "== Suspension (masquée du public) ==\n";
$r = $req( 'POST', '/postelio/v1/companies/' . $uuidA . '/verification/decision', array( 'decision' => 'suspended', 'motif' => 'test' ), $admin );
$t( 'admin suspend => 200', 200 === $r['status'] );
$r = $req( 'GET', '/postelio/v1/companies/' . $uuidA, null, 0 );
$t( 'entreprise suspendue masquée du public => 404', 404 === $r['status'] );

echo "== Événements / audit ==\n";
$audit = $wpdb->prefix . 'postelio_audit_log';
foreach ( array( 'company.created', 'company.member_added', 'company.verification_requested', 'company.verified', 'company.suspended' ) as $ev ) {
	$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE action = %s AND resource_id = %s", $ev, (string) $idA ) );
	$t( "audit contient {$ev}", $n >= 1 );
}

echo "== Nettoyage ==\n";
foreach ( $created_posts as $pid ) {
	$members->remove_all_for_company( $pid );
	wp_delete_post( $pid, true );
}
foreach ( $created_users as $uid ) {
	( new CandidateProfileRepository() )->delete_for( $uid );
	( new RecruiterProfileRepository() )->delete_for( $uid );
	wp_delete_user( $uid );
}
$wpdb->query( "DELETE FROM {$audit} WHERE resource_type = 'company' OR action IN ('user.created','user.deleted')" );
echo "  entreprises + comptes de test supprimés\n";

echo "\n";
if ( empty( $fail ) ) {
	WP_CLI::success( "Smoke companies OK : {$pass} vérifications passées." );
} else {
	WP_CLI::error( count( $fail ) . ' échec(s) : ' . implode( ' | ', $fail ) );
}
