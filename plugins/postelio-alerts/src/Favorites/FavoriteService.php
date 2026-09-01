<?php
/**
 * Service des favoris (candidat). add/remove/list/count. Ajout idempotent (contrainte UNIQUE) ;
 * un favori peut référencer une offre native ou externe. Toute l'identité d'offre passe par le
 * contrat JobDirectory — aucune lecture directe des tables d'offres.
 *
 * Sécurité : le candidat est toujours celui de la session (jamais un paramètre). Compte suspendu
 * → aucune mutation (levée en amont par le contrôleur). Quota V1 filtrable.
 *
 * @package Postelio\Alerts\Favorites
 */

namespace Postelio\Alerts\Favorites;

use Postelio\Core\ApiError;
use Postelio\Core\Events;
use Postelio\Jobs\Api\JobDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FavoriteService {

	private FavoriteRepository $repo;
	private Events $events;

	public function __construct( FavoriteRepository $repo, Events $events ) {
		$this->repo   = $repo;
		$this->events = $events;
	}

	public function repository(): FavoriteRepository {
		return $this->repo;
	}

	/** Quota de favoris par candidat (filtrable). */
	private function max_favorites(): int {
		return max( 1, (int) apply_filters( 'postelio/alerts/max_favorites', 500 ) );
	}

	/**
	 * Ajoute un favori pour l'offre publique $job_uuid. Idempotent. Retourne la carte publique
	 * de l'offre + l'état. Offre inconnue → 404. Quota atteint (nouvel ajout) → 409.
	 *
	 * @return array<string, mixed>
	 */
	public function add( int $candidate_user_id, string $job_uuid ): array {
		$source = JobDirectory::resolve_source( $job_uuid );
		if ( null === $source ) {
			throw ApiError::not_found( 'Offre introuvable.' );
		}
		$reference = $job_uuid;

		if ( ! $this->repo->exists( $candidate_user_id, $source, $reference )
			&& $this->repo->count_for_candidate( $candidate_user_id ) >= $this->max_favorites() ) {
			throw new ApiError( 'conflict', 'Nombre maximal de favoris atteint.', array(
				'limit'  => $this->max_favorites(),
				'reason' => 'favorites_quota',
			) );
		}

		$created = $this->repo->add( $candidate_user_id, $source, $reference );
		if ( $created ) {
			$this->events->emit( 'favorite.created', array(
				'candidate_user_id' => $candidate_user_id,
				'job_source'        => $source,
				'job_reference'     => $reference,
				'resource_type'     => 'job_favorite',
				'resource_id'       => (string) $candidate_user_id,
			) );
		}
		$view            = $this->view( $source, $reference );
		$view['created'] = $created; // false = déjà présent (idempotence)
		return $view;
	}

	/** Retire un favori (idempotent : succès même si absent). */
	public function remove( int $candidate_user_id, string $job_uuid ): void {
		$source = JobDirectory::resolve_source( $job_uuid );
		// Même si l'offre est devenue inconnue, on doit pouvoir retirer un favori « fantôme » :
		// on tente le retrait pour les deux espaces de noms possibles.
		$sources = null !== $source ? array( $source ) : array( 'native', 'external' );
		$removed = false;
		foreach ( $sources as $s ) {
			if ( $this->repo->remove( $candidate_user_id, $s, $job_uuid ) ) {
				$removed = true;
				$this->events->emit( 'favorite.removed', array(
					'candidate_user_id' => $candidate_user_id,
					'job_source'        => $s,
					'job_reference'     => $job_uuid,
					'resource_type'     => 'job_favorite',
					'resource_id'       => (string) $candidate_user_id,
				) );
			}
		}
		unset( $removed );
	}

	/**
	 * Liste paginée des favoris (carte publique + disponibilité).
	 *
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function list( int $candidate_user_id, int $page, int $per_page ): array {
		$res   = $this->repo->list_for_candidate( $candidate_user_id, $page, $per_page );
		$items = array();
		foreach ( $res['items'] as $row ) {
			$items[] = $this->view( (string) $row['job_source'], (string) $row['job_reference'], (string) $row['created_at'] );
		}
		return array( 'items' => $items, 'total' => (int) $res['total'] );
	}

	public function count( int $candidate_user_id ): int {
		return $this->repo->count_for_candidate( $candidate_user_id );
	}

	/**
	 * Carte publique d'un favori. Offre disparue → available:false + informations minimales.
	 *
	 * @return array<string, mixed>
	 */
	private function view( string $source, string $reference, string $created_at = '' ): array {
		$card = JobDirectory::public_card( $reference );
		if ( null === $card ) {
			$card = array(
				'uuid'      => $reference,
				'titre'     => '',
				'ville'     => null,
				'company'   => '',
				'source'    => $source,
				'available' => false,
			);
		}
		$out = array(
			'job_uuid'  => (string) $card['uuid'],
			'source'    => (string) $card['source'],
			'available' => (bool) $card['available'],
			'titre'     => (string) $card['titre'],
			'ville'     => $card['ville'],
			'company'   => (string) $card['company'],
		);
		if ( '' !== $created_at ) {
			$out['favorited_at'] = $created_at;
		}
		return $out;
	}
}
