<?php
/**
 * Provider Stripe V1 — Checkout Session hosted. Client HTTP léger via `wp_remote_*` (cohérent
 * avec l'intégration France Travail du Lot 10 ; le dépôt n'utilise PAS Composer). Vérification
 * de signature webhook via StripeSignature (schéma HMAC documenté). AUCUNE donnée carte ne
 * transite par Postelio (PCI SAQ-A). Secrets lus en env/constantes, jamais en base/Git.
 *
 * @package Postelio\Billing\Provider
 */

namespace Postelio\Billing\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StripePaymentProvider implements PaymentProvider {

	private const API_BASE = 'https://api.stripe.com/v1';

	private string $secret;
	private string $webhook_secret;

	public function __construct( ?string $secret = null, ?string $webhook_secret = null ) {
		$this->secret         = null !== $secret ? $secret : self::env( 'POSTELIO_STRIPE_SECRET_KEY' );
		$this->webhook_secret = null !== $webhook_secret ? $webhook_secret : self::env( 'POSTELIO_STRIPE_WEBHOOK_SECRET' );
	}

	public function name(): string {
		return 'stripe';
	}

	public function mode(): string {
		return StripeSignature::key_mode( $this->secret );
	}

	public function is_configured(): bool {
		return '' !== $this->secret && '' !== $this->webhook_secret && 'unknown' !== $this->mode();
	}

	public function create_customer( array $company ): ?string {
		$resp = $this->post( '/customers', array(
			'name'                 => (string) ( $company['name'] ?? '' ),
			'metadata[company_uuid]' => (string) ( $company['company_uuid'] ?? '' ),
		) );
		return isset( $resp['id'] ) ? (string) $resp['id'] : null;
	}

	public function create_checkout( array $order, string $success_url, string $cancel_url ): ?array {
		$params = array(
			'mode'                                    => 'payment',
			'success_url'                             => $success_url,
			'cancel_url'                              => $cancel_url,
			'client_reference_id'                     => (string) $order['public_uuid'],
			'metadata[order_uuid]'                    => (string) $order['public_uuid'],
			'metadata[product_code]'                  => (string) $order['product_code'],
			'line_items[0][quantity]'                 => 1,
			'line_items[0][price_data][currency]'     => strtolower( (string) $order['currency'] ),
			'line_items[0][price_data][unit_amount]'  => (int) $order['total_amount'],
			'line_items[0][price_data][product_data][name]' => (string) ( $order['snapshot']['product']['label'] ?? $order['product_code'] ),
		);
		if ( ! empty( $order['provider_customer_id'] ) ) {
			$params['customer'] = (string) $order['provider_customer_id'];
		}
		// Idempotence Stripe : une même clé ne crée jamais deux sessions.
		$resp = $this->post( '/checkout/sessions', $params, 'checkout_' . (string) $order['idempotency_key'] );
		if ( ! isset( $resp['id'], $resp['url'] ) ) {
			return null;
		}
		return array(
			'session_id'        => (string) $resp['id'],
			'url'               => (string) $resp['url'],
			'payment_intent_id' => isset( $resp['payment_intent'] ) ? (string) $resp['payment_intent'] : null,
			'expires_at'        => isset( $resp['expires_at'] ) ? (int) $resp['expires_at'] : null,
		);
	}

	public function verify_webhook( string $raw_body, string $signature_header ): ?array {
		if ( ! StripeSignature::verify( $raw_body, $signature_header, $this->webhook_secret, time() ) ) {
			return null;
		}
		$event = json_decode( $raw_body, true );
		return is_array( $event ) ? $event : null;
	}

	public function refund( string $payment_intent_id ): bool {
		if ( '' === $payment_intent_id ) {
			return false;
		}
		$resp = $this->post( '/refunds', array( 'payment_intent' => $payment_intent_id ) );
		return isset( $resp['id'] );
	}

	public function health(): array {
		return array(
			'provider'         => 'stripe',
			'mode'             => $this->mode(),
			'configured'       => $this->is_configured(),
			'webhook_configured' => '' !== $this->webhook_secret,
		);
	}

	// --- HTTP -----------------------------------------------------------------

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function post( string $path, array $params, ?string $idempotency_key = null ): array {
		if ( '' === $this->secret ) {
			return array();
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $this->secret,
			'Content-Type'  => 'application/x-www-form-urlencoded',
		);
		if ( null !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}
		$resp = wp_remote_post( self::API_BASE . $path, array(
			'timeout' => 20,
			'headers' => $headers,
			'body'    => $params,
		) );
		if ( is_wp_error( $resp ) ) {
			return array();
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function env( string $name ): string {
		if ( defined( $name ) ) {
			return (string) constant( $name );
		}
		$v = getenv( $name );
		return false === $v ? '' : (string) $v;
	}
}
