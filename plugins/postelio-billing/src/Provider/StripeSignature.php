<?php
/**
 * Vérification de signature webhook Stripe — logique PURE (testable hors réseau/WP). Schéma
 * documenté par Stripe : en-tête `t=<timestamp>,v1=<sig>[,v1=...]` ; signature =
 * HMAC-SHA256 de « {t}.{payload} » avec le webhook secret ; comparaison à temps constant ;
 * tolérance temporelle. `now` est injecté pour la testabilité.
 *
 * @package Postelio\Billing\Provider
 */

namespace Postelio\Billing\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_BILLING_TESTING' ) ) {
		exit;
	}
}

final class StripeSignature {

	public const DEFAULT_TOLERANCE = 300; // secondes

	/**
	 * Parse l'en-tête Stripe-Signature. @return array{t:int, v1:string[]}
	 */
	public static function parse( string $header ): array {
		$t  = 0;
		$v1 = array();
		foreach ( explode( ',', $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$t = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$v1[] = $kv[1];
			}
		}
		return array( 't' => $t, 'v1' => $v1 );
	}

	public static function expected( int $timestamp, string $payload, string $secret ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
	}

	/**
	 * Vérifie la signature. Retourne true si une des signatures v1 correspond ET que
	 * l'horodatage est dans la tolérance.
	 */
	public static function verify( string $payload, string $header, string $secret, int $now, int $tolerance = self::DEFAULT_TOLERANCE ): bool {
		if ( '' === $secret ) {
			return false;
		}
		$parsed = self::parse( $header );
		if ( $parsed['t'] <= 0 || empty( $parsed['v1'] ) ) {
			return false;
		}
		if ( abs( $now - $parsed['t'] ) > $tolerance ) {
			return false;
		}
		$expected = self::expected( $parsed['t'], $payload, $secret );
		foreach ( $parsed['v1'] as $candidate ) {
			if ( hash_equals( $expected, $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	/** 'test' | 'live' | 'unknown' d'après le préfixe d'une clé secrète Stripe. */
	public static function key_mode( string $secret_key ): string {
		if ( 0 === strpos( $secret_key, 'sk_test_' ) || 0 === strpos( $secret_key, 'rk_test_' ) ) {
			return 'test';
		}
		if ( 0 === strpos( $secret_key, 'sk_live_' ) || 0 === strpos( $secret_key, 'rk_live_' ) ) {
			return 'live';
		}
		return 'unknown';
	}
}
