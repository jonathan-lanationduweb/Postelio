<?php
/**
 * Présentation API Billing. Vue synthétique métier : UUID publics uniquement, jamais d'ID SQL
 * ni de secret provider (session/payment_intent/customer). Le reçu (receipt_url) est exposé
 * seulement s'il provient d'un paiement réussi.
 *
 * @package Postelio\Billing\Http
 */

namespace Postelio\Billing\Http;

use Postelio\Billing\Domain\PaymentStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BillingPresenter {

	/**
	 * @param array<string,mixed>            $order
	 * @param array<int,array<string,mixed>> $payments
	 * @return array<string,mixed>
	 */
	public static function order_view( array $order, array $payments = array() ): array {
		$snapshot   = is_array( $order['snapshot'] ?? null ) ? $order['snapshot'] : array();
		$product    = is_array( $snapshot['product'] ?? null ) ? $snapshot['product'] : array();
		$receipt    = null;
		$pay_status = 'none';
		foreach ( $payments as $p ) {
			if ( PaymentStatus::SUCCEEDED === $p['status'] ) {
				$pay_status = 'succeeded';
				if ( ! empty( $p['receipt_url'] ) ) {
					$receipt = (string) $p['receipt_url'];
				}
				break;
			}
			$pay_status = (string) $p['status'];
		}

		return array(
			'order_uuid'         => (string) $order['public_uuid'],
			'status'             => (string) $order['status'],
			'payment_status'     => $pay_status,
			'fulfillment_status' => (string) $order['fulfillment_status'],
			'product'            => array(
				'code'  => (string) $order['product_code'],
				'label' => (string) ( $product['label'] ?? $order['product_code'] ),
			),
			'resource'           => array(
				'type' => (string) $order['resource_type'],
				'uuid' => (string) $order['resource_uuid'],
			),
			'amount'             => (int) $order['total_amount'],
			'currency'           => (string) $order['currency'],
			'tax_amount'         => (int) $order['tax_amount'],
			'created_at'         => self::iso( (string) $order['created_at'] ),
			'paid_at'            => self::iso( (string) ( $order['paid_at'] ?? '' ) ),
			'fulfilled_at'       => self::iso( (string) ( $order['fulfilled_at'] ?? '' ) ),
			'receipt_available'  => null !== $receipt,
			'receipt_url'        => $receipt,
			'failure'            => self::failure_label( (string) $order['status'] ),
		);
	}

	private static function failure_label( string $status ): ?string {
		switch ( $status ) {
			case 'payment_failed':
				return 'Le paiement n\'a pas abouti.';
			case 'expired':
				return 'La session de paiement a expiré.';
			case 'fulfillment_failed':
			case 'manual_review':
				return 'Le paiement est reçu ; l\'activation est en cours de traitement.';
			case 'refunded':
				return 'Ce paiement a été remboursé.';
		}
		return null;
	}

	private static function iso( string $mysql_utc ): ?string {
		if ( '' === $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return null;
		}
		return str_replace( ' ', 'T', $mysql_utc ) . 'Z';
	}
}
