<?php
/**
 * Contrôle centralisé de la vérification d'e-mail (décision V1).
 *
 * Expose une **capability virtuelle** `pst_email_verified`, accordée dynamiquement
 * à un utilisateur dont l'e-mail est vérifié ET le compte actif. Les futurs plugins
 * (companies, jobs, applications, messaging…) n'ont qu'à composer cette capability
 * avec la leur — sans dépendre de ce plugin :
 *
 *   Guard::require_all( 'pst_apply_job', 'pst_email_verified' )
 *
 * Ce lot ne pose AUCUNE restriction métier ; il fournit seulement le contrat.
 *
 * @package Postelio\Users\Verification
 */

namespace Postelio\Users\Verification;

use Postelio\Users\Users\AccountService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EmailVerification {

	/** Capability virtuelle exposée aux autres plugins. */
	public const CAP = 'pst_email_verified';

	public function register(): void {
		add_filter( 'user_has_cap', array( $this, 'grant_virtual_cap' ), 10, 4 );
	}

	/**
	 * Accorde `pst_email_verified` si l'utilisateur est vérifié et actif.
	 *
	 * @param array<string, bool> $allcaps Capabilities effectives.
	 * @param string[]            $caps    Capabilities demandées (non utilisé ici).
	 * @param array<int, mixed>   $args    Arguments de la vérification.
	 * @param \WP_User|null       $user    Utilisateur concerné.
	 * @return array<string, bool>
	 */
	public function grant_virtual_cap( array $allcaps, array $caps, array $args, $user ): array {
		$user_id = ( $user instanceof \WP_User ) ? (int) $user->ID : 0;
		if ( $user_id > 0 && self::is_verified( $user_id ) ) {
			$allcaps[ self::CAP ] = true;
		} else {
			unset( $allcaps[ self::CAP ] );
		}
		return $allcaps;
	}

	/**
	 * L'utilisateur a-t-il un e-mail vérifié et un compte actif ?
	 */
	public static function is_verified( int $user_id ): bool {
		if ( AccountService::STATUS_ACTIVE !== AccountService::status( $user_id ) ) {
			return false;
		}
		return '' !== (string) get_user_meta( $user_id, AccountService::META_EMAIL_VERIFIED, true );
	}
}
