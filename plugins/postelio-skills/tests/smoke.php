<?php
/**
 * Smoke test postelio-skills sur WordPress vivant.
 *
 *   wp eval-file plugins/postelio-skills/tests/smoke.php --path=wordpress
 *
 * Couvre : activation/CPT/taxonomies/table ; création/édition/publication (low/medium/high) ;
 * pas de pending ; ownership candidat A/B & entreprise A/B ; as_company + anti-spoofing ;
 * liste/détail public + non-exposition + SEO ; révision ; hide/unhide (SkillModeration) ;
 * report ; commentaires (low/medium/high + rate-limit + suspendu) ; suspension user/entreprise
 * avec CAUSE DISTINCTE ; compte supprimé. Nettoie tout.
 *
 * @package Postelio\Skills\Tests
 */

use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Companies\Api\CompanyModeration;
use Postelio\Companies\Verification\Siren;
use Postelio\Core\Plugin as Core;
use Postelio\Skills\Api\SkillModeration;
use Postelio\Skills\Cpt\SkillPostType;
use Postelio\Skills\Skills\SkillRepository;
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
	$q = array();
	if ( false !== strpos( $route, '?' ) ) { list( $route, $qs ) = explode( '?', $route, 2 ); parse_str( $qs, $q ); }
	$r = new WP_REST_Request( $m, $route );
	if ( $q ) { $r->set_query_params( $q ); }
	if ( null !== $body ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
	$resp = rest_do_request( $r );
	return array( 'status' => $resp->get_status(), 'data' => $resp->get_data() );
};
$accounts = new AccountService( new CandidateProfileRepository(), new RecruiterProfileRepository() );
$mk = static function ( string $role ) use ( $accounts ): int {
	return $accounts->register( array( 'email' => 'smoke.sk.' . $role . '.' . wp_generate_password( 6, false ) . '@postelio.test', 'password' => 'motdepasse123', 'role' => $role ) );
};

global $wpdb;
$repo   = new SkillRepository();
$audit  = $wpdb->prefix . 'postelio_audit_log';
$cases  = $wpdb->prefix . 'postelio_moderation_cases';
$comments_tbl = $wpdb->prefix . 'postelio_skill_comments';
$skills_created = array(); $users = array(); $company_id = 0; $company_b = 0;

echo "== Activation / CPT / taxonomies / table ==\n";
$t( 'plugin actif', is_plugin_active( 'postelio-skills/postelio-skills.php' ) );
$t( 'module registry', Core::instance()->registry()->has( 'skills' ) );
$t( 'CPT + taxonomies', post_type_exists( 'postelio_skill' ) && taxonomy_exists( SkillPostType::TAX_CAT ) && taxonomy_exists( SkillPostType::TAX_TAG ) );
$t( 'table commentaires', (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$comments_tbl}'" ) );
wp_insert_term( 'informatique', SkillPostType::TAX_CAT );

echo "== Comptes ==\n";
$candA = $mk( 'candidate' ); $candB = $mk( 'candidate' ); $recA = $mk( 'recruiter' ); $recB = $mk( 'recruiter' );
$users = array( $candA, $candB, $recA, $recB );
$admin = (int) ( get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) )[0] ?? 1 );

$track = function ( string $uuid ) use ( $repo, &$skills_created ) {
	$s = $repo->get_by_uuid( $uuid );
	if ( $s ) { $skills_created[] = (int) $s['id']; }
	return $uuid;
};
$body = array( 'title' => 'Bien classer ses papiers', 'category' => 'informatique', 'content' => '<p>Un contenu neutre et utile pour organiser ses documents.</p>', 'summary' => 'Organisation简单.', 'tags' => array( 'organisation', 'méthode' ) );

echo "== Création brouillon ==\n";
$c1 = $req( 'POST', '/postelio/v1/me/skills', $body, $candA );
$t( 'create => 201', 201 === $c1['status'] );
$u1 = (string) ( $c1['data']['data']['uuid'] ?? '' ); $track( $u1 );
$t( 'statut draft', 'draft' === ( $c1['data']['data']['status'] ?? '' ) );
$t( 'pas d\'ID SQL exposé', false === strpos( wp_json_encode( $c1['data']['data'] ), '"author_id"' ) && false === strpos( wp_json_encode( $c1['data']['data'] ), '"id":' ) );
$t( 'anon create => 401', 401 === $req( 'POST', '/postelio/v1/me/skills', $body, 0 )['status'] );

