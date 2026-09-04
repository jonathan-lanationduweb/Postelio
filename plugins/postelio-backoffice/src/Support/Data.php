<?php
/**
 * Accès aux DONNÉES d'administration (lecture seule). Toutes les valeurs viennent des contrats
 * publics des plugins métier, d'endpoints REST internes ou de lectures WordPress natives ; jamais
 * de SQL dans une table d'un autre plugin ; une valeur indisponible = null (affichée « — »).
 *
 * TRANSITOIRE : en Phase 1, les agrégateurs éprouvés du legacy (Postelio\Admin\Support\Metrics,
 * Health, Contracts) sont réutilisés tels quels derrière cette façade ; ils seront rapatriés ici
 * lorsque Postelio Admin sera retiré. Tout est gardé par class_exists → aucun fatal si le legacy est
 * désactivé.
 *
 * @package Postelio\Backoffice\Support
 */

namespace Postelio\Backoffice\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Data {

	private const METRICS   = '\\Postelio\\Admin\\Support\\Metrics';
	private const HEALTH    = '\\Postelio\\Admin\\Support\\Health';
	private const CONTRACTS = '\\Postelio\\Admin\\Support\\Contracts';

	public static function module_active( string $module ): bool {
		if ( class_exists( self::CONTRACTS ) ) {
			return (bool) call_user_func( array( self::CONTRACTS, 'module_active' ), $module );
		}
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			return false;
		}
		try {
			return \Postelio\Core\Plugin::instance()->registry()->has( $module );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public static function has( string $fqcn ): bool {
		return class_exists( $fqcn );
	}

	/** @return array<string,int> */
	public static function user_counts(): array {
		if ( class_exists( self::METRICS ) ) {
			return (array) call_user_func( array( self::METRICS, 'user_counts' ) );
		}
		$c     = function_exists( 'count_users' ) ? count_users() : array( 'avail_roles' => array() );
		$roles = $c['avail_roles'] ?? array();
		return array(
			'candidates' => (int) ( $roles['postelio_candidate'] ?? 0 ),
			'recruiters' => (int) ( $roles['postelio_recruiter'] ?? 0 ),
			'moderators' => (int) ( $roles['postelio_moderator'] ?? 0 ),
			'total'      => (int) ( $c['total_users'] ?? 0 ),
		);
	}

	/** @return array<string,int>|null */
	public static function company_counts(): ?array {
		return self::has( '\\Postelio\\Companies\\Api\\CompanyAdminDirectory' ) ? \Postelio\Companies\Api\CompanyAdminDirectory::counts() : null;
	}

	/** @return array<string,int>|null */
	public static function job_counts(): ?array {
		return self::has( '\\Postelio\\Jobs\\Api\\JobAdminDirectory' ) ? \Postelio\Jobs\Api\JobAdminDirectory::counts() : null;
	}

	/** @return array<string,int>|null */
	public static function application_counts(): ?array {
		return self::has( '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory' ) ? (array) \Postelio\Applications\Api\ApplicationAdminDirectory::counts() : null;
	}

	public static function moderation_open(): ?int {
		if ( ! self::module_active( 'moderation' ) || ! self::has( '\\Postelio\\Moderation\\Api\\ModerationDirectory' ) ) {
			return null;
		}
		return \Postelio\Moderation\Api\ModerationDirectory::open_cases_count();
	}

	public static function moderation_critical(): ?int {
		return class_exists( self::METRICS ) ? call_user_func( array( self::METRICS, 'moderation_critical' ) ) : null;
	}

	/** @return array{paid:?int, failed:?int, mode:string, configured:bool}|null */
	public static function billing_health(): ?array {
		return class_exists( self::METRICS ) ? call_user_func( array( self::METRICS, 'billing_health' ) ) : null;
	}

	/** @return array<string,mixed>|null Compteurs de la file e-mail (contrat Notifications). */
	public static function delivery_stats(): ?array {
		$dir = '\\Postelio\\Notifications\\Api\\NotificationDirectory';
		if ( ! self::has( $dir ) || ! method_exists( $dir, 'delivery_stats' ) ) {
			return null;
		}
		return (array) call_user_func( array( $dir, 'delivery_stats' ) );
	}

	/**
	 * Santé : snapshot { core: {status,…}, modules: [ {module,label,status,meta} ] }.
	 *
	 * @return array{core:array<string,mixed>, modules:array<int,array<string,mixed>>}
	 */
	public static function health(): array {
		if ( class_exists( self::HEALTH ) ) {
			return (array) call_user_func( array( self::HEALTH, 'snapshot' ) );
		}
		return array( 'core' => array( 'status' => 'absent' ), 'modules' => array() );
	}

	/** Statut global agrégé : error > degraded > ok. */
	public static function health_global(): string {
		$snap     = self::health();
		$statuses = array( (string) ( $snap['core']['status'] ?? 'ok' ) );
		foreach ( (array) $snap['modules'] as $m ) {
			$statuses[] = (string) ( $m['status'] ?? 'ok' );
		}
		if ( in_array( 'error', $statuses, true ) ) {
			return 'error';
		}
		if ( in_array( 'degraded', $statuses, true ) ) {
			return 'degraded';
		}
		return 'ok';
	}

	public static function health_label( string $status ): string {
		$map = array( 'ok' => 'OK', 'degraded' => 'Dégradé', 'unconfigured' => 'À configurer', 'error' => 'Erreur', 'absent' => 'Absent' );
		return $map[ $status ] ?? ucfirst( $status );
	}

	public static function health_variant( string $status ): string {
		$map = array( 'ok' => 'success', 'degraded' => 'warning', 'unconfigured' => 'info', 'error' => 'error' );
		return $map[ $status ] ?? 'neutral';
	}
}
