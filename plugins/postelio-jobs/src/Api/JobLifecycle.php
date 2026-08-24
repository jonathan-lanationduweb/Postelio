<?php
/**
 * Contrat public STABLE du cycle de vie des offres, destiné au futur
 * `postelio-billing`.
 *
 * Billing NE DOIT JAMAIS écrire `pst_status`, `pst_date_expiration`, etc. Il passe
 * par ce contrat :
 *   - `can_renew($job_id)` : l'offre est-elle renouvelable (expiring|expired) ;
 *   - `renew_after_payment($job_id, $days, $meta)` : applique le renouvellement
 *     APRÈS un paiement validé (transition `expired|expiring → published`, nouvelle
 *     échéance, compteur, événement `job.renewed`).
 *
 * AUCUN paiement n'est implémenté ici : ce n'est que le point d'entrée que billing
 * appellera (typiquement sur `payment.succeeded`).
 *
 * @package Postelio\Jobs\Api
 */

namespace Postelio\Jobs\Api;

use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;
use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Jobs\Jobs\JobStateMachine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobLifecycle {

	public const DEFAULT_DAYS = 30;

	private static function repo(): JobRepository {
		return new JobRepository();
	}

	public static function status( int $job_id ): ?string {
		$j = self::repo()->get( $job_id );
		return $j ? (string) $j['status'] : null;
	}

	/** Une offre est renouvelable si elle est bientôt expirée ou expirée. */
	public static function can_renew( int $job_id ): bool {
		$s = self::status( $job_id );
		return in_array( $s, array( JobStateMachine::EXPIRING, JobStateMachine::EXPIRED ), true );
	}

	/**
	 * Applique un renouvellement après paiement validé (appelé par billing).
	 *
	 * Idempotence (exactly-once) : si `$meta['idempotency_key']` est fourni (= order_uuid), le
	 * renouvellement est piloté par un registre côté Jobs. Un rejeu avec la même clé N'ajoute
	 * PAS un second renouvellement et N'émet PAS un second `job.renewed`. Sans clé, comportement
	 * historique (compat ascendante) : contrôle `can_renew` strict + application incrémentale.
	 *
	 * @param array<string, mixed> $meta  Traçabilité (provider_ref) + `idempotency_key` optionnel.
	 * @return array<string, mixed> Offre à jour.
	 * @throws ApiError invalid_transition | not_found
	 */
	public static function renew_after_payment( int $job_id, int $days = self::DEFAULT_DAYS, array $meta = array() ): array {
		$repo = self::repo();
		$job  = $repo->get( $job_id );
		if ( null === $job ) {
			throw ApiError::not_found();
		}
		$days = $days > 0 ? $days : self::DEFAULT_DAYS;
		$ref  = isset( $meta['idempotency_key'] ) ? (string) $meta['idempotency_key'] : '';

		if ( '' !== $ref ) {
			$already = isset( $repo->renewal_ledger( $job_id )[ $ref ] );
			// Rejeu déjà appliqué : SET absolu idempotent, aucun événement dupliqué.
			if ( ! $already && ! self::can_renew( $job_id ) ) {
				throw new ApiError( 'invalid_transition', 'Offre non renouvelable (statut : ' . $job['status'] . ').' );
			}
			$result = $repo->apply_renewal_idempotent( $job_id, $days, $ref );
			if ( ! $result['already_applied'] ) {
				self::emit_renewed( $job_id, (int) $job['company']['id'], $result, $meta );
			}
			return $repo->get( $job_id );
		}

		if ( ! self::can_renew( $job_id ) ) {
			throw new ApiError( 'invalid_transition', 'Offre non renouvelable (statut : ' . $job['status'] . ').' );
		}
		$result = $repo->apply_renewal( $job_id, $days );
		self::emit_renewed( $job_id, (int) $job['company']['id'], $result, $meta );
		return $repo->get( $job_id );
	}

	/**
	 * @param array{new_expiration:string, count:int} $result
	 * @param array<string,mixed> $meta
	 */
	private static function emit_renewed( int $job_id, int $company_id, array $result, array $meta ): void {
		Core::instance()->events()->emit(
			'job.renewed',
			array(
				'job_id'        => $job_id,
				'company_id'    => $company_id,
				'resource_type' => 'job',
				'resource_id'   => (string) $job_id,
				'audit'         => array(
					'new_expiration' => $result['new_expiration'],
					'renewal_count'  => $result['count'],
					'provider_ref'   => isset( $meta['provider_ref'] ) ? (string) $meta['provider_ref'] : null,
				),
			)
		);
	}
}
