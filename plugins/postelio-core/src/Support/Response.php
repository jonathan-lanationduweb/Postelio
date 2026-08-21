<?php
/**
 * Construction de l'enveloppe de réponse standard `{ data, meta }`.
 *
 * Source de vérité : docs/backend/api-contract.md §2. SANS dépendance WordPress.
 *
 * @package Postelio\Core\Support
 */

namespace Postelio\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class Response {

	public const DEFAULT_PER_PAGE = 20;
	public const MAX_PER_PAGE     = 100;

	/**
	 * Enveloppe de succès.
	 *
	 * @param mixed                $data
	 * @param array<string, mixed> $meta
	 * @return array{data:mixed, meta:array}
	 */
	public static function ok( $data, array $meta = array() ): array {
		return array(
			'data' => $data,
			'meta' => (object) $meta,
		);
	}

	/**
	 * Enveloppe de collection paginée.
	 *
	 * @param array<int, mixed> $items
	 * @return array{data:array, meta:array{pagination:array{page:int, per_page:int, total:int, total_pages:int}}}
	 */
	public static function paginated( array $items, int $page, int $per_page, int $total ): array {
		$per_page   = self::clamp_per_page( $per_page );
		$page       = max( 1, $page );
		$total      = max( 0, $total );
		$total_page = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		return array(
			'data' => array_values( $items ),
			'meta' => array(
				'pagination' => array(
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => $total,
					'total_pages' => $total_page,
				),
			),
		);
	}

	public static function clamp_per_page( int $per_page ): int {
		if ( $per_page < 1 ) {
			return self::DEFAULT_PER_PAGE;
		}
		return min( $per_page, self::MAX_PER_PAGE );
	}
}
