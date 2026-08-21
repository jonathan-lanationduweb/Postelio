<?php
/**
 * Contrat public STABLE de vérification d'entreprise, destiné aux autres plugins
 * (postelio-jobs, etc.).
 *
 * Les consommateurs NE DOIVENT JAMAIS lire directement `pst_verification_status`,
 * les métadonnées WordPress, `legal_verified` ni le provider. Ils passent par :
 *   - cette façade : `CompanyVerification::can_publish_jobs($company_id)` … ; ou
 *   - les filtres WordPress équivalents (découplage total) :
 *       apply_filters('postelio/company/is_verified', false, $company_id)
 *       apply_filters('postelio/company/can_publish_jobs', false, $company_id)
 *       apply_filters('postelio/company/verification_status', 'unverified', $company_id)
 *
 * Règle V1 (D1) : une offre peut être créée en brouillon par une entreprise non
 * vérifiée, mais sa publication publique exige une entreprise `verified`.
 *
 * @package Postelio\Companies\Api
 */

namespace Postelio\Companies\Api;

use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Companies\Verification\VerificationStateMachine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompanyVerification {

	/**
	 * Statut de vérification canonique (voir VerificationStateMachine). Retourne
	 * `unverified` si l'entreprise est inconnue.
	 */
	public static function get_verification_status( int $company_id ): string {
		$company = ( new CompanyRepository() )->get( $company_id );
		if ( null === $company ) {
			return VerificationStateMachine::UNVERIFIED;
		}
		$status = (string) ( $company['verification']['status'] ?? VerificationStateMachine::UNVERIFIED );
		return VerificationStateMachine::is_status( $status ) ? $status : VerificationStateMachine::UNVERIFIED;
	}

	public static function is_verified( int $company_id ): bool {
		return VerificationStateMachine::VERIFIED === self::get_verification_status( $company_id );
	}

	/**
	 * L'entreprise peut-elle publier PUBLIQUEMENT une offre ? (brouillons non concernés.)
	 */
	public static function can_publish_jobs( int $company_id ): bool {
		return VerificationStateMachine::allows_publishing( self::get_verification_status( $company_id ) );
	}

	/**
	 * Branche les filtres WordPress équivalents (appelé une fois au boot).
	 */
	public static function register_filters(): void {
		add_filter( 'postelio/company/verification_status', static fn( $default, $id ) => self::get_verification_status( (int) $id ), 10, 2 );
		add_filter( 'postelio/company/is_verified', static fn( $default, $id ) => self::is_verified( (int) $id ), 10, 2 );
		add_filter( 'postelio/company/can_publish_jobs', static fn( $default, $id ) => self::can_publish_jobs( (int) $id ), 10, 2 );
	}
}