echo "== Brouillon non public ==\n";
$t( 'GET public draft => 404', 404 === $req( 'GET', '/postelio/v1/skills/' . $u1, null, 0 )['status'] );
$t( 'draft absent de la liste publique', ! in_array( $u1, array_map( static fn( $s ) => $s['uuid'], (array) $req( 'GET', '/postelio/v1/skills', null, 0 )['data']['data'] ), true ) );

echo "== Édition + révision ==\n";
$rev0 = (int) $repo->get_by_uuid( $u1 )['revision'];
$req( 'PUT', '/postelio/v1/me/skills/' . $u1, array( 'title' => 'Bien classer ses papiers (v2)' ), $candA );
$t( 'révision incrémentée', (int) $repo->get_by_uuid( $u1 )['revision'] === $rev0 + 1 );

echo "== Publication LOW => published + public ==\n";
$p1 = $req( 'POST', '/postelio/v1/me/skills/' . $u1 . '/publish', null, $candA );
$t( 'publish => 200 published', 200 === $p1['status'] && 'published' === ( $p1['data']['data']['status'] ?? '' ) );
$t( 'public detail => 200', 200 === $req( 'GET', '/postelio/v1/skills/' . $u1, null, 0 )['status'] );
$pubv = $req( 'GET', '/postelio/v1/skills/' . $u1, null, 0 )['data']['data'];
$t( 'byline auteur candidat présent', ! empty( $pubv['author']['name'] ) && 'candidate' === $pubv['author']['type'] );
$t( 'SEO contract présent (noindex=false)', isset( $pubv['seo'] ) && false === $pubv['seo']['noindex'] && true === $pubv['seo']['in_sitemap'] );
$t( 'détail public sans email/téléphone', false === strpos( wp_json_encode( $pubv ), '@postelio.test' ) );

echo "== Publication MEDIUM (contact) => published + case ==\n";
$um = $track( (string) ( $req( 'POST', '/postelio/v1/me/skills', array_merge( $body, array( 'title' => 'Me contacter', 'content' => '<p>Écrivez-moi à perso@example.com pour en savoir plus.</p>' ) ), $candA )['data']['data']['uuid'] ?? '' ) );
$pm = $req( 'POST', '/postelio/v1/me/skills/' . $um . '/publish', null, $candA );
$t( 'medium => 200 published (send+flag)', 200 === $pm['status'] && 'published' === ( $pm['data']['data']['status'] ?? '' ) );
$t( 'case modération ouverte pour le skill', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$cases} WHERE resource_type='skill' AND resource_uuid=%s", $um ) ) >= 1 );

echo "== Publication HIGH/CRITICAL => bloqué, reste draft, pas de pending ==\n";
$uh = $track( (string) ( $req( 'POST', '/postelio/v1/me/skills', array_merge( $body, array( 'title' => 'Payez pour postuler', 'content' => '<p>Envoyez un virement IBAN pour valider votre dossier.</p>' ) ), $candA )['data']['data']['uuid'] ?? '' ) );
$ph = $req( 'POST', '/postelio/v1/me/skills/' . $uh . '/publish', null, $candA );
$t( 'high => 422 moderation_blocked', 422 === $ph['status'] && 'moderation_blocked' === ( $ph['data']['error']['code'] ?? '' ) );
$t( 'reste draft (fail-closed)', 'draft' === (string) $repo->get_by_uuid( $uh )['status'] );
$t( 'message générique (aucune règle exposée)', false === strpos( strtolower( (string) ( $ph['data']['error']['message'] ?? '' ) ), 'iban' ) );

echo "== Ownership ==\n";
$t( 'candidat B édite skill de A => 404', 404 === $req( 'PUT', '/postelio/v1/me/skills/' . $u1, array( 'title' => 'pirate' ), $candB )['status'] );
$t( 'candidat B GET /me detail de A => 404', 404 === $req( 'GET', '/postelio/v1/me/skills/' . $u1, null, $candB )['status'] );

