<?php
/**
 * Smoke test postelio-files (logique + intégration) sur WordPress vivant :
 *   wp eval-file plugins/postelio-files/tests/smoke.php --path=wordpress
 *
 * Le streaming view/download (qui fait `exit`) est testé séparément en HTTP réel.
 *
 * @package Postelio\Files\Tests
 */

use Postelio\Applications\Applications\ApplicationRepository;
use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Companies\Members\MembershipRepository;
use Postelio\Companies\Verification\ManualVerificationProvider;
use Postelio\Companies\Verification\VerificationService;
use Postelio\Core\Plugin as Core;
use Postelio\Files\Api\FileCvContract;
use Postelio\Files\Files\FileRepository;
use Postelio\Files\Plugin as FilesPlugin;
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
$mk  = static function ( string $role ) use ( $acc ): int { return $acc->register( array( 'email' => 'sf.' . $role . '.' . wp_generate_password( 6, false ) . '@postelio.test', 'password' => 'motdepasse123', 'role' => $role ) ); };

$tmp = static function ( string $content ): array {
	$p = wp_tempnam( 'pst_cv_' );
	file_put_contents( $p, $content );
	return array( 'tmp_name' => $p, 'name' => 'CV.pdf', 'size' => filesize( $p ), 'error' => UPLOAD_ERR_OK );
};
$pdf = "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<<>>\n%%EOF\n";

global $wpdb;
$cv    = FilesPlugin::instance()->cv();
$store = FilesPlugin::instance()->storage();
$frepo = new FileRepository();
$users = array(); $companies = array(); $jobs = array(); $fids = array();

echo "== Activation / table / stockage privé ==\n";
$t( 'plugin files actif', is_plugin_active( 'postelio-files/postelio-files.php' ) );
$t( 'module files dans le registry', Core::instance()->registry()->has( 'files' ) );
$tbl = $wpdb->prefix . 'postelio_files';
$t( "table {$tbl}", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
$t( 'schema files = 1', (string) get_option( 'postelio_files_schema' ) === '1' );
$dir = FilesPlugin::storage_dir();
$t( 'répertoire privé hors uploads publics', false === strpos( $dir, '/uploads/' ) );
$t( '.htaccess de protection présent', is_file( $dir . '/.htaccess' ) && false !== strpos( (string) file_get_contents( $dir . '/.htaccess' ), 'denied' ) );

echo "== Comptes ==\n";
$cand = $mk( 'candidate' ); $cand2 = $mk( 'candidate' );
$recA = $mk( 'recruiter' ); $recB = $mk( 'recruiter' );
$users = array( $cand, $cand2, $recA, $recB );
$admin = (int) ( get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) )[0] ?? 1 );

echo "== Validation d'upload ==\n";
try { $cv->upload( $cand, $tmp( '<?php echo "x"; ?>' ) ); $t( 'PHP renommé .pdf refusé', false ); }
catch ( \Postelio\Core\ApiError $e ) { $t( 'PHP renommé .pdf refusé (' . $e->error_code() . ')', 'unsupported_media_type' === $e->error_code() ); }
try { $cv->upload( $cand, array( 'tmp_name' => wp_tempnam( 'x' ), 'name' => 'cv.pdf', 'size' => 11 * 1024 * 1024, 'error' => 0 ) ); $t( '>10 Mo refusé', false ); }
catch ( \Postelio\Core\ApiError $e ) { $t( '>10 Mo refusé (' . $e->error_code() . ')', 'payload_too_large' === $e->error_code() ); }
try { $cv->upload( $cand, array_merge( $tmp( '' ), array( 'size' => 0 ) ) ); $t( '0 octet refusé', false ); }
catch ( \Postelio\Core\ApiError $e ) { $t( '0 octet refusé', true ); }
try { $cv->upload( $cand, array_merge( $tmp( $pdf ), array( 'name' => 'cv.doc' ) ) ); $t( 'extension .doc refusée', false ); }
catch ( \Postelio\Core\ApiError $e ) { $t( 'extension .doc refusée', 'unsupported_media_type' === $e->error_code() ); }

