<?php
/**
 * Abstraction du fournisseur de paiement. Aucun code métier Billing ne dépend directement du
 * SDK/HTTP Stripe : tout passe par cette interface (remplaçable, testable via
 * FakePaymentProvider). V1 : StripePaymentProvider (Checkout hosted).
 *
 * @package Postelio\Billing\Provider
 */

namespace Postelio\Billing\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_BILLING_TESTING' ) ) {
		exit;
	}
}

interface PaymentProvider {

	public function name(): string;

	/** 'test' | 'live' | 'unknown' selon la clé configurée. */
	public function mode(): string;

	public function is_configured(): bool;

	/**
	 * Crée (ou résout) un customer provider pour une entreprise. Retourne l'id customer, ou
	 * null en cas d'échec (le checkout doit alors échouer proprement).
	 *
	 * @param array<string,mixed> $company
	 */
	public function create_customer( array $company ): ?string;

	/**
	 * Crée une session de paiement hosted pour un ordre. Retourne au minimum
	 * { session_id, url }, ou null en cas d'échec.
	 *
	 * @param array<string,mixed> $order
	 * @return array<string,mixed>|null
	 */
	public function create_checkout( array $order, string $success_url, string $cancel_url ): ?array;

	/**
	 * Vérifie la signature d'un webhook et retourne l'événement décodé, ou null si invalide.
	 *
	 * @return array<string,mixed>|null
	 */
	public function verify_webhook( string $raw_body, string $signature_header ): ?array;

	/**
	 * Rembourse un paiement (charge/payment_intent). Retourne true si accepté par le provider.
	 */
	public function refund( string $payment_intent_id ): bool;

	/** @return array<string,mixed> Diagnostic non sensible (aucun secret). */
	public function health(): array;
}
