<?php
/**
 * Contrat public d'IDENTITÉ DE FACTURATION d'une entreprise (consommé par postelio-billing).
 * Évite que Billing lise directement les meta `pst_legal_verified` : il obtient un snapshot
 * de l'identité légale VÉRIFIÉE (acheteur) via ce contrat. Lecture seule.
 *
 * @package Postelio\Companies\Api
 */

namespace Postelio\Companies\Api;

use Postelio\Companies\Companies\CompanyRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompanyBilling {

	/** Champs légaux figés lors de la vérification (voir VerificationService::LEGAL_KEYS). */
	private const LEGAL_KEYS = array(
		'raison_sociale', 'nom_commercial', 'forme_juridique', 'siren', 'siret',
		'tva', 'naf_ape', 'adresse_siege', 'cp_siege', 'ville_siege', 'pays',
	);

	/**
	 * Identité de facturation (acheteur). Retourne null si l'entreprise n'existe pas.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function identity( int $company_id ): ?array {
		$company = ( new CompanyRepository() )->get( $company_id );
		if ( null === $company ) {
			return null;
		}
		$legal_verified = is_array( $company['legal_verified'] ?? null ) ? $company['legal_verified'] : array();
		$status         = (string) ( $company['verification']['status'] ?? 'unverified' );

		$legal = array();
		foreach ( self::LEGAL_KEYS as $k ) {
			$legal[ $k ] = isset( $legal_verified[ $k ] ) ? (string) $legal_verified[ $k ] : null;
		}

		return array(
			'company_uuid'        => (string) ( $company['uuid'] ?? '' ),
			'name'                => (string) ( $company['nom'] ?? $company['name'] ?? '' ),
			'verification_status' => $status,
			'verified'            => 'verified' === $status,
			'suspended'           => 'suspended' === $status,
			'legal'               => $legal,
		);
	}
}
