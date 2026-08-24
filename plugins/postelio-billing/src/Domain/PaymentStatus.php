<?php
/**
 * Machine à états d'un paiement Billing (V1). Logique PURE. Statuts MÉTIER Postelio — on ne
 * recopie pas mécaniquement tous les statuts Stripe. `duplicate` marque un 2e paiement réussi
 * pour un ordre déjà fulfilé (revue admin, jamais de 2e renouvellement).
 *
 * @package Postelio\Billing\Domain
 */

namespace Postelio\Billing\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_BILLING_TESTING' ) ) {
		exit;
	}
}

final class PaymentStatus {

	public const CREATED   = 'created';
	public const PENDING   = 'pending';
	public const SUCCEEDED = 'succeeded';
	public const FAILED    = 'failed';
	public const REFUNDED  = 'refunded';
	public const DISPUTED  = 'disputed';
	public const DUPLICATE = 'duplicate';

	/** @var array<string, string[]> */
	private const TRANSITIONS = array(
		self::CREATED   => array( self::PENDING, self::SUCCEEDED, self::FAILED, self::DUPLICATE ),
		self::PENDING   => array( self::SUCCEEDED, self::FAILED ),
		self::SUCCEEDED => array( self::REFUNDED, self::DISPUTED, self::DUPLICATE ),
		self::FAILED    => array( self::SUCCEEDED ), // paiement asynchrone finalement confirmé
		self::DUPLICATE => array( self::REFUNDED ),
		self::REFUNDED  => array( self::DISPUTED ),
		self::DISPUTED  => array(),
	);

	/** @return string[] */
	public static function all(): array {
		return array_keys( self::TRANSITIONS );
	}

	public static function is_status( string $s ): bool {
		return isset( self::TRANSITIONS[ $s ] );
	}

	public static function can_transition( string $from, string $to ): bool {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}
}
