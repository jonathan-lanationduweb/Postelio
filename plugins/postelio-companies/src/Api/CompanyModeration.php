<?php
/**
 * Contrat public de modération d'entreprise (consommé par postelio-moderation). Délègue à
 * `VerificationService::decide` (suspended / verified) — jamais d'UPDATE direct. La légalité
 * (SIREN/RNE) reste du ressort de la Vérification ; ici on ne fait que suspendre/réactiver.
 *
 * @package Postelio\Companies\Api
 */

namespace Postelio\Companies\Api;

use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Companies\Verification\ManualVerificationProvider;
use Postelio\Companies\Verification\VerificationService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompanyModeration {

	public static function suspend( int $actor_id, string $company_uuid, string $motif = '' ): bool {
		$id = CompanyDirectory::id_from_uuid( $company_uuid );
		if ( $id <= 0 ) {
			return false;
		}
		self::service()->decide( $id, $actor_id, 'suspended', $motif );
		return true;
	}

	/** Réactivation : `suspended → verified` (re-vérification). */
	public static function unsuspend( int $actor_id, string $company_uuid ): bool {
		$id = CompanyDirectory::id_from_uuid( $company_uuid );
		if ( $id <= 0 ) {
			return false;
		}
		self::service()->decide( $id, $actor_id, 'verified' );
		return true;
	}

	private static function service(): VerificationService {
		return new VerificationService( new CompanyRepository(), new ManualVerificationProvider() );
	}
}