echo "== Upload CV valide + nom traversal ==\n";
$f1 = $cv->upload( $cand, array_merge( $tmp( $pdf ), array( 'name' => '../../wp-config.pdf' ) ) );
$fids[] = (int) $f1['id'];
$t( 'upload => ressource créée', ! empty( $f1['public_uuid'] ) );
$t( 'nom d\'origine assaini (pas de chemin)', false === strpos( (string) $f1['original_name'], '/' ) && false === strpos( (string) $f1['original_name'], '..' ) );
$t( 'stored_name aléatoire (≠ original)', $f1['stored_name'] !== $f1['original_name'] );
$t( 'fichier présent dans le stockage privé', $store->exists( $f1['storage_key'] ) );
$t( 'premier CV = principal', true === (bool) $f1['is_primary'] );
$t( 'sha256 calculé', strlen( (string) $f1['sha256'] ) === 64 );

echo "== Liste / presenter (pas de fuite) ==\n";
$listResp = $req( 'GET', '/postelio/v1/me/files/cv', null, $cand );
$row0 = $listResp['data']['data'][0] ?? array();
$t( 'GET /me/files/cv => 200 + 1 CV', 200 === $listResp['status'] && count( (array) $listResp['data']['data'] ) === 1 );
$t( 'presenter n\'expose PAS storage_key', ! isset( $row0['storage_key'] ) && false === strpos( wp_json_encode( $row0 ), 'storage' ) );
$t( 'presenter n\'expose PAS owner_user_id', ! isset( $row0['owner_user_id'] ) );
$t( 'presenter expose uuid + liens view/download', ! empty( $row0['uuid'] ) && ! empty( $row0['links']['download'] ) );

echo "== Snapshot candidature (v1 → postule → v2 → suppr v1) ==\n";
// Entreprise vérifiée + offre publiée
$c = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'FilesCo', 'legal' => array( 'siren' => '552100554' ) ), $recA );
$cid = CompanyDirectory::id_from_uuid( $c['data']['data']['uuid'] ); $companies[] = $cid;
$vs = new VerificationService( new CompanyRepository(), new ManualVerificationProvider() ); $vs->request( $cid, 1 ); $vs->decide( $cid, 1, 'verified' );
$j = $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'Dev', 'ville' => 'Lyon' ), $recA ); $ju = $j['data']['data']['uuid'];
$req( 'POST', '/postelio/v1/jobs/' . $ju . '/publish', null, $recA );
$jid = ( new JobRepository() )->get_by_uuid( $ju )['id']; $jobs[] = $jid;
// Candidat postule avec CV v1
$t( 'FileCvContract: v1 utilisable', FileCvContract::usable_for_application( $f1['public_uuid'], $cand ) );
$appR = $req( 'POST', '/postelio/v1/jobs/' . $ju . '/applications', array( 'cv_uuid' => $f1['public_uuid'] ), $cand );
$t( 'candidature avec cv_uuid => 201', 201 === $appR['status'] );
$appU = $appR['data']['data']['uuid'];
$cv_ref = (string) ( new ApplicationRepository() )->get_by_uuid( $appU )['cv_reference'];
$t( 'candidature référence bien le CV v1', $cv_ref === $f1['public_uuid'] );
// CV appartenant à un autre candidat => refus
$appBad = $req( 'POST', '/postelio/v1/jobs/' . $ju . '/applications', array( 'cv_uuid' => $f1['public_uuid'] ), $cand2 );
$t( 'CV d\'autrui refusé (422)', 422 === $appBad['status'] );
// Upload v2 + principal
$f2 = $cv->upload( $cand, array_merge( $tmp( $pdf ), array( 'name' => 'cv-v2.pdf' ) ) ); $fids[] = (int) $f2['id'];
$cv->set_primary( $cand, $f2['public_uuid'] );
$t( 'v2 principal', true === (bool) $frepo->get_by_uuid( $f2['public_uuid'] )['is_primary'] );
$t( 'v1 n\'est plus principal', false === (bool) $frepo->get_by_uuid( $f1['public_uuid'] )['is_primary'] );
// Supprimer v1 du profil → referenced → archived (conservé)
$del = $cv->delete( $cand, $f1['public_uuid'] );
$t( 'suppression v1 référencé => archived (conservé)', 'archived' === $del['status'] );
$t( 'fichier v1 toujours présent en stockage', $store->exists( $f1['storage_key'] ) );
$t( 'candidature référence toujours v1', (string) ( new ApplicationRepository() )->get_by_uuid( $appU )['cv_reference'] === $f1['public_uuid'] );
$t( 'v1 archivé => plus utilisable pour une nouvelle candidature', ! FileCvContract::usable_for_application( $f1['public_uuid'], $cand ) );
$t( 'v1 absent de la liste active du profil', ! in_array( $f1['public_uuid'], array_map( fn( $x ) => $x['uuid'], (array) $req( 'GET', '/postelio/v1/me/files/cv', null, $cand )['data']['data'] ), true ) );

