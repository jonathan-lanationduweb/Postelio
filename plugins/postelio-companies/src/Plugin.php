<?php
/**
 * Amorçage du module postelio-companies. S'appuie sur postelio-core (registry,
 * migrations, événements, REST, permissions) et postelio-users (rôles recruteur,
 * capability `pst_email_verified`). Aucune infrastructure dupliquée.
 *
 * @package Postelio\Companies
 */

namespace Postelio\Companies;

use Postelio\Companies\Companies\CompanyController;
use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Companies\Companies\CompanyService;
use Postelio\Companies\Cpt\CompanyPostType;
use Postelio\Companies\Members\MembershipRepository;
use Postelio\Companies\Members\MembershipService;
use Postelio\Companies\Migrations\CreateCompanyMembersTable;
use Postelio\Companies\Verification\ManualVerificationProvider;
use Postelio\Companies\Verification\VerificationController;
use Postelio\Companies\Verification\VerificationProvider;
use Postelio\Companies\Verification\VerificationService;
use Postelio\Core\Plugin as Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE        = 'companies';
	public const SCHEMA_OPTION = 'postelio_companies_schema';

	private static ?Plugin $instance = null;
	private bool $booted            = false;

	private CompanyRepository $companies;
	private MembershipRepository $members;
	private MembershipService $membership_service;
	private CompanyService $company_service;
	private VerificationService $verification;

	private function __construct() {
		$this->companies          = new CompanyRepository();
		$this->members            = new MembershipRepository();
		$this->membership_service = new MembershipService( $this->members );
		$this->company_service    = new CompanyService( $this->companies, $this->membership_service );
		$this->verification       = new VerificationService( $this->companies, self::provider() );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private static function provider(): VerificationProvider {
		/**
		 * Permet de brancher un provider réel (Sirene/RNE) plus tard, une fois le
		 * contrat validé. Défaut : revue manuelle (aucune API externe).
		 */
		$provider = apply_filters( 'postelio/verification_provider', new ManualVerificationProvider() );
		return $provider instanceof VerificationProvider ? $provider : new ManualVerificationProvider();
	}

	/** @return CreateCompanyMembersTable[] */
	private static function migrations(): array {
		return array( new CreateCompanyMembersTable() );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$core = Core::instance();

		if ( ! $core->registry()->has( self::MODULE ) ) {
			$core->registry()->register(
				self::MODULE,
				array(
					'version'     => POSTELIO_COMPANIES_VERSION,
					'requires'    => array( 'core', 'users' ),
					'load_order'  => 20,
					'text_domain' => 'postelio-companies',
				)
			);
			$core->events()->emit( 'plugin.registered', array( 'module' => self::MODULE, 'version' => POSTELIO_COMPANIES_VERSION ) );
		}

		$core->migrator()->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );

		( new CompanyPostType() )->register();

		add_action( 'init', array( $this, 'maybe_upgrade' ), 2 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		add_action( 'init', static function () {
			load_plugin_textdomain( 'postelio-companies', false, dirname( plugin_basename( POSTELIO_COMPANIES_FILE ) ) . '/languages' );
		} );
	}

	public function register_routes(): void {
		( new CompanyController( $this->companies, $this->company_service, $this->membership_service ) )->register_routes();
		( new VerificationController( $this->verification, $this->companies, $this->membership_service ) )->register_routes();
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
			return;
		}
		( new CompanyPostType() )->register_type();
		$migrator = Core::instance()->migrator();
		$migrator->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );
		$migrator->migrate( self::MODULE );
	}

	public static function deactivate(): void {
		// Non destructif : conserve entreprises, membres et vérifications.
	}
}
