<?php
/**
 * Accès aux DONNÉES d'administration (lecture seule). Toutes les valeurs viennent des façades
 * publiques des plugins métier (`*AdminDirectory`, `*Directory`), d'endpoints REST internes
 * (`Support\Rest`) ou de lectures WordPress natives. Jamais de SQL dans la table d'un autre plugin ;
 * une valeur indisponible vaut `null` et s'affiche « — ».
 *
 * Les façades restent DANS les plugins métier (choix d'architecture) : cette classe ne fait que les
 * appeler derrière des gardes `class_exists`, pour qu'un module désactivé n'entraîne jamais de fatal.
 *
 * @package Postelio\Backoffice\Support
 */

namespace Postelio\Backoffice\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Data {

	/** Le module est-il enregistré dans le Registry du core ? */
	public static function module_active( string $module ): bool {
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

	/** Appelle une méthode statique de façade si elle existe, sinon renvoie `$fallback`. @return mixed */
	public static function facade( string $fqcn, string $method, array $args = array(), $fallback = null ) {
		if ( ! class_exists( $fqcn ) || ! method_exists( $fqcn, $method ) ) {
			return $fallback;
		}
		try {
			return call_user_func_array( array( $fqcn, $method ), $args );
		} catch ( \Throwable $e ) {
			return $fallback;
		}
	}

	// ------------------------------------------------------------------ utilisateurs

	/** @return array<string,int> Comptes par rôle (WordPress natif). */
	public static function user_counts(): array {
		$c     = function_exists( 'count_users' ) ? count_users() : array( 'avail_roles' => array(), 'total_users' => 0 );
		$roles = $c['avail_roles'] ?? array();
		return array(
			'candidates' => (int) ( $roles['postelio_candidate'] ?? 0 ),
			'recruiters' => (int) ( $roles['postelio_recruiter'] ?? 0 ),
			'moderators' => (int) ( $roles['postelio_moderator'] ?? 0 ),
			'total'      => (int) ( $c['total_users'] ?? 0 ),
		);
	}

	public static function suspended_users(): ?int {
		if ( ! class_exists( '\\Postelio\\Users\\Users\\AccountService' ) ) {
			return null;
		}
		$q = new \WP_User_Query( array(
			'meta_key'    => \Postelio\Users\Users\AccountService::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'  => \Postelio\Users\Users\AccountService::STATUS_SUSPENDED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'count_total' => true,
			'number'      => 1,
			'fields'      => 'ID',
		) );
		return (int) $q->get_total();
	}

	// ------------------------------------------------------------------ compteurs métier

	/** @return array<string,int>|null */
	public static function company_counts(): ?array {
		$r = self::facade( '\\Postelio\\Companies\\Api\\CompanyAdminDirectory', 'counts' );
		return is_array( $r ) ? $r : null;
	}

	/** @return array<string,int>|null */
	public static function job_counts(): ?array {
		$r = self::facade( '\\Postelio\\Jobs\\Api\\JobAdminDirectory', 'counts' );
		return is_array( $r ) ? $r : null;
	}

	/** @return array<string,int>|null */
	public static function application_counts(): ?array {
		$r = self::facade( '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory', 'counts' );
		return is_array( $r ) ? $r : null;
	}

	public static function moderation_open(): ?int {
		if ( ! self::module_active( 'moderation' ) ) {
			return null;
		}
		$r = self::facade( '\\Postelio\\Moderation\\Api\\ModerationDirectory', 'open_cases_count' );
		return null === $r ? null : (int) $r;
	}

	public static function moderation_critical(): ?int {
		if ( ! self::module_active( 'moderation' ) ) {
			return null;
		}
		return Rest::total( '/postelio/v1/moderation/cases', array( 'status' => 'open', 'priority' => 'critical' ) );
	}

	/** @return array{paid:?int, failed:?int, mode:string, configured:bool}|null */
	public static function billing_health(): ?array {
		if ( ! self::module_active( 'billing' ) ) {
			return null;
		}
		$d = Rest::payload( '/postelio/v1/billing/health' );
		return array(
			'paid'       => Rest::total( '/postelio/v1/billing/admin/orders', array( 'status' => 'fulfilled' ) ),
			'failed'     => isset( $d['failed_fulfillment_count'] ) ? (int) $d['failed_fulfillment_count'] : null,
			'mode'       => (string) ( $d['mode'] ?? 'unknown' ),
			'configured' => ! empty( $d['configured'] ),
		);
	}

	/** @return array{configured:bool, provider:string}|null */
	public static function job_sources_health(): ?array {
		if ( ! self::module_active( 'job-sources' ) && ! self::module_active( 'job_sources' ) ) {
			return null;
		}
		$d = Rest::payload( '/postelio/v1/job-sources/health' );
		return array( 'configured' => ! empty( $d['configured'] ) || ! empty( $d['providers'] ), 'provider' => 'france_travail' );
	}

	/** @return array<string,mixed>|null Compteurs de la file e-mail (contrat Notifications). */
	public static function delivery_stats(): ?array {
		$r = self::facade( '\\Postelio\\Notifications\\Api\\NotificationDirectory', 'delivery_stats' );
		return is_array( $r ) ? $r : null;
	}

	// ------------------------------------------------------------------ santé (délégué)

	/** @return array{core:array<string,mixed>, modules:array<int,array<string,mixed>>} */
	public static function health(): array {
		return Health::snapshot();
	}

	public static function health_global(): string {
		return Health::global_status();
	}

	public static function health_label( string $status ): string {
		return Health::label( $status );
	}

	public static function health_variant( string $status ): string {
		return Health::variant( $status );
	}
}
