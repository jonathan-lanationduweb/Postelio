<?php
/**
 * Catalogue produit Billing V1 — EN CODE (versionné), source d'autorité tarifaire. Le front
 * ne fournit JAMAIS montant/devise/durée/taxe : seul `product_code` est accepté. Montants en
 * entiers (centimes). Le prix TTC est figé ; la fiscalité (tax_mode/tax_rate) est configurable
 * via filtre (valeur réelle À VALIDER — comptable).
 *
 * @package Postelio\Billing\Catalog
 */

namespace Postelio\Billing\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_BILLING_TESTING' ) ) {
		exit;
	}
}

final class ProductCatalog {

	public const JOB_RENEWAL = 'job_renewal';

	/** @return array<string, array<string, mixed>> */
	public static function all(): array {
		return array(
			self::JOB_RENEWAL => array(
				'product_code'  => self::JOB_RENEWAL,
				'label'         => 'Renouvellement d\'une offre d\'emploi (30 jours)',
				'unit_amount'   => 1000,          // 10,00 € TTC, en centimes
				'currency'      => 'EUR',
				'duration_days' => 30,
				'resource_type' => 'job',
				'active'        => true,
			),
		);
	}

	public static function exists( string $code ): bool {
		return isset( self::all()[ $code ] ) && ! empty( self::all()[ $code ]['active'] );
	}

	/** @return array<string, mixed>|null */
	public static function get( string $code ): ?array {
		return self::exists( $code ) ? self::all()[ $code ] : null;
	}

	/**
	 * Politique fiscale V1 (configurable ; défaut FR TTC 20 %). tax_rate en points de base
	 * (2000 = 20,00 %). VALEUR RÉELLE À VALIDER (statut TVA vendeur, B2B UE, reverse charge).
	 *
	 * @return array{tax_mode:string, tax_rate:int}
	 */
	public static function tax_policy(): array {
		$mode = (string) apply_filters( 'postelio/billing/tax_mode', 'inclusive' );
		$rate = (int) apply_filters( 'postelio/billing/tax_rate', 2000 );
		return array(
			'tax_mode' => in_array( $mode, array( 'inclusive', 'exclusive' ), true ) ? $mode : 'inclusive',
			'tax_rate' => max( 0, $rate ),
		);
	}

	/**
	 * Décompose un montant selon la politique fiscale. Tout en centimes entiers.
	 *
	 * @return array{unit_amount:int, tax_mode:string, tax_rate:int, tax_amount:int, net_amount:int, total_amount:int, currency:string}
	 */
	public static function price( string $code ): array {
		$product = self::get( $code );
		if ( null === $product ) {
			return array( 'unit_amount' => 0, 'tax_mode' => 'inclusive', 'tax_rate' => 0, 'tax_amount' => 0, 'net_amount' => 0, 'total_amount' => 0, 'currency' => 'EUR' );
		}
		$unit   = (int) $product['unit_amount'];
		$policy = self::tax_policy();
		$rate   = $policy['tax_rate'];
		if ( 'exclusive' === $policy['tax_mode'] ) {
			$net   = $unit;
			$tax   = (int) round( $net * $rate / 10000 );
			$total = $net + $tax;
		} else { // inclusive : le prix affiché contient la TVA
			$total = $unit;
			$net   = $rate > 0 ? (int) round( $total * 10000 / ( 10000 + $rate ) ) : $total;
			$tax   = $total - $net;
		}
		return array(
			'unit_amount'  => $unit,
			'tax_mode'     => $policy['tax_mode'],
			'tax_rate'     => $rate,
			'tax_amount'   => $tax,
			'net_amount'   => $net,
			'total_amount' => $total,
			'currency'     => (string) $product['currency'],
		);
	}
}
