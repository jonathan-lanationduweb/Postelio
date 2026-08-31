<?php
/**
 * Agrégation des KPI du tableau de bord. Chaque valeur provient d'un contrat public / d'un
 * endpoint REST interne / d'une lecture WordPress native (users, CPT). Aucune donnée inventée :
 * une valeur non disponible proprement renvoie `null` (affichée « — »). Aucun SQL direct dans
 * les tables d'un autre plugin.
 *
 * @package Postelio\Admin\Support
 */

namespace Postelio\Admin\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Metrics {

	/** @return array<string,int> Comptes d'utilisateurs par rôle (WordPress natif). */
	public static function user_counts(): array {
		$c     = function_exists( 'count_users' ) ? count_users() : array( 'avail_roles' => array() );
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
			'meta_key'    => \Postelio\Users\Users\AccountService::META_STATUS,
			'meta_value'  => \Postelio\Users\Users\AccountService::STATUS_SUSPENDED,
			'count_total' => true,
			'number'      => 1,
			'fields'      => 'ID',
		) );
		return (int) $q->get_total();
	}

	/** @return array<string,int>|null Compteurs entreprises par statut de vérification. */
	public static function company_counts(): ?array {
		if ( ! Contracts::has( '\\Postelio\\Companies\\Api\\CompanyAdminDirectory' ) ) {
			return null;
		}
		return \Postelio\Companies\Api\CompanyAdminDirectory::counts();
	}

	/** @return array<string,int>|null Compteurs offres natives par statut métier. */
	public static function job_counts(): ?array {
		if ( ! Contracts::has( '\\Postelio\\Jobs\\Api\\JobAdminDirectory' ) ) {
			return null;
		}
		return \Postelio\Jobs\Api\JobAdminDirectory::counts();
	}

	public static function moderation_open(): ?int {
		if ( ! Contracts::module_active( 'moderation' ) || ! Contracts::has( '\\Postelio\\Moderation\\Api\\ModerationDirectory' ) ) {
			return null;
		}
		return \Postelio\Moderation\Api\ModerationDirectory::open_cases_count();
	}

	public static function moderation_critical(): ?int {
		if ( ! Contracts::module_active( 'moderation' ) ) {
			return null;
		}
		return Contracts::rest_total( '/postelio/v1/moderation/cases', array( 'status' => 'open', 'priority' => 'critical' ) );
	}

	/** Savoir-faire publiés (liste publique = published + visible). */
	public static function skills_published(): ?int {
		if ( ! Contracts::module_active( 'skills' ) ) {
			return null;
		}
		return Contracts::rest_total( '/postelio/v1/skills' );
	}

	/** @return array{paid:?int, failed:?int, mode:string, configured:bool}|null */
	public static function billing_health(): ?array {
		if ( ! Contracts::module_active( 'billing' ) ) {
			return null;
		}
		$h = Contracts::rest( 'GET', '/postelio/v1/billing/health' );
		if ( 200 !== $h['status'] || ! is_array( $h['data'] ) ) {
			return array( 'paid' => null, 'failed' => null, 'mode' => 'unknown', 'configured' => false );
		}
		$d = $h['data']['data'] ?? array();
		return array(
			'paid'       => Contracts::rest_total( '/postelio/v1/billing/admin/orders', array( 'status' => 'fulfilled' ) ),
			'failed'     => isset( $d['failed_fulfillment_count'] ) ? (int) $d['failed_fulfillment_count'] : null,
			'mode'       => (string) ( $d['mode'] ?? 'unknown' ),
			'configured' => (bool) ( $d['configured'] ?? false ),
		);
	}

	/** @return array{configured:bool, provider:string}|null État synthétique des sources d'offres. */
	public static function job_sources_health(): ?array {
		if ( ! Contracts::module_active( 'job-sources' ) && ! Contracts::module_active( 'job_sources' ) ) {
			return null;
		}
		$h = Contracts::rest( 'GET', '/postelio/v1/job-sources/health' );
		if ( 200 !== $h['status'] || ! is_array( $h['data'] ) ) {
			return array( 'configured' => false, 'provider' => 'france_travail' );
		}
		$d = $h['data']['data'] ?? $h['data'];
		return array( 'configured' => (bool) ( $d['configured'] ?? false ), 'provider' => 'france_travail' );
	}
}
