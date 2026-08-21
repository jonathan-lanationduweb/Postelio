<?php
/**
 * Amorçage du module postelio-users. S'appuie entièrement sur postelio-core
 * (registry, migrations, événements, REST, permissions) — aucune infrastructure
 * dupliquée.
 *
 * @package Postelio\Users
 */

namespace Postelio\Users;

use Postelio\Core\Plugin as Core;
use Postelio\Users\Auth\AuthController;
use Postelio\Users\Auth\TokenAuthenticator;
use Postelio\Users\Auth\TokenService;
use Postelio\Users\Migrations\AddCandidateUuid;
use Postelio\Users\Migrations\CreateCandidateProfilesTable;
use Postelio\Users\Migrations\CreateRecruiterProfilesTable;
use Postelio\Users\Profiles\CandidateProfileRepository;
use Postelio\Users\Profiles\ProfileController;
use Postelio\Users\Profiles\RecruiterProfileRepository;
use Postelio\Users\Settings\SettingsController;
use Postelio\Users\Settings\SettingsService;
use Postelio\Users\Users\AccountService;
use Postelio\Users\Users\UserPresenter;
use Postelio\Users\Verification\EmailVerification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE        = 'users';
	public const SCHEMA_OPTION = 'postelio_users_schema';

	private static ?Plugin $instance = null;
	private bool $booted            = false;

	private CandidateProfileRepository $candidates;
	private RecruiterProfileRepository $recruiters;
	private SettingsService $settings;
	private TokenService $tokens;
	private AccountService $accounts;
	private UserPresenter $presenter;

	private function __construct() {
		$this->candidates = new CandidateProfileRepository();
		$this->recruiters = new RecruiterProfileRepository();
		$this->settings   = new SettingsService();
		$this->tokens     = new TokenService();
		$this->accounts   = new AccountService( $this->candidates, $this->recruiters );
		$this->presenter  = new UserPresenter( $this->candidates, $this->recruiters, $this->settings );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @return CreateCandidateProfilesTable[]|CreateRecruiterProfilesTable[] */
	private static function migrations(): array {
		return array(
			new CreateCandidateProfilesTable(),
			new CreateRecruiterProfilesTable(),
			new AddCandidateUuid(),
		);
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$core = Core::instance();

		// 1. Déclaration du module dans le registry du core.
		if ( ! $core->registry()->has( self::MODULE ) ) {
			$core->registry()->register(
				self::MODULE,
				array(
					'version'     => POSTELIO_USERS_VERSION,
					'requires'    => array( 'core' ),
					'load_order'  => 10,
					'text_domain' => 'postelio-users',
				)
			);
			$core->events()->emit( 'plugin.registered', array( 'module' => self::MODULE, 'version' => POSTELIO_USERS_VERSION ) );
		}

		// 2. Migrations du module, gérées par le migrateur du core.
		$core->migrator()->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );

		// 3. Authentification applicative (Bearer) — complète les cookies WP.
		( new TokenAuthenticator( $this->tokens ) )->register();

		// 3b. Capability virtuelle `pst_email_verified` (contrat pour les lots futurs).
		( new EmailVerification() )->register();

		// 4. Enrichissement transversal de /me (le core ignore le domaine users).
		add_filter( 'postelio/me', array( $this->presenter, 'enrich_me' ), 10, 2 );

		// 5. Traductions.
		add_action( 'init', static function () {
			load_plugin_textdomain( 'postelio-users', false, dirname( plugin_basename( POSTELIO_USERS_FILE ) ) . '/languages' );
		} );

		// 6. Filet de migration au chargement (après un pull sans réactivation).
		add_action( 'init', array( $this, 'maybe_upgrade' ), 2 );

		// 7. Endpoints REST du module.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		( new AuthController( $this->accounts, $this->presenter, $this->tokens ) )->register_routes();
		( new ProfileController( $this->candidates, $this->recruiters ) )->register_routes();
		( new SettingsController( $this->settings, $this->accounts ) )->register_routes();
	}

	public function maybe_upgrade(): void {
		$migrator = Core::instance()->migrator();
		if ( (string) get_option( self::SCHEMA_OPTION, '0' ) !== $migrator->target_version( self::MODULE ) ) {
			$migrator->migrate( self::MODULE );
		}
	}

	// --- Cycle de vie ------------------------------------------------------

	public static function activate(): void {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			return; // le core doit être actif ; garde de sécurité.
		}
		$migrator = Core::instance()->migrator();
		$migrator->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );
		$migrator->migrate( self::MODULE );
	}

	public static function deactivate(): void {
		// Non destructif : conserve tables, comptes, profils et jetons.
	}
}
