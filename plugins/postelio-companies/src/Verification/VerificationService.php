<?php
/**
 * Workflow de vérification d'entreprise.
 *
 * États : unverified → pending → (verified | rejected | manual_review) ; verified →
 * suspended ; manual_review → (verified | rejected). Le recruteur ne peut JAMAIS se
 * déclarer `verified` : seule une décision admin (ou un provider automatique validé)
 * fige l'identité légale vérifiée. Réutilisable par postelio-jobs (D1) via
 * `is_verified()`.
 *
 * @package Postelio\Companies\Verification
 */

namespace Postelio\Companies\Verification;

use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VerificationService {

	public const STATUSES = array( 'unverified', 'pending', 'manual_review', 'verified', 'rejected', 'suspended' );

	/** Champs d'identité légale figés lors d'une vérification. */
	private const LEGAL_KEYS = array(
		'raison_sociale',
		'nom_commercial',
		'forme_juridique',
		'siren',
		'siret',
		'tva',
		'naf_ape',
		'adresse_siege',
		'cp_siege',
		'ville_siege',
		'pays',
		'date_creation',
	);

	private CompanyRepository $companies;
	private VerificationProvider $provider;

	public function __construct( CompanyRepository $companies, VerificationProvider $provider ) {
		$this->companies = $companies;
		$this->provider  = $provider;
	}

	public function status( int $company_id ): string {
		$company = $this->companies->get( $company_id );
		return (string) ( $company['verification']['status'] ?? 'unverified' );
	}

	public function is_verified( int $company_id ): bool {
		return 'verified' === $this->status( $company_id );
	}

	/**
	 * Demande de vérification par un recruteur. Valide l'identité déclarée, détecte
	 * les doublons de SIREN, puis applique le résultat du provider. Retourne l'état
	 * de vérification à jour.
	 *
	 * @return array<string, mixed>
	 * @throws ApiError validation_error | conflict | invalid_transition
	 */
	public function request( int $company_id, int $actor_id ): array {
		$company = $this->companies->get( $company_id );
		if ( null === $company ) {
			throw ApiError::not_found();
		}
		$current = (string) ( $company['verification']['status'] ?? 'unverified' );
		// La demande fait passer à `pending` : transition doit être autorisée.
		if ( ! VerificationStateMachine::can_transition( $current, VerificationStateMachine::PENDING ) ) {
			throw new ApiError( 'invalid_transition', 'Vérification non demandable depuis l\'état « ' . $current . ' ».' );
		}

		$legal = $company['legal_declared'] ?? array();
		$errors = array();
		if ( ! Siren::is_valid_siren( (string) ( $legal['siren'] ?? '' ) ) ) {
			$errors['siren'] = 'SIREN invalide (9 chiffres, clé de Luhn).';
		}
		if ( ! empty( $legal['siret'] ) && ! Siren::is_valid_siret( (string) $legal['siret'] ) ) {
			$errors['siret'] = 'SIRET invalide (14 chiffres, clé de Luhn).';
		}
		if ( ! empty( $errors ) ) {
			throw ApiError::validation( $errors );
		}

		// 1. Passage à `pending`.
		$verification = array(
			'status'            => VerificationStateMachine::PENDING,
			'provider'          => $this->provider->name(),
			'requested_at'      => current_time( 'mysql', true ),
			'requested_by'      => $actor_id,
			'verified_at'       => null,
			'verified_legal_id' => null,
			'reviewer_id'       => null,
			'motif'             => null,
		);
		$this->companies->set_verification( $company_id, $verification );
		$this->emit( 'company.verification_requested', $company_id, array( 'provider' => $this->provider->name() ) );

		// 2. Anti-doublon : `conflict` = motif de manual_review (pas un état).
		$dupe = $this->companies->find_id_by_siren( (string) $legal['siren'], $company_id );
		if ( $dupe > 0 ) {
			$verification['status'] = VerificationStateMachine::MANUAL_REVIEW;
			$verification['motif']  = 'duplicate_siren';
			$this->companies->set_verification( $company_id, $verification );
			return $verification;
		}

		// 3. Résultat du provider (transition depuis `pending`).
		$result = $this->provider->check( $legal );
		switch ( $result['outcome'] ?? 'manual_review' ) {
			case 'verified':
				return $this->apply_verified( $company_id, $legal, $result['legal'] ?? $legal, $actor_id, $verification, false );
			case 'rejected':
				$verification['status'] = VerificationStateMachine::REJECTED;
				$verification['motif']  = (string) ( $result['motif'] ?? 'provider_rejected' );
				$this->companies->set_verification( $company_id, $verification );
				$this->emit( 'company.rejected', $company_id, array( 'motif' => $verification['motif'] ) );
				return $verification;
			case 'manual_review':
			default:
				$verification['status'] = VerificationStateMachine::MANUAL_REVIEW;
				$this->companies->set_verification( $company_id, $verification );
				return $verification;
		}
	}

	/**
	 * Décision administrateur. $decision ∈ verified | rejected | manual_review | suspended.
	 *
	 * @return array<string, mixed>
	 * @throws ApiError
	 */
	public function decide( int $company_id, int $admin_id, string $decision, string $motif = '' ): array {
		$company = $this->companies->get( $company_id );
		if ( null === $company ) {
			throw ApiError::not_found();
		}
		if ( ! in_array( $decision, VerificationStateMachine::admin_decisions(), true ) ) {
			throw ApiError::validation( array( 'decision' => 'Décision inconnue.' ) );
		}
		$current = (string) ( $company['verification']['status'] ?? 'unverified' );
		if ( ! VerificationStateMachine::can_transition( $current, $decision ) ) {
			throw new ApiError( 'invalid_transition', 'Transition « ' . $current . ' → ' . $decision . ' » non autorisée.' );
		}

		$verification = $company['verification'] ?? array( 'status' => 'unverified' );
		$verification['reviewer_id'] = $admin_id;
		$verification['provider']    = $verification['provider'] ?? $this->provider->name();

		switch ( $decision ) {
			case 'verified':
				return $this->apply_verified( $company_id, $company['legal_declared'] ?? array(), $company['legal_declared'] ?? array(), $admin_id, $verification, true );
			case 'rejected':
				$verification['status'] = 'rejected';
				$verification['motif']  = $motif;
				$this->companies->set_verification( $company_id, $verification );
				$this->emit( 'company.rejected', $company_id, array( 'motif' => $motif, 'by' => 'admin' ) );
				return $verification;
			case 'suspended':
				$verification['status'] = 'suspended';
				$verification['motif']  = $motif;
				$this->companies->set_verification( $company_id, $verification );
				$this->emit( 'company.suspended', $company_id, array( 'motif' => $motif ) );
				return $verification;
			case 'manual_review':
				$verification['status'] = 'manual_review';
				$this->companies->set_verification( $company_id, $verification );
				return $verification;
			default:
				throw ApiError::validation( array( 'decision' => 'Décision inconnue.' ) );
		}
	}

	/**
	 * Fige l'identité légale vérifiée et passe en `verified`.
	 *
	 * @param array<string, mixed> $declared
	 * @param array<string, mixed> $verified_source
	 * @param array<string, mixed> $verification
	 * @return array<string, mixed>
	 */
	private function apply_verified( int $company_id, array $declared, array $verified_source, int $actor_id, array $verification, bool $by_admin ): array {
		$verified_legal = array();
		foreach ( self::LEGAL_KEYS as $k ) {
			$verified_legal[ $k ] = $verified_source[ $k ] ?? ( $declared[ $k ] ?? null );
		}
		$this->companies->set_legal_verified( $company_id, $verified_legal );

		$verification['status']            = 'verified';
		$verification['verified_at']       = current_time( 'mysql', true );
		$verification['verified_legal_id'] = (string) ( $verified_legal['siren'] ?? '' );
		$verification['reviewer_id']       = $by_admin ? $actor_id : null;
		$verification['motif']             = null;
		$this->companies->set_verification( $company_id, $verification );

		$this->emit(
			'company.verified',
			$company_id,
			array( 'provider' => $verification['provider'] ?? 'manual', 'by' => $by_admin ? 'admin' : 'provider' )
		);
		return $verification;
	}

	/**
	 * @param array<string, mixed> $audit
	 */
	private function emit( string $event, int $company_id, array $audit = array() ): void {
		Core::instance()->events()->emit(
			$event,
			array(
				'company_id'    => $company_id,
				'resource_type' => 'company',
				'resource_id'   => (string) $company_id,
				'audit'         => $audit,
			)
		);
	}
}
