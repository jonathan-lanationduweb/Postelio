<?php
/**
 * Jetons d'accès applicatifs (Bearer) pour clients non-cookie (future app Tauri).
 *
 * Sans dépendance externe : le jeton est `"{uid}.{tid}.{secret}"`. Seul un hash
 * SHA-256 du secret est stocké (usermeta), avec une expiration. Le web utilise les
 * cookies WordPress + nonce ; ce service ne sert qu'aux clients applicatifs.
 *
 * @package Postelio\Users\Auth
 */

namespace Postelio\Users\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class TokenService {

	public const META_KEY = 'postelio_api_tokens';

	/** Durée de vie par défaut (secondes). Configurable via le filtre ci-dessous. */
	public const DEFAULT_TTL = 1209600; // 14 jours.

	/**
	 * Sépare un jeton en ses trois composantes. Null si malformé.
	 *
	 * @return array{uid:int, tid:string, secret:string}|null
	 */
	public static function parse( string $token ): ?array {
		$parts = explode( '.', trim( $token ), 3 );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		[ $uid, $tid, $secret ] = $parts;
		if ( ! ctype_digit( $uid ) || '' === $tid || '' === $secret ) {
			return null;
		}
		return array(
			'uid'    => (int) $uid,
			'tid'    => $tid,
			'secret' => $secret,
		);
	}

	public static function hash( string $secret ): string {
		return hash( 'sha256', $secret );
	}

	private function ttl(): int {
		$ttl = (int) apply_filters( 'postelio/auth_token_ttl', self::DEFAULT_TTL );
		return $ttl > 0 ? $ttl : self::DEFAULT_TTL;
	}

	/**
	 * Émet un nouveau jeton pour un utilisateur. Retourne le jeton en clair
	 * (affiché une seule fois) et son expiration.
	 *
	 * @return array{token:string, expires_at:int}
	 */
	public function issue( int $user_id, string $label = 'api' ): array {
		$tid    = wp_generate_password( 12, false );
		$secret = wp_generate_password( 48, false );
		$now    = time();
		$exp    = $now + $this->ttl();

		$tokens          = $this->read( $user_id );
		$tokens          = $this->purge_expired( $tokens, $now );
		$tokens[ $tid ]  = array(
			'hash'    => self::hash( $secret ),
			'expires' => $exp,
			'created' => $now,
			'label'   => substr( $label, 0, 40 ),
		);
		$this->write( $user_id, $tokens );

		return array(
			'token'      => sprintf( '%d.%s.%s', $user_id, $tid, $secret ),
			'expires_at' => $exp,
		);
	}

	/**
	 * Valide un jeton et retourne l'ID utilisateur, ou 0 si invalide/expiré.
	 */
	public function validate( string $token ): int {
		$parsed = self::parse( $token );
		if ( null === $parsed ) {
			return 0;
		}
		$tokens = $this->read( $parsed['uid'] );
		$entry  = $tokens[ $parsed['tid'] ] ?? null;
		if ( ! is_array( $entry ) ) {
			return 0;
		}
		if ( (int) $entry['expires'] < time() ) {
			return 0;
		}
		if ( ! hash_equals( (string) $entry['hash'], self::hash( $parsed['secret'] ) ) ) {
			return 0;
		}
		return $parsed['uid'];
	}

	/**
	 * Révoque un jeton précis. Retourne le nouveau jeton si $reissue.
	 *
	 * @return array{token:string, expires_at:int}|null
	 */
	public function refresh( string $token ): ?array {
		$uid = $this->validate( $token );
		if ( 0 === $uid ) {
			return null;
		}
		$this->revoke( $token );
		return $this->issue( $uid );
	}

	public function revoke( string $token ): void {
		$parsed = self::parse( $token );
		if ( null === $parsed ) {
			return;
		}
		$tokens = $this->read( $parsed['uid'] );
		unset( $tokens[ $parsed['tid'] ] );
		$this->write( $parsed['uid'], $tokens );
	}

	public function revoke_all( int $user_id ): void {
		delete_user_meta( $user_id, self::META_KEY );
	}

	/** @return array<string, array{hash:string, expires:int, created:int, label:string}> */
	private function read( int $user_id ): array {
		$data = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $data ) ? $data : array();
	}

	/** @param array<string, mixed> $tokens */
	private function write( int $user_id, array $tokens ): void {
		if ( empty( $tokens ) ) {
			delete_user_meta( $user_id, self::META_KEY );
			return;
		}
		update_user_meta( $user_id, self::META_KEY, $tokens );
	}

	/**
	 * @param array<string, array{expires:int}> $tokens
	 * @return array<string, mixed>
	 */
	private function purge_expired( array $tokens, int $now ): array {
		foreach ( $tokens as $tid => $entry ) {
			if ( (int) ( $entry['expires'] ?? 0 ) < $now ) {
				unset( $tokens[ $tid ] );
			}
		}
		return $tokens;
	}
}
