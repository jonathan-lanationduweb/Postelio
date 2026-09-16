<?php
/**
 * Résolution SERVEUR de la ressource effective d'une décision de modération (M5).
 *
 * Auparavant, `decide()` acceptait un `target` (type + uuid) fourni LIBREMENT par la
 * requête et l'exécutait sans vérifier son lien avec la case : un modérateur autorisé sur
 * la Case A pouvait donc agir sur une Ressource B arbitraire (masquer/fermer/avertir, ou —
 * en admin — suspendre un utilisateur/entreprise/offre sans rapport). L'audit journalisait
 * en plus la ressource de la CASE, masquant la cible réelle.
 *
 * Désormais la cible n'est JAMAIS choisie par le client : elle est DÉRIVÉE de la case et de
 * l'action, via les contrats publics des domaines propriétaires. Le client ne peut plus
 * désigner un UUID arbitraire.
 *
 * Règles :
 *  - Actions sur le contenu (hide/unhide, close_conversation, suspend_job, suspend_company,
 *    warning, no_action/dismiss/escalate) → la ressource PROPRE de la case, si le type est
 *    compatible avec l'action ; sinon refus (409, non-divulgation).
 *  - Actions sur un utilisateur (suspend_user/unsuspend_user) → l'utilisateur RESPONSABLE
 *    dérivé de la case : offre → son créateur, entreprise → son propriétaire (contrats
 *    JobDirectory / CompanyDirectory / UserDirectory). Types sans dérivation d'utilisateur
 *    disponible → refus (409).
 *
 * @package Postelio\Moderation\Cases
 */

namespace Postelio\Moderation\Cases;

use Postelio\Core\ApiError;
use Postelio\Moderation\Actions\ModerationActions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CaseTargetResolver {

	private const JOB_DIR     = '\\Postelio\\Jobs\\Api\\JobDirectory';
	private const COMPANY_DIR = '\\Postelio\\Companies\\Api\\CompanyDirectory';
	private const USER_DIR    = '\\Postelio\\Users\\Api\\UserDirectory';

	/** Types de case sur lesquels `hide`/`unhide` s'appliquent (contenu masquable). */
	private const HIDEABLE = array( 'skill', 'external_job', 'job' );

	/**
	 * Retourne la ressource effective [resource_type, resource_uuid] à traiter, dérivée de
	 * la case. Lève une ApiError (409) si l'action est incompatible avec la case.
	 *
	 * @param array<string,mixed> $case Ligne de case (resource_type, resource_uuid).
	 * @return array{0:string,1:string}
	 * @throws ApiError
	 */
	public static function resolve( array $case, string $action ): array {
		$rtype = (string) ( $case['resource_type'] ?? '' );
		$ruuid = (string) ( $case['resource_uuid'] ?? '' );

		switch ( $action ) {
			// Actions d'état pur (aucune ressource touchée) — on renvoie la ressource de la
			// case pour la traçabilité, sans contrainte de type.
			case 'no_action':
			case 'dismiss':
			case 'escalate':
			case 'warning':
				return array( $rtype, $ruuid );

			case 'hide':
			case 'unhide':
				return self::require_type( $rtype, $ruuid, self::HIDEABLE, $action );

			case 'close_conversation':
				return self::require_type( $rtype, $ruuid, array( 'conversation' ), $action );

			case 'suspend_job':
			case 'unsuspend_job':
				return self::require_type( $rtype, $ruuid, array( 'job' ), $action );

			case 'suspend_company':
			case 'unsuspend_company':
				return self::require_type( $rtype, $ruuid, array( 'company' ), $action );

			case 'suspend_user':
			case 'unsuspend_user':
				$user_uuid = self::responsible_user( $rtype, $ruuid );
				if ( '' === $user_uuid ) {
					throw new ApiError( 'invalid_transition', 'Aucun utilisateur responsable n\'est dérivable de cette case pour cette action.' );
				}
				return array( 'user', $user_uuid );
		}

		// Action inconnue : rejet (le contrôleur valide déjà, garde-fou).
		if ( ! ModerationActions::is_valid( $action ) ) {
			throw ApiError::validation( array( 'action' => 'Action inconnue.' ) );
		}
		return array( $rtype, $ruuid );
	}

	/**
	 * @param string[] $allowed
	 * @return array{0:string,1:string}
	 * @throws ApiError
	 */
	private static function require_type( string $rtype, string $ruuid, array $allowed, string $action ): array {
		if ( ! in_array( $rtype, $allowed, true ) ) {
			// Non-divulgation : on ne détaille jamais une autre ressource.
			throw new ApiError( 'invalid_transition', 'Action incompatible avec la ressource de cette case.' );
		}
		return array( $rtype, $ruuid );
	}

	/**
	 * Utilisateur responsable dérivé de la ressource de la case (UUID public), ou '' si non
	 * dérivable. offre → créateur ; entreprise → propriétaire.
	 */
	private static function responsible_user( string $rtype, string $ruuid ): string {
		$user_id = 0;
		if ( 'job' === $rtype && class_exists( self::JOB_DIR ) ) {
			$job_id  = (int) call_user_func( array( self::JOB_DIR, 'id_from_uuid' ), $ruuid );
			$user_id = $job_id > 0 ? (int) call_user_func( array( self::JOB_DIR, 'created_by' ), $job_id ) : 0;
		} elseif ( 'company' === $rtype && class_exists( self::COMPANY_DIR ) ) {
			$company_id = (int) call_user_func( array( self::COMPANY_DIR, 'id_from_uuid' ), $ruuid );
			$owner      = $company_id > 0 ? call_user_func( array( self::COMPANY_DIR, 'owner_of' ), $company_id ) : null;
			$user_id    = null !== $owner ? (int) $owner : 0;
		}
		if ( $user_id <= 0 || ! class_exists( self::USER_DIR ) ) {
			return '';
		}
		return (string) call_user_func( array( self::USER_DIR, 'public_uuid' ), $user_id );
	}
}
