<?php
/**
 * Tests unitaires SANS dépendance (ni PHPUnit ni WordPress ni réseau) du domaine billing.
 *
 * Exécution :  php plugins/postelio-billing/tests/run-unit.php
 *
 * Couvre la logique PURE : ProductCatalog (prix/TVA, entiers), OrderStatus & PaymentStatus
 * (machines), StripeSignature (vérif HMAC + parsing mode), SellerConfig (complétude),
 * BillingSnapshot (structure figée). Aucun appel Stripe.
 *
 * @package Postelio\Billing\Tests
 */

declare( strict_types=1 );

define( 'POSTELIO_BILLING_TESTING', true );

// --- Shims ------------------------------------------------------------------
$GLOBALS['__pst_filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value = null, ...$args ) {
		return array_key_exists( $hook, $GLOBALS['__pst_filters'] ) ? $GLOBALS['__pst_filters'][ $hook ] : $value;
	}
}

$src = dirname( __DIR__ ) . '/src/';
require_once $src . 'Catalog/ProductCatalog.php';
require_once $src . 'Config/SellerConfig.php';
require_once $src . 'Domain/OrderStatus.php';
require_once $src . 'Domain/PaymentStatus.php';
require_once $src . 'Snapshot/BillingSnapshot.php';
require_once $src . 'Provider/StripeSignature.php';

use Postelio\Billing\Catalog\ProductCatalog;
use Postelio\Billing\Config\SellerConfig;
use Postelio\Billing\Domain\OrderStatus as OS;
use Postelio\Billing\Domain\PaymentStatus as PS;
use Postelio\Billing\Provider\StripeSignature;
use Postelio\Billing\Snapshot\BillingSnapshot;

$tests  = 0;
$failed = array();
function check( string $label, bool $cond ): void {
	global $tests, $failed;
	++$tests;
	echo $cond ? "  [ok]   {$label}\n" : "  [FAIL] {$label}\n";
	if ( ! $cond ) {
		$failed[] = $label;
	}
}

echo "== ProductCatalog ==\n";
check( 'job_renewal existe', ProductCatalog::exists( ProductCatalog::JOB_RENEWAL ) );
check( 'produit inconnu absent', ! ProductCatalog::exists( 'zzz' ) );
$p = ProductCatalog::get( ProductCatalog::JOB_RENEWAL );
check( 'unit_amount = 1000 (centimes)', 1000 === $p['unit_amount'] );
check( 'currency EUR', 'EUR' === $p['currency'] );
check( 'duration 30 j', 30 === $p['duration_days'] );
$price = ProductCatalog::price( ProductCatalog::JOB_RENEWAL );
check( 'TTC total = 1000', 1000 === $price['total_amount'] );
check( 'TVA 20% incluse => net 833', 833 === $price['net_amount'] );
check( 'TVA 20% incluse => tax 167', 167 === $price['tax_amount'] );
check( 'net + tax = total', $price['net_amount'] + $price['tax_amount'] === $price['total_amount'] );
$GLOBALS['__pst_filters']['postelio/billing/tax_mode'] = 'exclusive';
$GLOBALS['__pst_filters']['postelio/billing/tax_rate'] = 2000;
$ex = ProductCatalog::price( ProductCatalog::JOB_RENEWAL );
check( 'exclusive : net = unit 1000', 1000 === $ex['net_amount'] );
check( 'exclusive : tax = 200', 200 === $ex['tax_amount'] );
check( 'exclusive : total = 1200', 1200 === $ex['total_amount'] );
unset( $GLOBALS['__pst_filters']['postelio/billing/tax_mode'], $GLOBALS['__pst_filters']['postelio/billing/tax_rate'] );

echo "== OrderStatus ==\n";
check( 'created -> awaiting_payment', OS::can_transition( OS::CREATED, OS::AWAITING_PAYMENT ) );
check( 'awaiting_payment -> paid', OS::can_transition( OS::AWAITING_PAYMENT, OS::PAID ) );
check( 'paid -> fulfillment_pending', OS::can_transition( OS::PAID, OS::FULFILLMENT_PENDING ) );
check( 'fulfillment_pending -> fulfilled', OS::can_transition( OS::FULFILLMENT_PENDING, OS::FULFILLED ) );
check( 'paid -> manual_review', OS::can_transition( OS::PAID, OS::MANUAL_REVIEW ) );
check( 'fulfilled -> refunded', OS::can_transition( OS::FULFILLED, OS::REFUNDED ) );
check( 'created -/-> fulfilled', ! OS::can_transition( OS::CREATED, OS::FULFILLED ) );
check( 'expired terminal', OS::is_terminal( OS::EXPIRED ) );
check( 'refunded terminal', OS::is_terminal( OS::REFUNDED ) );
check( 'awaiting_payment non terminal', ! OS::is_terminal( OS::AWAITING_PAYMENT ) );

