<?php
/**
 * Tests unitaires postelio-files SANS dépendance (ni PHPUnit ni WordPress).
 *
 *   php plugins/postelio-files/tests/run-unit.php
 *
 * Couvre UploadValidator (extension/taille/signature/nom sûr) et la résistance à la
 * traversée de chemin de LocalPrivateStorageProvider.
 *
 * @package Postelio\Files\Tests
 */

declare( strict_types=1 );
define( 'POSTELIO_CORE_TESTING', true );

// Shims WP minimalistes.
if ( ! function_exists( 'wp_normalize_path' ) ) { function wp_normalize_path( $p ) { return str_replace( '\\', '/', (string) $p ); } }
if ( ! function_exists( 'wp_basename' ) ) { function wp_basename( $p ) { return basename( str_replace( '\\', '/', (string) $p ) ); } }
if ( ! function_exists( 'sanitize_file_name' ) ) { function sanitize_file_name( $n ) { $n = preg_replace( '/[^A-Za-z0-9._-]+/', '_', (string) $n ); return trim( $n, '_' ); } }
if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); } }

require_once dirname( __DIR__ ) . '/src/Files/UploadValidator.php';
require_once dirname( __DIR__ ) . '/src/Storage/StorageProvider.php';
require_once dirname( __DIR__ ) . '/src/Storage/LocalPrivateStorageProvider.php';
use Postelio\Files\Files\UploadValidator as UV;
use Postelio\Files\Storage\LocalPrivateStorageProvider;

$tests = 0; $failed = array();
function check( string $l, bool $c ): void { global $tests,$failed; ++$tests; echo ($c?'  [ok]   ':'  [FAIL] ').$l."\n"; if(!$c)$failed[]=$l; }

echo "== UploadValidator ==\n";
check( 'cv.pdf accepté', UV::allowed_extension( 'cv.pdf' ) );
check( 'cv.PDF (casse) accepté', UV::allowed_extension( 'MonCV.PDF' ) );
check( 'cv.pdf.php REFUSÉ', ! UV::allowed_extension( 'cv.pdf.php' ) );
check( 'cv.doc REFUSÉ', ! UV::allowed_extension( 'cv.doc' ) );
check( 'sans extension REFUSÉ', ! UV::allowed_extension( 'cv' ) );
check( 'taille ok', UV::within_size( 1000, 10485760 ) );
check( 'taille 0 refusée', ! UV::within_size( 0, 10485760 ) );
check( 'taille > max refusée', ! UV::within_size( 20000000, 10485760 ) );
check( 'signature %PDF- ok', UV::has_pdf_signature( "%PDF-1.7\n..." ) );
check( 'signature absente refusée', ! UV::has_pdf_signature( "PK\x03\x04" ) );
check( 'signature HTML refusée', ! UV::has_pdf_signature( '<?php echo 1;' ) );
check( 'nom sûr retire le chemin', UV::safe_original_name( '../../wp-config.php' ) === 'wp-config.php' );
check( 'nom sûr neutralise backslash', false === strpos( UV::safe_original_name( '..\\..\\evil.pdf' ), '\\' ) );
check( 'nom vide => défaut', UV::safe_original_name( '' ) === 'cv.pdf' );

echo "== LocalPrivateStorageProvider (anti-traversée) ==\n";
$base = sys_get_temp_dir() . '/pst_files_test_' . bin2hex( random_bytes( 4 ) );
mkdir( $base, 0777, true );
$src = $base . '/_src.bin';
file_put_contents( $src, 'HELLO' );
$sp = new LocalPrivateStorageProvider( $base . '/store' );

check( 'put clé normale => true', $sp->put( $src, '2026/08/abc.pdf' ) );
check( 'exists clé normale', $sp->exists( '2026/08/abc.pdf' ) );
check( 'size > 0', $sp->size( '2026/08/abc.pdf' ) === 5 );
check( 'put traversée ../ => false', ! $sp->put( $src, '../evil.pdf' ) );
check( 'put traversée imbriquée => false', ! $sp->put( $src, 'x/../../evil.pdf' ) );
check( 'clé absolue neutralisée (reste dans la base)', $sp->put( $src, '/x/passwd.pdf' ) && $sp->exists( 'x/passwd.pdf' ) );
check( 'exists clé traversée => false', ! $sp->exists( '../../_src.bin' ) );
check( 'open_read clé inconnue => null', null === $sp->open_read( 'nope/none.pdf' ) );
$fh = $sp->open_read( '2026/08/abc.pdf' );
check( 'open_read clé valide => resource', is_resource( $fh ) );
if ( is_resource( $fh ) ) { fclose( $fh ); }
check( 'delete => true', $sp->delete( '2026/08/abc.pdf' ) );
check( 'exists après delete => false', ! $sp->exists( '2026/08/abc.pdf' ) );

// Nettoyage
@unlink( $src );
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); }
@rmdir( $base );

echo "\n";
if ( empty( $failed ) ) { echo "RÉSULTAT : {$tests} assertions, 0 échec. OK\n"; exit( 0 ); }
echo 'RÉSULTAT : '.count($failed)." échec(s) sur {$tests}.\n"; exit( 1 );
