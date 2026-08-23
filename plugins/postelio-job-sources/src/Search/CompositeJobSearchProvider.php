<?php
/**
 * Moteur de recherche COMPOSITE : fusionne les offres natives (CPT, provider natif existant)
 * et externes (table dédiée) en une seule liste, sans changer le contrat public `GET /jobs`.
 * Implémente l'interface `JobSearchProvider` de postelio-jobs et se branche via le filtre
 * `postelio/jobs/search_provider`.
 *
 * Pagination unifiée (V1) : tri déterministe par date de publication décroissante (native
 * `date_publication` vs externe `source_published_at`). On récupère les N premiers de chaque
 * source (N = page*per_page, plafonné MERGE_CAP), on fusionne-trie, puis on découpe la page.
 * `total` = total natif + total externe. Au-delà de MERGE_CAP éléments, la pagination
 * profonde est approximative (documenté) — à remplacer par un moteur d'index à grande échelle.
 *
 * @package Postelio\JobSources\Search
 */

namespace Postelio\JobSources\Search;

use Postelio\JobSources\Jobs\ExternalJobRepository;
use Postelio\JobSources\Sources\JobSourceRegistry;

if ( interface_exists( '\\Postelio\\Jobs\\Search\\JobSearchProvider' ) ) {

	final class CompositeJobSearchProvider implements \Postelio\Jobs\Search\JobSearchProvider {

		private \Postelio\Jobs\Search\JobSearchProvider $native;
		private ExternalJobRepository $external;
		private JobSourceRegistry $registry;

		public function __construct( \Postelio\Jobs\Search\JobSearchProvider $native, ExternalJobRepository $external, JobSourceRegistry $registry ) {
			$this->native   = $native;
			$this->external = $external;
			$this->registry = $registry;
		}

		/**
		 * @param array<string, mixed> $filters
		 * @return array{items: array<int, array<string,mixed>>, total:int}
		 */
		public function search( array $filters, int $page, int $per_page ): array {
			$source = (string) ( $filters['source'] ?? 'all' );
			$page   = max( 1, $page );
			$per    = max( 1, $per_page );

			if ( 'postelio' === $source ) {
				$r                    = $this->tag_native( $this->native->search( $filters, $page, $per ) );
				$r['total_is_exact']  = true; // provider natif : total exact
				return $r;
			}
			if ( 'partners' === $source ) {
				return $this->external_only( $filters, $page, $per );
			}

			$cap  = (int) apply_filters( 'postelio/job_sources/merge_cap', 100 );
			$need = min( $cap, $page * $per );

			$nat = $this->native->search( $filters, 1, $need );
			$ext = $this->external->search_public( $filters, $need, $this->registry->disabled_source_keys() );

			$merged = array();
			foreach ( (array) $nat['items'] as $it ) {
				$it['source_type'] = 'native';
				$merged[]          = array( 'ts' => self::ts( (string) ( $it['date_publication'] ?? '' ) ), 'row' => $it );
			}
			foreach ( (array) $ext['items'] as $it ) {
				$merged[] = array( 'ts' => self::ts( (string) ( $it['source_published_at'] ?? '' ) ), 'row' => $it );
			}
			usort( $merged, static fn( $a, $b ) => $b['ts'] <=> $a['ts'] );

			$slice = array_slice( $merged, ( $page - 1 ) * $per, $per );
			$items = array_map( static fn( $x ) => $x['row'], $slice );

			$total = (int) $nat['total'] + (int) $ext['total'];
			// Fusion V1 : ordre/pagination exacts DANS le périmètre récupéré (merge_cap).
			// Au-delà, le total (somme) reste juste mais l'ordre global n'est pas garanti.
			return array( 'items' => $items, 'total' => $total, 'total_is_exact' => ( $total <= $cap ) );
		}

		/**
		 * @param array<string, mixed> $filters
		 * @return array{items: array<int, array<string,mixed>>, total:int}
		 */
		private function external_only( array $filters, int $page, int $per ): array {
			$cap   = (int) apply_filters( 'postelio/job_sources/merge_cap', 100 );
			$need  = min( $cap, $page * $per );
			$ext   = $this->external->search_public( $filters, $need, $this->registry->disabled_source_keys() );
			$items = array_slice( (array) $ext['items'], ( $page - 1 ) * $per, $per );
			return array( 'items' => array_values( $items ), 'total' => (int) $ext['total'], 'total_is_exact' => ( (int) $ext['total'] <= $cap ) );
		}

		/**
		 * @param array{items: array<int, array<string,mixed>>, total:int} $res
		 * @return array{items: array<int, array<string,mixed>>, total:int}
		 */
		private function tag_native( array $res ): array {
			$res['items'] = array_map(
				static function ( $it ) { $it['source_type'] = 'native'; return $it; },
				(array) $res['items']
			);
			return $res;
		}

		private static function ts( string $date ): int {
			$date = trim( $date );
			if ( '' === $date ) {
				return 0;
			}
			$t = strtotime( $date );
			return $t ? $t : 0;
		}
	}
}
