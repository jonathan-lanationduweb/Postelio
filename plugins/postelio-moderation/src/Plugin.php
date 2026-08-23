<?php
/**
 * Amorçage du module postelio-moderation. Domaine central de décision : signalements
 * (réactif) + passerelle préventive (`postelio/moderation/evaluate`). Décide ; délègue
 * l'exécution aux domaines propriétaires via leurs contrats publics.
 *
 * @package Postelio\Moderation
 */

namespace Postelio\Moderation;

use Postelio\Core\Plugin as Core;
use Postelio\Moderation\Actions\ModerationActions;
use Postelio\Moderation\Cases\CaseEventRepository;
use Postelio\Moderation\Cases\CaseRepository;
use Postelio\Moderation\Cases\CaseService;
use Postelio\Moderation\Domain\EvaluationRequest;
use Postelio\Moderation\Domain\ModerationGateway;
use Postelio\Moderation\Http\CaseController;
use Postelio\Moderation\Http\ReportController;
use Postelio\Moderation\Migrations\CreateModerationTables;
use Postelio\Moderation\Reports\ReportRepository;
use Postelio\Moderation\Reports\ReportService;
use Postelio\Moderation\Rules\LocalRuleEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE        = 'moderation';
	public const SCHEMA_OPTION = 'postelio_moderation_schema';

	private static ?Plugin $instance = null;
	private bool $booted = false;

	private CaseService $cases;
	private ReportService $reports;
	private ModerationGateway $gateway;

	private function __construct() {
		$case_repo   = new CaseRepository();
		$event_repo  = new CaseEventRepository();
		$this->cases = new CaseService( $case_repo, $event_repo, new ModerationActions() );
		$this->reports = new ReportService( new ReportRepository(), $this->cases );
		$this->gateway = new ModerationGateway( new LocalRuleEngine(), $this->cases );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @return CreateModerationTables[] */
	private static function migrations(): array {
		return array( new CreateModerationTables() );
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
					'version'     => POSTELIO_MODERATION_VERSION,
					'requires'    => array( 'core' ),
					'load_order'  => 90,
					'text_domain' => 'postelio-moderation',
				)
			);
			$core->events()->emit( 'plugin.registered', array( 'module' => self::MODULE, 'version' => POSTELIO_MODERATION_VERSION ) );
		}

		$core->migrator()->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );

		// Contrat entrant préventif : les domaines appellent ce filtre (jamais les tables).
		add_filter( 'postelio/moderation/evaluate', function ( $default, $request ) {
			if ( ! is_array( $request ) ) {
				return $default;
			}
			return $this->gateway->evaluate( EvaluationRequest::from_array( $request ) )->to_array();
		}, 10, 2 );

		add_action( 'init', array( $this, 'maybe_upgrade' ), 2 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		( new ReportController( $this->reports ) )->register_routes();
		( new CaseController( $this->cases ) )->register_routes();
	}

	public function gateway(): ModerationGateway {
		return $this->gateway;
	}
	public function reports(): ReportService {
		return $this->reports;
	}
	public function cases(): CaseService {
		return $this->cases;
	}

	public function maybe_upgrade(): void {
		$migrator = Core::instance()->migrator();
		if ( (string) get_option( self::SCHEMA_OPTION, '0' ) !== $migrator->target_version( self::MODULE ) ) {
			$migrator->migrate( self::MODULE );
		}
	}

	public static function activate(): void {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			return;
		}
		$m = Core::instance()->migrator();
		$m->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );
		$m->migrate( self::MODULE );
	}

	public static function deactivate(): void {
		// Non destructif : conserve reports, cases et events.
	}
}
