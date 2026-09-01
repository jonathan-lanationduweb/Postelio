<?php
/**
 * Contrat de recherche INTERNE, stable, pour les autres modules (postelio-alerts).
 *
 * Une SEULE logique de matching : résout le provider de recherche via le filtre
 * `postelio/jobs/search_provider` (moteur natif par défaut, moteur COMPOSITE natif+externe quand
 * postelio-job-sources est actif) et délègue. Aucun consommateur ne fait de SQL sur les offres
 * ni ne lit la table `external_jobs` : tout passe par ce contrat, exactement comme `GET /jobs`.
 *
 * `published_after` (interne) restreint aux offres publiées à partir d'une date — cohérent pour
 * le natif (meta date de publication) et l'externe (source_published_at). Il n'est jamais exposé
 * comme filtre public.
 *
 * @package Postelio\Jobs\Api
 */

namespace Postelio\Jobs\Api;

use Postelio\Jobs\Jobs\JobPresenter;
use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Jobs\Search\MetaQuerySearchProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobSearchDirectory {

	/** Résout le provider actif (composite si job-sources présent). */
	private static function provider() {
		$default = new MetaQuerySearchProvider( new JobRepository() );
		return apply_filters( 'postelio/jobs/search_provider', $default );
	}

	/**
	 * Exécute une recherche via le moteur actif.
	 *
	 * @param array<string, mixed> $filters  Filtres déjà validés (+ éventuel `published_after`).
	 * @param int                  $page
	 * @param int                  $per_page
	 * @return array{items: array<int, array<string,mixed>>, total:int, total_is_exact:bool}
	 */
	public static function search( array $filters, int $page, int $per_page ): array {
		$res   = self::provider()->search( $filters, max( 1, $page ), max( 1, $per_page ) );
		$raw   = isset( $res['items'] ) && is_array( $res['items'] ) ? $res['items'] : array();
		// Vues publiques UNIFORMES (natif + externe) : le consommateur n'a jamais à connaître la
		// forme brute d'une ligne native vs externe (uuid/titre/ville/company.nom/source.type).
		$items = array_map( array( JobPresenter::class, 'public_view' ), $raw );
		return array(
			'items'          => $items,
			'total'          => (int) ( $res['total'] ?? 0 ),
			'total_is_exact' => (bool) ( $res['total_is_exact'] ?? true ),
		);
	}
}
