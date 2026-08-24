<?php
/**
 * Résolution du PaymentProvider actif, au POINT D'USAGE (filtre `postelio/billing/provider`).
 * Par défaut : StripePaymentProvider. Les tests/smoke injectent un FakePaymentProvider via le
 * filtre — sans réenregistrer les routes. Aucune mise en cache : le filtre gagne toujours.
 *
 * @package Postelio\Billing\Provider
 */

namespace Postelio\Billing\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProviderRegistry {

	public static function resolve(): PaymentProvider {
		$default  = new StripePaymentProvider();
		$provider = apply_filters( 'postelio/billing/provider', $default );
		return $provider instanceof PaymentProvider ? $provider : $default;
	}
}
