<?php
/**
 * Stockage de la configuration du site : UNE option WordPress par page
 * (`postelio_site_<page>`) — jamais un JSON global monolithique impossible à migrer. Les valeurs
 * lues sont fusionnées SUR les valeurs par défaut du schéma (tolérant aux ajouts de champs).
 *
 * @package Postelio\Site\Config
 */

namespace Postelio\Site\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteConfigRepository {

	private const PREFIX = 'postelio_site_';

	public static function option_name( string $page ): string {
		return self::PREFIX . preg_replace( '/[^a-z0-9_]/', '', strtolower( $page ) );
	}

	/**
	 * Valeurs stockées d'une page, fusionnées sur les défauts du schéma.
	 *
	 * @return array<string,mixed>
	 */
	public function get( string $page ): array {
		$defaults = SiteSchema::defaults( $page );
		if ( empty( $defaults ) && ! SiteSchema::has_page( $page ) ) {
			return array();
		}
		$stored = get_option( self::option_name( $page ), array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return self::merge( $defaults, $stored );
	}

	/**
	 * Écrit les valeurs (déjà validées par le service).
	 *
	 * @param array<string,mixed> $values
	 */
	public function put( string $page, array $values ): void {
		update_option( self::option_name( $page ), $values, false );
	}

	/** @return array<string,array<string,mixed>> Toutes les pages { page => valeurs }. */
	public function all(): array {
		$out = array();
		foreach ( SiteSchema::pages() as $page ) {
			$out[ $page ] = $this->get( $page );
		}
		return $out;
	}

	/**
	 * Fusion récursive « stored par-dessus defaults » (les clés inconnues de stored sont ignorées
	 * pour rester dans le périmètre du schéma ; les tableaux indexés — repeaters — sont remplacés).
	 *
	 * @param array<string,mixed> $defaults
	 * @param array<string,mixed> $stored
	 * @return array<string,mixed>
	 */
	private static function merge( array $defaults, array $stored ): array {
		$out = $defaults;
		foreach ( $defaults as $key => $dval ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				continue;
			}
			$sval = $stored[ $key ];
			if ( is_array( $dval ) && self::is_assoc( $dval ) && is_array( $sval ) ) {
				$out[ $key ] = self::merge( $dval, $sval );
			} else {
				$out[ $key ] = $sval; // scalaire ou repeater (liste) → remplacement direct
			}
		}
		// Conserver l'ordre des sections s'il est stocké mais absent des defaults (robustesse).
		if ( isset( $stored['_order'] ) && is_array( $stored['_order'] ) ) {
			$out['_order'] = array_values( array_map( 'strval', $stored['_order'] ) );
		}
		return $out;
	}

	private static function is_assoc( array $a ): bool {
		return array_keys( $a ) !== range( 0, count( $a ) - 1 );
	}
}
