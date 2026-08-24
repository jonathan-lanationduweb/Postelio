<?php
/**
 * Amorçage du module postelio-skills (« Savoir-faire & Avis »). CPT + taxonomies, commentaires
 * (table dédiée), publication modérée (gate `postelio/moderation/evaluate`, comme Jobs), contrats
 * SkillDirectory/SkillModeration, cascade de masquage sur suspension. Aucune UI admin, aucun
 * provider externe, aucun e-mail direct (événements → Notifications).
 *
 * @package Postelio\Skills
 */

namespace Postelio\Skills;

use Postelio\Core\Plugin as Core;
use Postelio\Skills\Comments\CommentRepository;
use Postelio\Skills\Comments\CommentService;
use Postelio\Skills\Cpt\SkillPostType;
use Postelio\Skills\Http\CommentController;
use Postelio\Skills\Http\SkillController;
use Postelio\Skills\Integration\SuspensionSync;
use Postelio\Skills\Migrations\CreateSkillCommentsTable;
use Postelio\Skills\Skills\SkillRepository;
use Postelio\Skills\Skills\SkillService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE        = 'skills';
	public const SCHEMA_OPTION = 'postelio_skills_schema';

	private static ?Plugin $instance = null;
	private bool $booted = false;

	private SkillRepository $skills;
	private SkillService $service;
	private CommentRepository $comment_repo;
	private CommentService $comments;

	private function __construct() {
		$this->skills       = new SkillRepository();
		$this->service      = new SkillService( $this->skills );
		$this->comment_repo = new CommentRepository();
		$this->comments     = new CommentService( $this->comment_repo, $this->skills );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @return CreateSkillCommentsTable[] */
	private static function migrations(): array {
		return array( new CreateSkillCommentsTable() );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
		$core         = Core::instance();

		if ( ! $core->registry()->has( self::MODULE ) ) {
			$core->registry()->register( self::MODULE, array(
				'version'     => POSTELIO_SKILLS_VERSION,
				'requires'    => array( 'core', 'users', 'companies' ),
				'load_order'  => 60,
				'text_domain' => 'postelio-skills',
			) );
			$core->events()->emit( 'plugin.registered', array( 'module' => self::MODULE, 'version' => POSTELIO_SKILLS_VERSION ) );
		}

		( new SkillPostType() )->register();
		$core->migrator()->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );

		// Cascade de masquage (découplée) sur suspension user/entreprise.
		( new SuspensionSync( $core->events(), $this->skills ) )->register();

		// Contrat sortant : dit à la modération si une ressource skill/skill_comment est
		// « connaissable » par un rapporteur (existe + publique) — sinon 404 non-divulgation.
		add_filter( 'postelio/moderation/resource_visible', array( $this, 'moderation_resource_visible' ), 10, 4 );

		add_action( 'init', array( $this, 'maybe_upgrade' ), 2 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		( new SkillController( $this->service ) )->register_routes();
		( new CommentController( $this->comments ) )->register_routes();
	}

	/** @param mixed $visible */
	public function moderation_resource_visible( $visible, string $type, string $uuid, int $reporter_id ) {
		if ( 'skill' === $type ) {
			$s = $this->skills->get_by_uuid( $uuid );
			return null !== $s && $this->skills->is_public_visible( $s );
		}
		if ( 'skill_comment' === $type ) {
			$c = $this->comment_repo->get_by_uuid( $uuid );
			return null !== $c && CommentRepository::PUBLISHED === $c['status'];
		}
		return $visible;
	}

	public function service(): SkillService {
		return $this->service;
	}
	public function comments(): CommentService {
		return $this->comments;
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
		( new SkillPostType() )->register_type();
		$m = Core::instance()->migrator();
		$m->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );
		$m->migrate( self::MODULE );
	}

	public static function deactivate(): void {
		// Non destructif : conserve savoir-faire (CPT) et commentaires.
	}
}
