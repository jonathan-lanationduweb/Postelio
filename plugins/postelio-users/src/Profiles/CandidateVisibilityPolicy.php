<?php
/**
 * Politique d'accès d'un recruteur au profil d'un candidat (H2).
 *
 * `GET /candidates/{uuid}` ne rejetait que la visibilité `masque` : tout détenteur de
 * `pst_view_company_applications` (donc tout recruteur auto-inscrit) pouvait lire un
 * profil `candidatees` (« visible uniquement aux entreprises auxquelles je candidate »),
 * et `blocked_companies` n'était jamais comparé à l'appelant.
 *
 * Cette primitive métier — testable et unique — décide si un recruteur donné peut voir
 * un profil, selon sa visibilité et l'appartenance réelle du recruteur à une entreprise :
 *
 *  - `masque`      → jamais (indistinct d'un profil inexistant, 404 côté contrôleur) ;
 *  - entreprise bloquée par le candidat (`blocked_companies`) → jamais (PRIORITAIRE) ;
 *  - `candidatees` → uniquement si l'entreprise de l'appelant a REÇU une candidature du
 *                    candidat (quel que soit son état — décision métier : l'entreprise a
 *                    déjà reçu le CV/les coordonnées, l'historique est conservé) ;
 *  - `recruteurs`  → visible aux entreprises actives non bloquées.
 *
 * Dans tous les cas l'appelant doit appartenir à une entreprise NON suspendue : un
 * recruteur sans entreprise (ou dont l'entreprise est suspendue) n'accède à aucun profil.
 * Le simple rôle `recruiter` ne suffit jamais.
 *
 * Les données transverses (appartenance entreprise, état, candidatures) sont lues via les
 * contrats publics `CompanyDirectory` / `ApplicationDirectory` — aucune requête SQL métier
 * n'est dupliquée ici. Si un de ces contrats est indisponible, la politique échoue en mode
 * fermé (accès refusé).
 *
 * @package Postelio\Users\Profiles
 */

namespace Postelio\Users\Profiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CandidateVisibilityPolicy {

	private const COMPANY_DIR     = '\\Postelio\\Companies\\Api\\CompanyDirectory';
	private const APPLICATION_DIR = '\\Postelio\\Applications\\Api\\ApplicationDirectory';

	/**
	 * Le recruteur (utilisateur courant) peut-il consulter ce profil candidat ?
	 *
	 * @param array<string, mixed> $profile Profil candidat décodé (inclut `user_id`,
	 *                                       `profile_visibility`, `blocked_companies`).
	 */
	public static function recruiter_can_view( array $profile, int $recruiter_user_id ): bool {
		$visibility = (string) ( $profile['profile_visibility'] ?? 'recruteurs' );

		// Profil masqué : indistinct d'un profil inexistant.
		if ( 'masque' === $visibility ) {
			return false;
		}

		// Appartenance réelle : le recruteur doit être membre d'une entreprise.
		$company_id = self::recruiter_company( $recruiter_user_id );
		if ( $company_id <= 0 ) {
			return false;
		}

		// Entreprise suspendue : aucun accès (état pertinent).
		if ( self::company_suspended( $company_id ) ) {
			return false;
		}

		// Blocage candidat → entreprise : PRIORITAIRE sur toute visibilité.
		if ( self::company_is_blocked( $profile, $company_id ) ) {
			return false;
		}

		// Visibilité restreinte aux entreprises auxquelles le candidat postule.
		if ( 'candidatees' === $visibility ) {
			$candidate_user_id = (int) ( $profile['user_id'] ?? 0 );
			return $candidate_user_id > 0
				&& self::company_has_application_from( $company_id, $candidate_user_id );
		}

		// `recruteurs` (défaut) : visible aux entreprises actives non bloquées.
		return true;
	}

	/** Entreprise (ID interne) dont le recruteur est membre, 0 sinon. */
	private static function recruiter_company( int $user_id ): int {
		if ( $user_id <= 0 || ! class_exists( self::COMPANY_DIR ) ) {
			return 0;
		}
		return (int) call_user_func( array( self::COMPANY_DIR, 'company_of_user' ), $user_id );
	}

	private static function company_suspended( int $company_id ): bool {
		if ( ! is_callable( array( self::COMPANY_DIR, 'is_suspended' ) ) ) {
			// Contrat indisponible : impossible de confirmer l'état → mode fermé.
			return true;
		}
		return (bool) call_user_func( array( self::COMPANY_DIR, 'is_suspended' ), $company_id );
	}

	/**
	 * L'entreprise est-elle bloquée par le candidat ? Le stockage `blocked_companies`
	 * n'a pas de format d'identifiant imposé (le front actuel y écrit des noms) : on
	 * compare donc l'UUID public ET le nom normalisé de l'entreprise. Un blocage est une
	 * liste de refus — une correspondance large est le sens sûr (sur-bloquer, pas fuir).
	 *
	 * @param array<string, mixed> $profile
	 */
	private static function company_is_blocked( array $profile, int $company_id ): bool {
		$blocked = $profile['blocked_companies'] ?? null;
		if ( ! is_array( $blocked ) || array() === $blocked ) {
			return false;
		}

		$needles = array();
		if ( is_callable( array( self::COMPANY_DIR, 'uuid_of' ) ) ) {
			$uuid = (string) call_user_func( array( self::COMPANY_DIR, 'uuid_of' ), $company_id );
			if ( '' !== $uuid ) {
				$needles[] = self::normalize( $uuid );
			}
		}
		if ( is_callable( array( self::COMPANY_DIR, 'name_of' ) ) ) {
			$name = (string) call_user_func( array( self::COMPANY_DIR, 'name_of' ), $company_id );
			if ( '' !== $name ) {
				$needles[] = self::normalize( $name );
			}
		}
		if ( array() === $needles ) {
			return false;
		}

		foreach ( $blocked as $entry ) {
			$hay = self::normalize( is_scalar( $entry ) ? (string) $entry : '' );
			if ( '' !== $hay && in_array( $hay, $needles, true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function company_has_application_from( int $company_id, int $candidate_user_id ): bool {
		if ( ! is_callable( array( self::APPLICATION_DIR, 'company_has_application_from_candidate' ) ) ) {
			// Contrat indisponible : impossible de confirmer la relation → mode fermé.
			return false;
		}
		return (bool) call_user_func(
			array( self::APPLICATION_DIR, 'company_has_application_from_candidate' ),
			$company_id,
			$candidate_user_id
		);
	}

	private static function normalize( string $value ): string {
		return strtolower( trim( $value ) );
	}
}
