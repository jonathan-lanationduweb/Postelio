<?php
/**
 * Contrat public STABLE des CV, destiné à postelio-applications.
 *
 * Permet de valider qu'un CV appartient au candidat et est utilisable, SANS que
 * applications manipule le stockage ni les meta de files. Le fichier étant immuable
 * (un nouvel upload = une nouvelle ressource), référencer son UUID depuis une
 * candidature garantit la conservation (« snapshot ») sans copie physique.
 *
 * @package Postelio\Files\Api
 */

namespace Postelio\Files\Api;

use Postelio\Files\Files\CvService;
use Postelio\Files\Files\FileRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FileCvContract {

	/**
	 * Le CV (UUID public) appartient-il au candidat et est-il utilisable pour une
	 * candidature (type cv, statut `ready`) ?
	 */
	public static function usable_for_application( string $cv_uuid, int $candidate_id ): bool {
		$f = ( new FileRepository() )->get_by_uuid( $cv_uuid );
		return null !== $f
			&& CvService::TYPE === $f['type']
			&& (int) $f['owner_user_id'] === $candidate_id
			&& 'ready' === $f['status'];
	}

	/** Le CV existe-t-il (indépendamment du statut) ? */
	public static function exists( string $cv_uuid ): bool {
		return null !== ( new FileRepository() )->get_by_uuid( $cv_uuid );
	}

	/**
	 * Le CV est-il un CV GUEST utilisable (propriétaire 0, type cv, statut `ready`) et pas
	 * encore rattaché à un compte ? Le parcours guest stocke le CV avec le propriétaire 0.
	 */
	public static function usable_guest_cv( string $cv_uuid ): bool {
		return self::usable_for_application( $cv_uuid, 0 );
	}

	/**
	 * Ré-attribue un CV guest (propriétaire 0) à un compte candidat, à la matérialisation de
	 * la candidature. Refuse si le fichier n'est pas un CV guest utilisable. Retourne true si
	 * ré-attribué.
	 */
	public static function reassign_to_user( string $cv_uuid, int $user_id ): bool {
		if ( $user_id <= 0 || ! self::usable_guest_cv( $cv_uuid ) ) {
			return false;
		}
		$repo = new FileRepository();
		$f    = $repo->get_by_uuid( $cv_uuid );
		if ( null === $f ) {
			return false;
		}
		$repo->reassign_owner( (int) $f['id'], $user_id );
		return true;
	}

	/**
	 * Métadonnées minimales pour un snapshot (nom d'origine), ou null.
	 *
	 * @return array{uuid:string, name:?string}|null
	 */
	public static function snapshot_meta( string $cv_uuid ): ?array {
		$f = ( new FileRepository() )->get_by_uuid( $cv_uuid );
		return $f ? array( 'uuid' => (string) $f['public_uuid'], 'name' => $f['original_name'] ) : null;
	}
}