echo "== Autorisation d'accès (filtre) ==\n";
$t( 'recruteur A autorisé sur le CV de la candidature', true === apply_filters( 'postelio/files/authorize_download', false, $f1['public_uuid'], $recA ) );
$t( 'recruteur B NON autorisé', false === apply_filters( 'postelio/files/authorize_download', false, $f1['public_uuid'], $recB ) );
$t( 'candidat tiers NON autorisé (filtre)', false === apply_filters( 'postelio/files/authorize_download', false, $f1['public_uuid'], $cand2 ) );
$t( 'CV référencé (is_referenced) = true', true === apply_filters( 'postelio/files/file_is_referenced', false, $f1['public_uuid'] ) );

echo "== Accès métadonnées : ownership ==\n";
$t( 'candidat propriétaire lit son CV (200)', 200 === $req( 'GET', '/postelio/v1/me/files/cv/' . $f2['public_uuid'], null, $cand )['status'] );
$t( 'autre candidat sur CV d\'autrui (404)', 404 === $req( 'GET', '/postelio/v1/me/files/cv/' . $f2['public_uuid'], null, $cand2 )['status'] );
$t( 'UUID inconnu (404)', 404 === $req( 'GET', '/postelio/v1/me/files/cv/' . wp_generate_uuid4(), null, $cand )['status'] );

echo "== Suppression d'un CV NON référencé ==\n";
$f3 = $cv->upload( $cand2, $tmp( $pdf ) ); $fids[] = (int) $f3['id'];
$del3 = $cv->delete( $cand2, $f3['public_uuid'] );
$t( 'CV non référencé => deleted', 'deleted' === $del3['status'] );

echo "== Événements / audit ==\n";
foreach ( array( 'cv.uploaded', 'cv.primary_changed', 'cv.deleted' ) as $ev ) {
	$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}postelio_audit_log WHERE action = %s", $ev ) );
	$t( "audit contient {$ev}", $n >= 1 );
}

echo "== Nettoyage ==\n";
foreach ( $fids as $fid ) { $f = $frepo->get( $fid ); if ( $f ) { $store->delete( $f['storage_key'] ); $frepo->hard_delete( $fid ); } }
$ap = $wpdb->prefix . 'postelio_applications'; $ids_in = implode( ',', array_map( 'intval', $companies ?: array( 0 ) ) );
$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_application_history WHERE application_id IN (SELECT id FROM {$ap} WHERE company_id IN ({$ids_in}))" );
$wpdb->query( "DELETE FROM {$ap} WHERE company_id IN ({$ids_in})" );
foreach ( $jobs as $jid ) { wp_delete_post( $jid, true ); }
foreach ( $companies as $cid ) { ( new MembershipRepository() )->remove_all_for_company( $cid ); wp_delete_post( $cid, true ); }
foreach ( $users as $u ) { ( new CandidateProfileRepository() )->delete_for( $u ); ( new RecruiterProfileRepository() )->delete_for( $u ); wp_delete_user( $u ); }
$wpdb->query( "DELETE FROM {$wpdb->prefix}postelio_audit_log WHERE resource_type IN ('file','application','job','company') OR action LIKE 'user.%'" );
echo "  fichiers + candidatures + offres + entreprises + comptes supprimés\n";

echo "\n";
if ( empty( $fail ) ) { WP_CLI::success( "Smoke files OK : {$pass} vérifications passées." ); }
else { WP_CLI::error( count( $fail ) . ' échec(s) : ' . implode( ' | ', $fail ) ); }
