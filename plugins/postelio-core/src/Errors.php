<?php
/**
 * Codes d'erreur internes stables et correspondance HTTP.
 *
 * Source de vérité : docs/backend/api-contract.md §2. Classe SANS dépendance
 * WordPress → testable en isolation.
 *
 * @package Postelio\Core
 */

namespace Postelio\Core;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class Errors {

	/** @var array<string, int> code interne → statut HTTP. */
	public const MAP = array(
		'unauthenticated'        => 401,
		'forbidden'              => 403,
		'not_found'              => 404,
		'validation_error'      => 422,
		'invalid_transition'    => 409,
		'conflict'              => 409,
		'rate_limited'          => 429,
		'payload_too_large'     => 413,
		'unsupported_media_type' => 415,
		'payment_required'      => 402,
		'server_error'          => 500,
	);

	public static function is_known( string $code ): bool {
		return isset( self::MAP[ $code ] );
	}

	/**
	 * Statut HTTP pour un code interne (500 par défaut si inconnu).
	 */
	public static function http_status( string $code ): int {
		return self::MAP[ $code ] ?? 500;
	}

	/**
	 * Construit l'enveloppe d'erreur standard.
	 * `{ "error": { "code", "message", "details" } }`.
	 *
	 * @param array<string, mixed> $details
	 * @return array{error: array{code:string, message:string, details:array}}
	 */
	public static function envelope( string $code, string $message, array $details = array() ): array {
		if ( ! self::is_known( $code ) ) {
			$code = 'server_error';
		}
		return array(
			'error' => array(
				'code'    => $code,
				'message' => $message,
				'details' => (object) $details,
			),
		);
	}
}
