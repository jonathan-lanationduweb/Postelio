<?php
/**
 * Identité juridique du VENDEUR (société exploitant Postelio), nécessaire à une facture
 * légale. Fournie par constantes/env/filtre — JAMAIS de valeur inventée en dur. Son absence
 * n'empêche PAS un paiement de test, mais interdit de prétendre produire une « facture
 * légale » : `is_complete()`/`legal_invoice_ready()` = false tant que l'identité manque.
 * Les vraies valeurs restent À FOURNIR (juridique/comptable).
 *
 * @package Postelio\Billing\Config
 */

namespace Postelio\Billing\Config;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_BILLING_TESTING' ) ) {
		exit;
	}
}

final class SellerConfig {

	/** Champs requis pour une facture légale française. */
	private const REQUIRED = array( 'legal_name', 'address', 'siren', 'vat_number' );

	/** @return array<string, string> */
	public static function all(): array {
		$defaults = array(
			'legal_name'   => self::env( 'POSTELIO_SELLER_LEGAL_NAME' ),
			'trading_name' => self::env( 'POSTELIO_SELLER_TRADING_NAME' ),
			'address'      => self::env( 'POSTELIO_SELLER_ADDRESS' ),
			'siren'        => self::env( 'POSTELIO_SELLER_SIREN' ),
			'siret'        => self::env( 'POSTELIO_SELLER_SIRET' ),
			'vat_number'   => self::env( 'POSTELIO_SELLER_VAT' ),
			'email'        => self::env( 'POSTELIO_SELLER_EMAIL' ),
			'mentions'     => self::env( 'POSTELIO_SELLER_MENTIONS' ),
		);
		/** @var array<string,string> $conf */
		$conf = (array) apply_filters( 'postelio/billing/seller_config', $defaults );
		return array_map( static fn( $v ) => (string) $v, $conf );
	}

	public static function is_complete(): bool {
		$c = self::all();
		foreach ( self::REQUIRED as $k ) {
			if ( '' === trim( (string) ( $c[ $k ] ?? '' ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** Alias métier : peut-on émettre une facture légale ? (V1 : jamais de PDF, mais l'état est exposé.) */
	public static function legal_invoice_ready(): bool {
		return self::is_complete();
	}

	private static function env( string $name ): string {
		if ( defined( $name ) ) {
			return (string) constant( $name );
		}
		$v = getenv( $name );
		return false === $v ? '' : (string) $v;
	}
}
