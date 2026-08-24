<?php
/**
 * Machine à états métier de l'ordre Billing (V1). Logique PURE (testable hors WP). Statuts
 * fixés côté serveur uniquement — jamais par le front. `fulfillment_status` est un sous-état
 * distinct (le paiement peut être `paid` sans que le renouvellement soit `done`).
 *
 * @package Postelio\Billing\Domain
 */

namespace Postelio\Billing\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_BILLING_TESTING' ) ) {
		exit;
	}
}

final class OrderStatus {

	public const CREATED             = 'created';
	public const AWAITING_PAYMENT    = 'awaiting_payment';
	public const PAID                = 'paid';
	public const FULFILLMENT_PENDING = 'fulfillment_pending';
	public const FULFILLED           = 'fulfilled';
	public const PAYMENT_FAILED      = 'payment_failed';
	public const EXPIRED             = 'expired';
	public const FULFILLMENT_FAILED  = 'fulfillment_failed';
	public const MANUAL_REVIEW       = 'manual_review';
	public const REFUNDED            = 'refunded';

	/** Sous-états de fulfillment. */
	public const F_NONE    = 'none';
	public const F_PENDING = 'pending';
	public const F_DONE    = 'done';
	public const F_FAILED  = 'failed';

	/** @var array<string, string[]> */
	private const TRANSITIONS = array(
		self::CREATED             => array( self::AWAITING_PAYMENT, self::EXPIRED ),
		self::AWAITING_PAYMENT    => array( self::PAID, self::PAYMENT_FAILED, self::EXPIRED ),
		self::PAYMENT_FAILED      => array( self::AWAITING_PAYMENT, self::PAID ), // re-tentative / paiement asynchrone tardif
		self::PAID                => array( self::FULFILLMENT_PENDING, self::MANUAL_REVIEW, self::REFUNDED ),
		self::FULFILLMENT_PENDING => array( self::FULFILLED, self::FULFILLMENT_FAILED, self::MANUAL_REVIEW ),
		self::FULFILLMENT_FAILED  => array( self::FULFILLMENT_PENDING, self::FULFILLED, self::MANUAL_REVIEW ),
		self::FULFILLED           => array( self::REFUNDED, self::MANUAL_REVIEW ),
		self::MANUAL_REVIEW       => array( self::FULFILLED, self::REFUNDED ),
		self::EXPIRED             => array(),
		self::REFUNDED            => array(),
	);

	/** @return string[] */
	public static function all(): array {
		return array_keys( self::TRANSITIONS );
	}

	public static function is_status( string $s ): bool {
		return isset( self::TRANSITIONS[ $s ] );
	}

	public static function is_terminal( string $s ): bool {
		return self::is_status( $s ) && array() === self::TRANSITIONS[ $s ];
	}

	public static function can_transition( string $from, string $to ): bool {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}
}
