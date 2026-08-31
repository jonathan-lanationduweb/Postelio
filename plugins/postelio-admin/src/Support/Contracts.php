<?php
/**
 * Détection sûre des modules/contrats Postelio. Le back-office ne DOIT jamais faire un fatal si
 * un plugin est désactivé : on teste toujours la présence (Registry + class_exists) avant
 * d'appeler un contrat. Fournit aussi un appel REST interne (rest_do_request) exécuté au nom de
 * l'utilisateur courant — ainsi les capabilities et présenteurs des domaines s'appliquent.
 *
 * @package Postelio\Admin\Support
 */

namespace Postelio\Admin\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Contracts {

	/** Le module est-il enregistré dans le Registry du core ? */
	public static function module_active( string $module ): bool {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			return false;
		}
		try {
			return \Postelio\Core\Plugin::instance()->registry()->has( $module );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public static function has( string $fqcn ): bool {
		return class_exists( $fqcn );
	}

	/**
	 * Appel REST INTERNE au nom de l'utilisateur courant (les permission_callback s'appliquent).
	 * Retourne { status, data }. Ne lève jamais : renvoie un statut d'erreur en cas de souci.
	 *
	 * @param array<string,mixed> $query
	 * @param array<string,mixed>|null $body
	 * @return array{status:int, data:mixed}
	 */
	public static function rest( string $method, string $route, array $query = array(), ?array $body = null ): array {
		if ( ! function_exists( 'rest_do_request' ) ) {
			return array( 'status' => 0, 'data' => null );
		}
		try {
			$r = new \WP_REST_Request( $method, $route );
			if ( $query ) {
				$r->set_query_params( $query );
			}
			if ( null !== $body ) {
				$r->set_header( 'Content-Type', 'application/json' );
				$r->set_body( wp_json_encode( $body ) );
			}
			$resp = rest_do_request( $r );
			return array( 'status' => (int) $resp->get_status(), 'data' => $resp->get_data() );
		} catch ( \Throwable $e ) {
			return array( 'status' => 0, 'data' => null );
		}
	}

	/** Total d'une liste paginée REST (meta.pagination.total), ou null si indisponible. */
	public static function rest_total( string $route, array $query = array() ): ?int {
		$res = self::rest( 'GET', $route, array_merge( $query, array( 'per_page' => 1 ) ) );
		if ( 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return null;
		}
		$total = $res['data']['meta']['pagination']['total'] ?? null;
		return null === $total ? null : (int) $total;
	}
}
