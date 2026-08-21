<?php
/**
 * Contrat du moteur de recherche d'offres.
 *
 * Les endpoints publics dépendent de CETTE abstraction, pas de `WP_Meta_Query`
 * directement. On peut donc remplacer le moteur (table/index dédié, Meilisearch,
 * Typesense…) via le filtre `postelio/jobs/search_provider` sans casser l'API.
 *
 * @package Postelio\Jobs\Search
 */

namespace Postelio\Jobs\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface JobSearchProvider {

	/**
	 * @param array<string, mixed> $filters
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function search( array $filters, int $page, int $per_page ): array;
}