echo "== PaymentStatus ==\n";
check( 'created -> succeeded', PS::can_transition( PS::CREATED, PS::SUCCEEDED ) );
check( 'succeeded -> refunded', PS::can_transition( PS::SUCCEEDED, PS::REFUNDED ) );
check( 'succeeded -> disputed', PS::can_transition( PS::SUCCEEDED, PS::DISPUTED ) );
check( 'created -> duplicate', PS::can_transition( PS::CREATED, PS::DUPLICATE ) );
check( 'refunded -/-> succeeded', ! PS::can_transition( PS::REFUNDED, PS::SUCCEEDED ) );
check( '7 statuts', count( PS::all() ) === 7 );

echo "== StripeSignature ==\n";
$payload = '{"id":"evt_1","type":"checkout.session.completed"}';
$secret  = 'whsec_test_123';
$t       = 1_700_000_000;
$sig     = StripeSignature::expected( $t, $payload, $secret );
$header  = 't=' . $t . ',v1=' . $sig;
check( 'signed_payload = t.payload (conforme Stripe)', StripeSignature::expected( $t, $payload, $secret ) === hash_hmac( 'sha256', $t . '.' . $payload, $secret ) );
check( 'parse t + plusieurs v1', StripeSignature::parse( 't=' . $t . ',v1=a,v1=b' ) === array( 't' => $t, 'v1' => array( 'a', 'b' ) ) );
check( 'signature valide dans tolérance', StripeSignature::verify( $payload, $header, $secret, $t + 10 ) );
check( 'timestamp ANCIEN hors tolérance rejeté', ! StripeSignature::verify( $payload, $header, $secret, $t + 10000 ) );
check( 'timestamp FUTUR hors tolérance rejeté', ! StripeSignature::verify( $payload, $header, $secret, $t - 10000 ) );
check( 'mauvaise signature rejetée', ! StripeSignature::verify( $payload, 't=' . $t . ',v1=deadbeef', $secret, $t ) );
check( 'plusieurs v1 : la 2e correspond => acceptée', StripeSignature::verify( $payload, 't=' . $t . ',v1=deadbeef,v1=' . $sig, $secret, $t + 5 ) );
check( 'header sans t rejeté', ! StripeSignature::verify( $payload, 'v1=' . $sig, $secret, $t ) );
check( 'header sans v1 rejeté', ! StripeSignature::verify( $payload, 't=' . $t, $secret, $t ) );
check( 'header malformé rejeté', ! StripeSignature::verify( $payload, 'garbage', $secret, $t ) );
check( 'espaces dans le header tolérés', StripeSignature::verify( $payload, 't=' . $t . ', v1=' . $sig, $secret, $t + 5 ) );
check( 'secret vide rejeté', ! StripeSignature::verify( $payload, $header, '', $t ) );
check( 'payload altéré rejeté', ! StripeSignature::verify( $payload . 'x', $header, $secret, $t ) );
check( 'mode test', 'test' === StripeSignature::key_mode( 'sk_test_abc' ) );
check( 'mode live', 'live' === StripeSignature::key_mode( 'sk_live_abc' ) );
check( 'mode restricted test/live', 'test' === StripeSignature::key_mode( 'rk_test_x' ) && 'live' === StripeSignature::key_mode( 'rk_live_x' ) );
check( 'mode unknown', 'unknown' === StripeSignature::key_mode( 'nope' ) );

echo "== SellerConfig ==\n";
check( 'incomplet par défaut => is_complete false', ! SellerConfig::is_complete() );
check( 'invoice_legal_ready false si incomplet', ! SellerConfig::legal_invoice_ready() );
$GLOBALS['__pst_filters']['postelio/billing/seller_config'] = array(
	'legal_name' => 'ACME SAS', 'address' => '1 rue X, Paris', 'siren' => '123456789', 'vat_number' => 'FR00123456789',
	'trading_name' => '', 'siret' => '', 'email' => '', 'mentions' => '',
);
check( 'complet => is_complete true', SellerConfig::is_complete() );
check( 'invoice_legal_ready true si complet', SellerConfig::legal_invoice_ready() );

echo "== BillingSnapshot ==\n";
$snap = BillingSnapshot::build( ProductCatalog::JOB_RENEWAL, array( 'company_uuid' => 'c-1', 'name' => 'ACME', 'legal' => array( 'siren' => '123456789' ) ), 'buyer@acme.test' );
check( 'snapshot product code', ProductCatalog::JOB_RENEWAL === $snap['product']['product_code'] );
check( 'snapshot buyer legal figé', '123456789' === $snap['buyer']['legal']['siren'] );
check( 'snapshot buyer email', 'buyer@acme.test' === $snap['buyer']['billing_email'] );
check( 'snapshot seller present', isset( $snap['seller']['legal_name'] ) );
unset( $GLOBALS['__pst_filters']['postelio/billing/seller_config'] );

echo "\n";
if ( empty( $failed ) ) {
	echo "RÉSULTAT : {$tests} assertions, 0 échec. OK\n";
	exit( 0 );
}
echo 'RÉSULTAT : ' . count( $failed ) . " échec(s) sur {$tests} assertions.\n";
exit( 1 );
