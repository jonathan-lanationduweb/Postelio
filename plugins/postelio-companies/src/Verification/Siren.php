<?php
/**
 * Validation des identifiants légaux français (SIREN/SIRET) — fondation anti-fraude.
 *
 * Vérifie le format et la clé de Luhn. NE contacte AUCUN service externe (ce n'est
 * qu'un contrôle de cohérence local ; l'existence réelle relève du provider de
 * vérification). Classe pure → testable en isolation.
 *
 * @package Postelio\Companies\Verification
 */

namespace Postelio\Companies\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class Siren {

	/** Ne conserve que les chiffres. */
	public static function normalize( string $value ): string {
		return preg_replace( '/\D+/', '', $value );
	}

	public static function is_valid_siren( string $siren ): bool {
		$siren = self::normalize( $siren );
		return 9 === strlen( $siren ) && self::luhn( $siren );
	}

	public static function is_valid_siret( string $siret ): bool {
		$siret = self::normalize( $siret );
		return 14 === strlen( $siret ) && self::luhn( $siret );
	}

	/**
	 * Le SIRET commence-t-il par le SIREN fourni ? (cohérence établissement/siège).
	 */
	public static function siret_matches_siren( string $siret, string $siren ): bool {
		$siret = self::normalize( $siret );
		$siren = self::normalize( $siren );
		return 14 === strlen( $siret ) && 9 === strlen( $siren ) && 0 === strncmp( $siret, $siren, 9 );
	}

	/**
	 * Algorithme de Luhn (clé de contrôle SIREN/SIRET).
	 */
	private static function luhn( string $digits ): bool {
		$sum    = 0;
		$len    = strlen( $digits );
		$parity = $len % 2;
		for ( $i = 0; $i < $len; $i++ ) {
			$d = (int) $digits[ $i ];
			if ( $i % 2 === $parity ) {
				$d *= 2;
				if ( $d > 9 ) {
					$d -= 9;
				}
			}
			$sum += $d;
		}
		return 0 === $sum % 10;
	}
}
