<?php
/**
 * Appel REST INTERNE au nom de l'utilisateur courant (`rest_do_request`) : les
 * `permission_callback` et les présenteurs des domaines s'appliquent donc exactement comme pour un
 * appel HTTP. C'est le SEUL moyen par lequel le back-office lit les domaines qui n'exposent pas de
 * façade PHP (modération, facturation, sources). Aucune requête SQL cross-domaine.
 *
 * @package Postelio\Backoffice\Support
 */

namespace Postelio\Backoffice\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest {

	/**
	 * @param array<string,mixed>      $query
	 * @param array<string,mixed>|null $body
	 * @return array{status:int, data:mixed}
	 */
	public static function call( string $method, string $route, array $query = array(), ?array $body = null ): array {
		if ( ! function_exists( 'rest_do_request' ) ) {
			return array( 'status' => 0, 'data' => null );
		}
		try {
			$r = new \WP_REST_Request( $method, $route );
			if ( ! empty( $query ) ) {
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

	/** Charge utile `data` d'une réponse 200, sinon tableau vide. @return array<string,mixed> */
	public static function payload( string $route, array $query = array() ): array {
		$r = self::call( 'GET', $route, $query );
		if ( 200 !== $r['status'] || ! is_array( $r['data'] ) ) {
			return array();
		}
		$d = $r['data']['data'] ?? $r['data'];
		return is_array( $d ) ? $d : array();
	}

	/** Total d'une liste paginée (`meta.pagination.total`), ou null si indisponible. */
	public static function total( string $route, array $query = array() ): ?int {
		$res = self::call( 'GET', $route, array_merge( $query, array( 'per_page' => 1 ) ) );
		if ( 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return null;
		}
		$total = $res['data']['meta']['pagination']['total'] ?? null;
		return null === $total ? null : (int) $total;
	}
}
