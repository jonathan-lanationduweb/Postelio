<?php
/**
 * Moteur de recherche V1 : `WP_Meta_Query` sur le CPT (via JobRepository).
 *
 * LIMITE DE SCALABILITÉ (documentée) : les filtres en postmeta ne passent pas à
 * l'échelle pour de gros volumes / recherche plein-texte pertinente. À remplacer
 * par `postelio-search` (table/index dédié ou Meilisearch/Typesense) en implémentant
 * simplement JobSearchProvider et en le branchant via `postelio/jobs/search_provider`.
 *
 * @package Postelio\Jobs\Search
 */

namespace Postelio\Jobs\Search;

use Postelio\Jobs\Jobs\JobRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MetaQuerySearchProvider implements JobSearchProvider {

	private JobRepository $jobs;

	public function __construct( JobRepository $jobs ) {
		$this->jobs = $jobs;
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function search( array $filters, int $page, int $per_page ): array {
		return $this->jobs->list_public( $filters, $page, $per_page );
	}
}
