<?php
/**
 * Construit le SNAPSHOT figé à la création de l'ordre : produit + acheteur + vendeur +
 * décomposition fiscale. Une fois l'ordre créé, ce snapshot ne change JAMAIS (une modif
 * ultérieure de l'entreprise, du prix ou du catalogue n'altère pas l'historique financier).
 *
 * @package Postelio\Billing\Snapshot
 */

namespace Postelio\Billing\Snapshot;

use Postelio\Billing\Catalog\ProductCatalog;
use Postelio\Billing\Config\SellerConfig;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_BILLING_TESTING' ) ) {
		exit;
	}
}

final class BillingSnapshot {

	/**
	 * @param array<string,mixed>|null $buyer_identity  Résultat de CompanyBilling::identity().
	 * @return array<string, mixed>
	 */
	public static function build( string $product_code, ?array $buyer_identity, string $buyer_email ): array {
		$product = ProductCatalog::get( $product_code );
		$price   = ProductCatalog::price( $product_code );
		$buyer   = is_array( $buyer_identity ) ? $buyer_identity : array();
		$seller  = SellerConfig::all();

		return array(
			'product' => array(
				'product_code'  => $product_code,
				'label'         => (string) ( $product['label'] ?? $product_code ),
				'unit_amount'   => $price['unit_amount'],
				'net_amount'    => $price['net_amount'],
				'tax_amount'    => $price['tax_amount'],
				'total_amount'  => $price['total_amount'],
				'tax_mode'      => $price['tax_mode'],
				'tax_rate'      => $price['tax_rate'],
				'currency'      => $price['currency'],
				'duration_days' => (int) ( $product['duration_days'] ?? 0 ),
			),
			'buyer'  => array(
				'company_uuid' => (string) ( $buyer['company_uuid'] ?? '' ),
				'name'         => (string) ( $buyer['name'] ?? '' ),
				'legal'        => is_array( $buyer['legal'] ?? null ) ? $buyer['legal'] : array(),
				'billing_email' => $buyer_email,
			),
			'seller' => array(
				'legal_name'           => $seller['legal_name'] ?? '',
				'trading_name'         => $seller['trading_name'] ?? '',
				'address'              => $seller['address'] ?? '',
				'siren'                => $seller['siren'] ?? '',
				'siret'                => $seller['siret'] ?? '',
				'vat_number'           => $seller['vat_number'] ?? '',
				'email'                => $seller['email'] ?? '',
				'mentions'             => $seller['mentions'] ?? '',
				'legal_invoice_ready'  => SellerConfig::legal_invoice_ready(),
			),
		);
	}
}
