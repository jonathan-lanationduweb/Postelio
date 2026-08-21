<?php
/**
 * Amorçage du module postelio-files. S'appuie sur le core (registry, REST, events,
 * migrations, permissions). Fournit la gestion technique des fichiers privés via un
 * StorageProvider abstrait (local privé en V1).
 *
 * @package Postelio\Files
 */

namespace Postelio\Files;

use Postelio\Core\Plugin as Core;
use Postelio\Files\Files\CvService;
use Postelio\Files\Files\FileController;
use Postelio\Files\Files\FileRepository;
use Postelio\Files\Migrations\CreateFilesTable;
use Postelio\Files\Scan\FileScanner;
use Postelio\Files\Scan\NullScanner;
use Postelio\Files\Storage\LocalPrivateStorageProvider;
use Postelio\Files\Storage\StorageProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE        = 'files';
	public const SCHEMA_OPTION = 'postelio_files_schema';

	private static ?Plugin $instance = null;
	private bool $booted            = false;

	private FileRepository $files;
	private StorageProvider $storage;
	private FileScanner $scanner;
	private CvService $cv;

	private function __construct() {
		$this->files   = new FileRepository();
		$this->storage = self::make_storage();
		$this->scanner = self::make_scanner();
		$this->cv      = new CvService( $this->storage, $this->scanner, $this->files );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Répertoire de stockage privé (filtrable). Hors chemins publics + protégé. */
	public static function storage_dir(): string {
		$default = rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' ) . '/postelio-private/files';
		return (string) apply_filters( 'postelio/files/storage_dir', $default );
	}

	private static function make_storage(): StorageProvider {
		$provider = apply_filters( 'postelio/files/storage_provider', new LocalPrivateStorageProvider( self::storage_dir() ) );
		return $provider instanceof StorageProvider ? $provider : new LocalPrivateStorageProvider( self::storage_dir() );
	}

	private static function make_scanner(): FileScanner {
		$scanner = apply_filters( 'postelio/files/scanner', new NullScanner() );
		return $scanner instanceof FileScanner ? $scanner : new NullScanner();
	}

	/** @return CreateFilesTable[] */
	private static function migrations(): array {
		return array( new CreateFilesTable() );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
		$core         = Core::instance();

		if ( ! $core->registry()->has( self::MODULE ) ) {
			$core->registry()->register(
				self::MODULE,
				array(
					'version'     => POSTELIO_FILES_VERSION,
					'requires'    => array( 'core', 'users' ),
					'load_order'  => 25,
					'text_domain' => 'postelio-files',
				)
			);
			$core->events()->emit( 'plugin.registered', array( 'module' => self::MODULE, 'version' => POSTELIO_FILES_VERSION ) );
		}

		$core->migrator()->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );

		add_action( 'init', array( $this, 'maybe_upgrade' ), 2 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', static function () {
			load_plugin_textdomain( 'postelio-files', false, dirname( plugin_basename( POSTELIO_FILES_FILE ) ) . '/languages' );
		} );
	}

	public function register_routes(): void {
		( new FileController( $this->cv, $this->files, $this->storage ) )->register_routes();
	}

	public function maybe_upgrade(): void {
		$migrator = Core::instance()->migrator();
		if ( (string) get_option( self::SCHEMA_OPTION, '0' ) !== $migrator->target_version( self::MODULE ) ) {
			$migrator->migrate( self::MODULE );
		}
	}

	public function cv(): CvService {
		return $this->cv;
	}
	public function storage(): StorageProvider {
		return $this->storage;
	}

	// --- Cycle de vie ------------------------------------------------------

	public static function activate(): void {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			return;
		}
		$storage = self::make_storage();
		if ( $storage instanceof LocalPrivateStorageProvider ) {
			$storage->ensure_protected(); // crée le dossier privé + .htaccess deny + index.php
		}
		$m = Core::instance()->migrator();
		$m->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );
		$m->migrate( self::MODULE );
	}

	public static function deactivate(): void {
		// Non destructif : conserve fichiers et enregistrements.
	}
}
