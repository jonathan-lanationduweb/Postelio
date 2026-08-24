<?php
/**
 * Service de commande : initie un checkout de renouvellement. Applique la chaîne de gardes
 * (auth/cap/email vérifié/actif/appartenance/entreprise vérifiée & non suspendue/offre
 * renouvelable), fige le SNAPSHOT (prix depuis le catalogue — jamais le front), gère
 * l'anti double-clic (réutilise l'ordre awaiting_payment), crée la session provider.
 *
 * @package Postelio\Billing\Orders
 */

namespace Postelio\Billing\Orders;

use Postelio\Billing\Catalog\ProductCatalog;
use Postelio\Billing\Domain\OrderStatus;
use Postelio\Billing\Provider\ProviderRegistry;
use Postelio\Billing\Snapshot\BillingSnapshot;
use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderService {

	private OrderRepository $orders;

	public function __construct( OrderRepository $orders ) {
		$this->orders = $orders;
	}

	/**
	 * Crée (ou réutilise) un ordre et retourne { order_uuid, checkout_url, expires_at }.
	 *
	 * @throws ApiError
	 */
	public function checkout( int $buyer_user_id, string $product_code, string $resource_type, string $resource_uuid ): array {
		$provider = ProviderRegistry::resolve();
		$product  = ProductCatalog::get( $product_code );
		if ( null === $product ) {
			throw ApiError::validation( array( 'product_code' => 'Produit inconnu.' ) );
		}
		if ( $resource_type !== (string) $product['resource_type'] || 'job' !== $resource_type ) {
			throw ApiError::validation( array( 'resource_type' => 'Type de ressource non supporté en V1.' ) );
		}

		// Résolution de l'offre + entreprise propriétaire. Non-divulgation : 404 si inconnue.
		if ( ! class_exists( '\\Postelio\\Jobs\\Api\\JobDirectory' ) || ! class_exists( '\\Postelio\\Jobs\\Api\\JobLifecycle' ) ) {
			throw new ApiError( 'server_error', 'Domaine offres indisponible.' );
		}
		$job_id = \Postelio\Jobs\Api\JobDirectory::id_from_uuid( $resource_uuid );
		if ( $job_id <= 0 ) {
			throw ApiError::not_found();
		}
		$company_id = \Postelio\Jobs\Api\JobDirectory::company_id_of( $job_id );
		if ( $company_id <= 0 || ! class_exists( '\\Postelio\\Companies\\Api\\CompanyDirectory' ) ) {
			throw ApiError::not_found();
		}
		// Appartenance : owner OU recruteur membre de l'entreprise du job. Sinon non-divulgation.
		if ( ! \Postelio\Companies\Api\CompanyDirectory::is_member( $company_id, $buyer_user_id ) ) {
			throw ApiError::not_found();
		}

		// Entreprise vérifiée et NON suspendue.
		$identity = class_exists( '\\Postelio\\Companies\\Api\\CompanyBilling' )
			? \Postelio\Companies\Api\CompanyBilling::identity( $company_id )
			: null;
		if ( ! is_array( $identity ) || empty( $identity['verified'] ) ) {
			throw ApiError::forbidden( 'Entreprise non vérifiée : paiement indisponible.' );
		}
		if ( ! empty( $identity['suspended'] ) ) {
			throw ApiError::forbidden( 'Entreprise suspendue : paiement indisponible.' );
		}

		// Offre renouvelable (contrat Jobs — jamais de lecture directe du statut).
		if ( ! \Postelio\Jobs\Api\JobLifecycle::can_renew( $job_id ) ) {
			throw new ApiError( 'invalid_transition', 'Offre non renouvelable dans son état actuel.' );
		}

		// Anti double-clic : réutilise un ordre awaiting_payment non expiré.
		$existing = $this->orders->reusable( $company_id, $resource_type, $resource_uuid, $product_code );
		if ( null !== $existing && ! empty( $existing['checkout_url'] ) && OrderStatus::AWAITING_PAYMENT === $existing['status'] ) {
			return array(
				'order_uuid'   => (string) $existing['public_uuid'],
				'checkout_url' => (string) $existing['checkout_url'],
				'expires_at'   => $existing['checkout_expires_at'] ?? null,
			);
		}

		// Snapshot (prix autoritaire = catalogue). billing_email = e-mail de l'acheteur.
		$buyer_email = '';
		$u = get_userdata( $buyer_user_id );
		if ( $u ) {
			$buyer_email = (string) $u->user_email;
		}
		$price    = ProductCatalog::price( $product_code );
		$snapshot = BillingSnapshot::build( $product_code, $identity, $buyer_email );

		$order_id = ( null !== $existing )
			? (int) $existing['id'] // réutilise l'ordre créé sans session valide
			: $this->orders->insert( array(
				'company_id'    => $company_id,
				'company_uuid'  => (string) ( $identity['company_uuid'] ?? '' ),
				'buyer_user_id' => $buyer_user_id,
				'product_code'  => $product_code,
				'resource_type' => $resource_type,
				'resource_uuid' => $resource_uuid,
				'currency'      => $price['currency'],
				'unit_amount'   => $price['unit_amount'],
				'tax_mode'      => $price['tax_mode'],
				'tax_rate'      => $price['tax_rate'],
				'tax_amount'    => $price['tax_amount'],
				'total_amount'  => $price['total_amount'],
				'duration_days' => (int) $product['duration_days'],
				'snapshot'      => $snapshot,
				'provider'      => $provider->name(),
			) );
		if ( 0 === $order_id ) {
			throw new ApiError( 'server_error', 'Création de la commande impossible.' );
		}
		$order = $this->orders->get( $order_id );

		// Stripe Customer LAZY par entreprise (échec ⇒ checkout échoue proprement).
		$customer_id = (string) ( $order['provider_customer_id'] ?? '' );
		if ( '' === $customer_id ) {
			$customer_id = (string) ( $provider->create_customer( array(
				'company_uuid' => (string) ( $identity['company_uuid'] ?? '' ),
				'name'         => (string) ( $identity['name'] ?? '' ),
			) ) ?? '' );
			if ( '' === $customer_id ) {
				throw new ApiError( 'server_error', 'Client de facturation indisponible.' );
			}
			$this->orders->update( $order_id, array( 'provider_customer_id' => $customer_id ) );
			$order['provider_customer_id'] = $customer_id;
		}

		$session = $provider->create_checkout( $order, $this->success_url(), $this->cancel_url() );
		if ( null === $session || empty( $session['url'] ) ) {
			throw new ApiError( 'server_error', 'Session de paiement indisponible.' );
		}
		$expires_at = ! empty( $session['expires_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $session['expires_at'] ) : null;
		$this->orders->update( $order_id, array(
			'status'              => OrderStatus::AWAITING_PAYMENT,
			'provider_session_id' => (string) $session['session_id'],
			'checkout_url'        => (string) $session['url'],
			'checkout_expires_at' => $expires_at,
		) );
		$this->emit( 'order.created', $order );
		$this->emit( 'checkout.created', $order );

		return array(
			'order_uuid'   => (string) $order['public_uuid'],
			'checkout_url' => (string) $session['url'],
			'expires_at'   => $expires_at,
		);
	}

	private function success_url(): string {
		return (string) apply_filters( 'postelio/billing/success_url', home_url( '/paiement-confirmation/?order={ORDER_UUID}' ) );
	}
	private function cancel_url(): string {
		return (string) apply_filters( 'postelio/billing/cancel_url', home_url( '/paiement-annule/' ) );
	}

	/** @param array<string,mixed> $order */
	private function emit( string $event, array $order ): void {
		Core::instance()->events()->emit( $event, array(
			'order_uuid'    => (string) $order['public_uuid'],
			'company_id'    => (int) $order['company_id'],
			'resource_type' => 'billing_order',
			'resource_id'   => (string) $order['public_uuid'],
			'audit'         => array( 'order_uuid' => (string) $order['public_uuid'], 'product_code' => (string) $order['product_code'] ),
		) );
	}
}