echo "== Entreprise vérifiée + as_company ==\n";
$siren = '100000000'; while ( ! Siren::is_valid_siren( $siren ) ) { $siren = str_pad( (string) ( ( (int) $siren ) + 1 ), 9, '0', STR_PAD_LEFT ); }
$cuuid = (string) ( $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'Skills Test SARL', 'legal' => array( 'siren' => $siren, 'raison_sociale' => 'Skills Test SARL' ) ), $recA )['data']['data']['uuid'] ?? '' );
$company_id = CompanyDirectory::company_of_user( $recA );
$req( 'POST', '/postelio/v1/companies/me/verification', null, $recA );
$req( 'POST', '/postelio/v1/companies/' . $cuuid . '/verification/decision', array( 'decision' => 'verified' ), $admin );
$cco = $req( 'POST', '/postelio/v1/me/skills', array_merge( $body, array( 'title' => 'Notre savoir-faire', 'as_company' => true, 'author_user_id' => $candB, 'company_id' => 999999 ) ), $recA );
$uc = $track( (string) ( $cco['data']['data']['uuid'] ?? '' ) );
$t( 'recruteur publie as_company => author_type company', 'company' === ( $cco['data']['data']['author_type'] ?? '' ) );
$sc = $repo->get_by_uuid( $uc );
$t( 'anti-spoofing : company = celle du recruteur', (int) $sc['company_id'] === $company_id );
$t( 'anti-spoofing : auteur = recruteur courant', (int) $sc['author_id'] === $recA );
$req( 'POST', '/postelio/v1/me/skills/' . $uc . '/publish', null, $recA );
$pcv = $req( 'GET', '/postelio/v1/skills/' . $uc, null, 0 )['data']['data'];
$t( 'byline entreprise', 'company' === ( $pcv['author']['type'] ?? '' ) && ! empty( $pcv['author']['company']['uuid'] ) );
$t( 'candidat as_company sans entreprise => 409', 409 === $req( 'POST', '/postelio/v1/me/skills', array_merge( $body, array( 'as_company' => true ) ), $candA )['status'] );

echo "== Recruteur B (autre entreprise) ne peut éditer ==\n";
$t( 'recruteur B édite contenu entreprise A => 404', 404 === $req( 'PUT', '/postelio/v1/me/skills/' . $uc, array( 'title' => 'pirate' ), $recB )['status'] );

echo "== Hide / Unhide (SkillModeration) ==\n";
SkillModeration::hide( $u1 );
$t( 'hidden => public 404', 404 === $req( 'GET', '/postelio/v1/skills/' . $u1, null, 0 )['status'] );
SkillModeration::unhide( $u1 );
$t( 'unhide => public 200', 200 === $req( 'GET', '/postelio/v1/skills/' . $u1, null, 0 )['status'] );

echo "== Report skill (Moderation) ==\n";
$t( 'report skill publié => 201', 201 === $req( 'POST', '/postelio/v1/moderation/reports', array( 'resource_type' => 'skill', 'resource_uuid' => $u1, 'reason_code' => 'spam' ), $candB )['status'] );
$t( 'report skill en draft => 404 (non-divulgation)', 404 === $req( 'POST', '/postelio/v1/moderation/reports', array( 'resource_type' => 'skill', 'resource_uuid' => $uh, 'reason_code' => 'spam' ), $candB )['status'] );

echo "== Commentaires (Avis) ==\n";
$t( 'anon comment => 401', 401 === $req( 'POST', '/postelio/v1/skills/' . $u1 . '/comments', array( 'body' => 'x' ), 0 )['status'] );
$rc1 = $req( 'POST', '/postelio/v1/skills/' . $u1 . '/comments', array( 'body' => 'Merci, très utile !' ), $candB );
$t( 'comment low => 201', 201 === $rc1['status'] );
$rcm = $req( 'POST', '/postelio/v1/skills/' . $u1 . '/comments', array( 'body' => 'Contactez perso@example.com' ), $candB );
$t( 'comment medium => 201 (publié+flag)', 201 === $rcm['status'] );
$rch = $req( 'POST', '/postelio/v1/skills/' . $u1 . '/comments', array( 'body' => 'je vais te tuer' ), $candB );
$t( 'comment critique => 422 moderation_blocked', 422 === $rch['status'] );
$t( 'commentaire bloqué : aucune row', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$comments_tbl} WHERE body=%s", 'je vais te tuer' ) ) );
$lst = $req( 'GET', '/postelio/v1/skills/' . $u1 . '/comments', null, 0 );
$t( 'liste commentaires publics => 2', 200 === $lst['status'] && 2 === count( (array) $lst['data']['data'] ) );

echo "== Rate limit commentaires ==\n";
add_filter( 'postelio/skills/comment_rate_per_hour', fn() => 1 );
$req( 'POST', '/postelio/v1/skills/' . $u1 . '/comments', array( 'body' => 'commentaire A' ), $recB );
$rl = $req( 'POST', '/postelio/v1/skills/' . $u1 . '/comments', array( 'body' => 'commentaire B' ), $recB );
$t( 'au-delà de la limite => 429', 429 === $rl['status'] );
remove_all_filters( 'postelio/skills/comment_rate_per_hour' );

echo "== Suspension user : cause distincte ==\n";
// Un skill de A masqué par MODÉRATION avant suspension doit rester masqué après réactivation.
SkillModeration::hide( $um );
UserModeration::suspend( UserDirectory::public_uuid( $candA ), $admin );
$t( 'user suspendu : skill perso publié masqué (404)', 404 === $req( 'GET', '/postelio/v1/skills/' . $u1, null, 0 )['status'] );
$t( 'user suspendu : nouvelle publication refusée', 403 === $req( 'POST', '/postelio/v1/me/skills', $body, $candA )['status'] );
$t( 'user suspendu : commentaire refusé', 403 === $req( 'POST', '/postelio/v1/skills/' . $uc . '/comments', array( 'body' => 'x' ), $candA )['status'] );
UserModeration::unsuspend( UserDirectory::public_uuid( $candA ), $admin );
$t( 'réactivation : skill restauré (200)', 200 === $req( 'GET', '/postelio/v1/skills/' . $u1, null, 0 )['status'] );
$t( 'réactivation NE réexpose PAS le contenu masqué par modération', ! SkillModeration::is_visible( $um ) );
SkillModeration::unhide( $um );

echo "== Suspension entreprise ==\n";
CompanyModeration::suspend( $admin, $cuuid, 'test' );
$t( 'entreprise suspendue : contenu entreprise masqué (404)', 404 === $req( 'GET', '/postelio/v1/skills/' . $uc, null, 0 )['status'] );
CompanyModeration::unsuspend( $admin, $cuuid );
$t( 'entreprise réactivée : contenu restauré (200)', 200 === $req( 'GET', '/postelio/v1/skills/' . $uc, null, 0 )['status'] );

echo "== Compte supprimé => masquage ==\n";
Core::instance()->events()->emit( 'user.deleted', array( 'id' => $candA, 'resource_type' => 'user', 'resource_id' => (string) $candA ) );
$t( 'compte supprimé : skills perso masqués (404)', 404 === $req( 'GET', '/postelio/v1/skills/' . $u1, null, 0 )['status'] );

echo "== Archive ==\n";
// restaure la visibilité (le user.deleted a masqué), puis archive via l'auteur.
$repo->set_susp_hidden( (int) $repo->get_by_uuid( $u1 )['id'], false );
$t( 'archive => archived', 'archived' === ( $req( 'POST', '/postelio/v1/me/skills/' . $u1 . '/archive', null, $candA )['data']['data']['status'] ?? '' ) );
$t( 'archived => public 404', 404 === $req( 'GET', '/postelio/v1/skills/' . $u1, null, 0 )['status'] );

echo "== Événements audités ==\n";
foreach ( array( 'skill.created', 'skill.published', 'skill.archived', 'skill.hidden', 'skill.restored', 'skill.comment_created' ) as $ev ) {
	$t( "audit contient {$ev}", (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE action=%s", $ev ) ) >= 1 );
}

echo "== Nettoyage ==\n";
$ids = array_unique( array_filter( $skills_created ) );
if ( $ids ) {
	$in = implode( ',', array_map( 'intval', $ids ) );
	$wpdb->query( "DELETE FROM {$comments_tbl} WHERE skill_id IN ({$in})" );
}
foreach ( $ids as $id ) { wp_delete_post( (int) $id, true ); }
$wpdb->query( "DELETE FROM {$cases} WHERE resource_type IN ('skill','skill_comment')" );
$wpdb->query( "DELETE FROM " . $wpdb->prefix . "postelio_moderation_reports WHERE resource_type IN ('skill','skill_comment')" );
if ( $company_id ) { ( new \Postelio\Companies\Members\MembershipRepository() )->remove_all_for_company( $company_id ); wp_delete_post( $company_id, true ); }
foreach ( $users as $u ) { if ( $u ) { ( new CandidateProfileRepository() )->delete_for( $u ); ( new RecruiterProfileRepository() )->delete_for( $u ); wp_delete_user( $u ); } }
$term = get_term_by( 'slug', 'informatique', SkillPostType::TAX_CAT ); if ( $term ) { wp_delete_term( $term->term_id, SkillPostType::TAX_CAT ); }
$wpdb->query( "DELETE FROM {$audit} WHERE action LIKE 'skill.%' OR action LIKE 'moderation.%' OR action LIKE 'company.%' OR action LIKE 'user.%' OR action LIKE 'plugin.%'" );
echo "  savoir-faire + commentaires + cases + entreprise + comptes supprimés\n";

echo "\n";
if ( empty( $fail ) ) { WP_CLI::success( "Smoke skills OK : {$pass} vérifications passées." ); }
else { WP_CLI::error( count( $fail ) . ' échec(s) : ' . implode( ' | ', $fail ) ); }
