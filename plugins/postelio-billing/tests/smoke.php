<?php
/**
 * Smoke test postelio-billing sur WordPress vivant (FakePaymentProvider — AUCUN appel Stripe).
 *
 *   wp eval-file plugins/postelio-billing/tests/smoke.php --path=wordpress
 *
 * Couvre : activation/3 tables ; checkout (sécurité, ownership, tampering, double-clic) ;
 * webhook (signature invalide, event inconnu, completed→paid→fulfilled, rejeu idempotent,
 * expired, async failed, refund, dispute, double paiement) ; EXACTLY-ONCE + fenêtre de crash ;
 * suspension user/company ; offre non renouvelable ; APIs order/history/admin/health ; events.
 * Nettoie tout.
 *
 * @package Postelio\Billing\Tests
 */

use Postelio\Billing\Domain\OrderStatus;
use Postelio\Billing\Orders\OrderRepository;
use Postelio\Billing\Payments\PaymentRepository;
use Postelio\Billing\Provider\FakePaymentProvider;
use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Companies\Api\CompanyModeration;
use Postelio\Companies\Verification\Siren;
use Postelio\Core\Plugin as Core;
use Postelio\Jobs\Api\JobDirectory;
use Postelio\Jobs\Api\JobLifecycle;
use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Users\Api\UserDirectory;
use Postelio\Users\Api\UserModeration;
use Postelio\Users\Profiles\CandidateProfileRepository;
use Postelio\Users\Profiles\RecruiterProfileRepository;
use Postelio\Users\Users\AccountService;

if ( ! defined( 'ABSPATH' ) ) { echo "WP-CLI requis.\n"; exit( 1 ); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$fail = array(); $pass = 0;
$t = static function ( string $l, bool $c ) use ( &$fail, &$pass ): void {
	if ( $c ) { ++$pass; echo "  [ok]   {$l}\n"; } else { $fail[] = $l; echo "  [FAIL] {$l}\n"; }
};
$req = static function ( string $m, string $route, ?array $body = null, int $user = 0 ): array {
	wp_set_current_user( $user );
	$q = array();
	if ( false !== strpos( $route, '?' ) ) { list( $route, $qs ) = explode( '?', $route, 2 ); parse_str( $qs, $q ); }
	$r = new WP_REST_Request( $m, $route );
	if ( $q ) { $r->set_query_params( $q ); }
	if ( null !== $body ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
	$resp = rest_do_request( $r );
	return array( 'status' => $resp->get_status(), 'data' => $resp->get_data() );
};
// POST webhook : corps brut + en-tête de signature (FakeProvider : 'fake-valid' / 'fake-invalid').
$hook = static function ( array $event, string $sig = 'fake-valid' ): array {
	wp_set_current_user( 0 );
	$r = new WP_REST_Request( 'POST', '/postelio/v1/billing/webhook/stripe' );
	$r->set_header( 'Content-Type', 'application/json' );
	$r->set_header( 'Stripe-Signature', $sig );
	$r->set_body( wp_json_encode( $event ) );
	$resp = rest_do_request( $r );
	return array( 'status' => $resp->get_status(), 'data' => $resp->get_data() );
};
$evt = static function ( string $type, array $object, string $id ): array {
	return array( 'id' => $id, 'type' => $type, 'data' => array( 'object' => $object ) );
};
$accounts = new AccountService( new CandidateProfileRepository(), new RecruiterProfileRepository() );
$mk = static function ( string $role ) use ( $accounts ): int {
	return $accounts->register( array( 'email' => 'smoke.bill.' . $role . '.' . wp_generate_password( 6, false ) . '@postelio.test', 'password' => 'motdepasse123', 'role' => $role ) );
};

global $wpdb;
$audit  = $wpdb->prefix . 'postelio_audit_log';
$orders = new OrderRepository();
$payments = new PaymentRepository();
$jrepo  = new JobRepository();

// Provider factice actif pour tout le smoke.
$fake = new FakePaymentProvider();
add_filter( 'postelio/billing/provider', static function () use ( $fake ) { return $fake; }, 99 );

$users = array(); $jobs = array(); $company_id = 0;

echo "== Activation / 3 tables ==\n";
$t( 'plugin billing actif', is_plugin_active( 'postelio-billing/postelio-billing.php' ) );
$t( 'module billing enregistré', Core::instance()->registry()->has( 'billing' ) );
$t( 'exactement 3 tables billing', count( $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}postelio_billing%'" ) ) === 3 );

echo "== Comptes + entreprise vérifiée + offre expirée ==\n";
$recA = $mk( 'recruiter' ); $cand = $mk( 'candidate' ); $recB = $mk( 'recruiter' );
$users = array( $recA, $cand, $recB );
$admin = (int) ( get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) )[0] ?? 1 );
$siren = '100000000'; while ( ! Siren::is_valid_siren( $siren ) ) { $siren = str_pad( (string) ( ( (int) $siren ) + 1 ), 9, '0', STR_PAD_LEFT ); }
$rc = $req( 'POST', '/postelio/v1/companies', array( 'nom' => 'Billing Test SARL', 'legal' => array( 'siren' => $siren, 'raison_sociale' => 'Billing Test SARL' ) ), $recA );
$cuuid = (string) ( $rc['data']['data']['uuid'] ?? '' );
$company_id = CompanyDirectory::company_of_user( $recA );
$req( 'POST', '/postelio/v1/companies/me/verification', null, $recA );
$req( 'POST', '/postelio/v1/companies/' . $cuuid . '/verification/decision', array( 'decision' => 'verified' ), $admin );

$make_expired_job = function () use ( $req, $recA, $jrepo, &$jobs ): array {
	$u = (string) ( $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'Offre ' . wp_generate_password( 4, false ), 'ville' => 'Lyon', 'contrat' => 'CDI', 'description' => 'Poste neutre.' ), $recA )['data']['data']['uuid'] ?? '' );
	$req( 'POST', '/postelio/v1/jobs/' . $u . '/publish', null, $recA );
	$id = (int) $jrepo->get_by_uuid( $u )['id'];
	$jrepo->set_status( $id, 'expired' );
	$jobs[] = $id;
	return array( $u, $id );
};
list( $juuid, $jid ) = $make_expired_job();
$t( 'offre expirée => can_renew=true', JobLifecycle::can_renew( $jid ) );

echo "== Checkout : sécurité ==\n";
$body_ok = array( 'product_code' => 'job_renewal', 'resource_type' => 'job', 'resource_uuid' => $juuid );
$t( 'anon checkout => 401', 401 === $req( 'POST', '/postelio/v1/billing/checkout', $body_ok, 0 )['status'] );
$t( 'candidat checkout => 403', 403 === $req( 'POST', '/postelio/v1/billing/checkout', $body_ok, $cand )['status'] );
$t( 'recruteur hors entreprise => 404 (non-divulgation)', 404 === $req( 'POST', '/postelio/v1/billing/checkout', $body_ok, $recB )['status'] );

echo "== Checkout : succès + snapshot + anti-tampering ==\n";
$co = $req( 'POST', '/postelio/v1/billing/checkout', array_merge( $body_ok, array( 'amount' => 1, 'currency' => 'USD', 'duration_days' => 999 ) ), $recA );
$t( 'checkout => 201', 201 === $co['status'] );
$order_uuid = (string) ( $co['data']['data']['order_uuid'] ?? '' );
$t( 'réponse expose checkout_url', ! empty( $co['data']['data']['checkout_url'] ) );
$order = $orders->get_by_uuid( $order_uuid );
$t( 'ordre awaiting_payment', OrderStatus::AWAITING_PAYMENT === $order['status'] );
$t( 'tampering montant ignoré (total=1000)', 1000 === (int) $order['total_amount'] );
$t( 'tampering devise ignorée (EUR)', 'EUR' === $order['currency'] );
$t( 'tampering durée ignorée (30)', 30 === (int) $order['duration_days'] );
$t( 'snapshot buyer figé (siren)', ! empty( $order['snapshot']['buyer']['legal']['siren'] ) );
$t( 'snapshot seller présent', isset( $order['snapshot']['seller']['legal_invoice_ready'] ) );

echo "== Double-clic : réutilisation de l'ordre ==\n";
$co2 = $req( 'POST', '/postelio/v1/billing/checkout', $body_ok, $recA );
$t( 'même order_uuid réutilisé', $order_uuid === (string) ( $co2['data']['data']['order_uuid'] ?? '' ) );
$t( 'un seul ordre pour l\'offre', 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . OrderRepository::table() . ' WHERE resource_uuid = %s', $juuid ) ) );

echo "== Webhook : signature invalide / event inconnu ==\n";
$sess = array( 'id' => 'cs_1', 'client_reference_id' => $order_uuid, 'payment_intent' => 'pi_1', 'amount_total' => 1000, 'currency' => 'eur' );
$t( 'signature invalide => 400', 400 === $hook( $evt( 'checkout.session.completed', $sess, 'evt_x' ), 'fake-invalid' )['status'] );
$t( 'event inconnu => 200 ignored', 'ignored' === ( $hook( $evt( 'payment_intent.succeeded', array(), 'evt_pi' ) )['data']['status'] ?? '' ) );

echo "== Webhook : completed => paid => fulfilled (exactly-once) ==\n";
$exp_before = (string) $jrepo->get( $jid )['date_expiration'];
$r1 = $hook( $evt( 'checkout.session.completed', $sess, 'evt_1' ) );
$t( 'webhook completed => 200 processed', 200 === $r1['status'] && 'processed' === ( $r1['data']['status'] ?? '' ) );
$order = $orders->get_by_uuid( $order_uuid );
$job   = $jrepo->get( $jid );
$t( 'ordre fulfilled', OrderStatus::FULFILLED === $order['status'] );
$t( 'offre renouvelée => published', 'published' === $job['status'] );
$t( 'renewal_count = 1', 1 === (int) $job['renewal_count'] );
$t( 'nouvelle échéance future', $job['date_expiration'] > gmdate( 'Y-m-d' ) );
$renewed_evts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE action = 'job.renewed' AND resource_id = %s", (string) $jid ) );
$t( 'un seul job.renewed', 1 === $renewed_evts );

echo "== Webhook : rejeu idempotent (même event) ==\n";
$hook( $evt( 'checkout.session.completed', $sess, 'evt_1' ) );
$job2 = $jrepo->get( $jid );
$t( 'rejeu => renewal_count inchangé (1)', 1 === (int) $job2['renewal_count'] );
$t( 'rejeu => échéance inchangée', $job['date_expiration'] === $job2['date_expiration'] );
$t( 'rejeu => toujours un seul job.renewed', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE action = 'job.renewed' AND resource_id = %s", (string) $jid ) ) );

echo "== FENÊTRE DE CRASH : Jobs renouvelé mais Billing 'crashé' avant fulfilled ==\n";
list( $cu, $cid ) = $make_expired_job();
$cco = $req( 'POST', '/postelio/v1/billing/checkout', array( 'product_code' => 'job_renewal', 'resource_type' => 'job', 'resource_uuid' => $cu ), $recA );
$corder_uuid = (string) $cco['data']['data']['order_uuid'];
$corder = $orders->get_by_uuid( $corder_uuid );
// Simule : paiement encaissé, order paid + fulfillment_pending, Jobs DÉJÀ renouvelé via la clé,
// puis crash avant que Billing ne marque fulfilled.
$payments->insert( array( 'order_id' => (int) $corder['id'], 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'EUR', 'provider_session_id' => 'cs_crash', 'provider_payment_intent_id' => 'pi_crash' ) );
$orders->update( (int) $corder['id'], array( 'status' => OrderStatus::PAID, 'fulfillment_status' => OrderStatus::F_PENDING, 'paid_at' => current_time( 'mysql', true ) ) );
JobLifecycle::renew_after_payment( $cid, 30, array( 'idempotency_key' => $corder_uuid ) ); // Jobs appliqué
$exp_after_apply = (string) $jrepo->get( $cid )['date_expiration'];
$cnt_after_apply = (int) $jrepo->get( $cid )['renewal_count'];
$renew_evt_after_apply = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE action='job.renewed' AND resource_id=%s", (string) $cid ) );
// Reprise du fulfillment (retry) : NE doit PAS re-renouveler.
Core::instance();
\Postelio\Billing\Plugin::instance()->fulfillment()->fulfill( (int) $corder['id'] );
$job_c = $jrepo->get( $cid );
$corder = $orders->get_by_uuid( $corder_uuid );
$t( 'crash-window : échéance inchangée (exactly-once)', $exp_after_apply === $job_c['date_expiration'] );
$t( 'crash-window : renewal_count inchangé', $cnt_after_apply === (int) $job_c['renewal_count'] );
$t( 'crash-window : pas de 2e job.renewed', $renew_evt_after_apply === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE action='job.renewed' AND resource_id=%s", (string) $cid ) ) );
$t( 'crash-window : ordre finalement fulfilled', OrderStatus::FULFILLED === $corder['status'] );

echo "== Double paiement réussi => 1 seul renouvellement + manual_review ==\n";
$before_cnt = (int) $jrepo->get( $jid )['renewal_count'];
$sess_dup = array( 'id' => 'cs_dup', 'client_reference_id' => $order_uuid, 'payment_intent' => 'pi_dup', 'amount_total' => 1000, 'currency' => 'eur' );
$hook( $evt( 'checkout.session.completed', $sess_dup, 'evt_dup' ) );
$order_dup = $orders->get_by_uuid( $order_uuid );
$t( 'double paiement => order manual_review', OrderStatus::MANUAL_REVIEW === $order_dup['status'] );
$t( 'double paiement => renewal_count inchangé', $before_cnt === (int) $jrepo->get( $jid )['renewal_count'] );
$dup = array_filter( $payments->list_for_order( (int) $order_dup['id'] ), static fn( $p ) => 'duplicate' === $p['status'] );
$t( 'double paiement => 2e paiement marqué duplicate', count( $dup ) === 1 );

echo "== Webhook : refund (sans rollback Job) ==\n";
$exp_pre_refund = (string) $jrepo->get( $jid )['date_expiration'];
$hook( $evt( 'charge.refunded', array( 'id' => 'ch_1', 'payment_intent' => 'pi_1' ), 'evt_refund' ) );
$pay1 = $payments->get_by_payment_intent( 'pi_1' );
$t( 'paiement => refunded', 'refunded' === ( $pay1['status'] ?? '' ) );
$t( 'refund NE retire PAS les jours du Job', $exp_pre_refund === (string) $jrepo->get( $jid )['date_expiration'] );

echo "== Webhook : dispute (sans suspension auto) ==\n";
$hook( $evt( 'charge.dispute.created', array( 'id' => 'dp_1', 'payment_intent' => 'pi_dup' ), 'evt_dispute' ) );
$paydup = $payments->get_by_payment_intent( 'pi_dup' );
$t( 'paiement => disputed', 'disputed' === ( $paydup['status'] ?? '' ) );
$t( 'dispute : entreprise NON suspendue automatiquement', false === (bool) \Postelio\Companies\Api\CompanyBilling::identity( $company_id )['suspended'] );

echo "== Session expirée + paiement échoué ==\n";
list( $eu, $eid ) = $make_expired_job();
$eco = $req( 'POST', '/postelio/v1/billing/checkout', array( 'product_code' => 'job_renewal', 'resource_type' => 'job', 'resource_uuid' => $eu ), $recA );
$euuid = (string) $eco['data']['data']['order_uuid'];
$hook( $evt( 'checkout.session.expired', array( 'id' => 'cs_exp', 'client_reference_id' => $euuid ), 'evt_exp' ) );
$t( 'session expirée => order expired', OrderStatus::EXPIRED === $orders->get_by_uuid( $euuid )['status'] );

list( $fu, $fid ) = $make_expired_job();
$fco = $req( 'POST', '/postelio/v1/billing/checkout', array( 'product_code' => 'job_renewal', 'resource_type' => 'job', 'resource_uuid' => $fu ), $recA );
$fuuid = (string) $fco['data']['data']['order_uuid'];
$hook( $evt( 'checkout.session.async_payment_failed', array( 'id' => 'cs_fail', 'client_reference_id' => $fuuid ), 'evt_fail' ) );
$t( 'async failed => order payment_failed', OrderStatus::PAYMENT_FAILED === $orders->get_by_uuid( $fuuid )['status'] );

echo "== Offre non renouvelable ==\n";
$pu = (string) ( $req( 'POST', '/postelio/v1/jobs', array( 'titre' => 'Publiee', 'ville' => 'Lyon', 'contrat' => 'CDI' ), $recA )['data']['data']['uuid'] ?? '' );
$req( 'POST', '/postelio/v1/jobs/' . $pu . '/publish', null, $recA );
$jobs[] = (int) $jrepo->get_by_uuid( $pu )['id'];
$t( 'checkout offre published => 409 invalid_transition', 409 === ( $z = $req( 'POST', '/postelio/v1/billing/checkout', array( 'product_code' => 'job_renewal', 'resource_type' => 'job', 'resource_uuid' => $pu ), $recA ) )['status'] && 'invalid_transition' === ( $z['data']['error']['code'] ?? '' ) );

echo "== Suspension user / company bloque le checkout ==\n";
list( $su, $sid ) = $make_expired_job();
UserModeration::suspend( UserDirectory::public_uuid( $recA ), $admin );
$t( 'user suspendu => checkout 403', 403 === $req( 'POST', '/postelio/v1/billing/checkout', array( 'product_code' => 'job_renewal', 'resource_type' => 'job', 'resource_uuid' => $su ), $recA )['status'] );
UserModeration::unsuspend( UserDirectory::public_uuid( $recA ), $admin );
CompanyModeration::suspend( $admin, $cuuid, 'test' );
$t( 'company suspendue => checkout 403', 403 === $req( 'POST', '/postelio/v1/billing/checkout', array( 'product_code' => 'job_renewal', 'resource_type' => 'job', 'resource_uuid' => $su ), $recA )['status'] );

echo "== Fulfillment après suspension => manual_review (paiement conservé) ==\n";
CompanyModeration::unsuspend( $admin, $cuuid ); // ré-vérifie pour pouvoir créer l'ordre AVANT suspension
$s2 = $req( 'POST', '/postelio/v1/billing/checkout', array( 'product_code' => 'job_renewal', 'resource_type' => 'job', 'resource_uuid' => $su ), $recA );
$s2_uuid = (string) $s2['data']['data']['order_uuid'];
$s2_order = $orders->get_by_uuid( $s2_uuid );
$payments->insert( array( 'order_id' => (int) $s2_order['id'], 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'EUR', 'provider_session_id' => 'cs_s2', 'provider_payment_intent_id' => 'pi_s2' ) );
$orders->update( (int) $s2_order['id'], array( 'status' => OrderStatus::PAID, 'fulfillment_status' => OrderStatus::F_PENDING ) );
CompanyModeration::suspend( $admin, $cuuid, 'test2' ); // suspension APRÈS paiement
\Postelio\Billing\Plugin::instance()->fulfillment()->fulfill( (int) $s2_order['id'] );
$s2_order = $orders->get_by_uuid( $s2_uuid );
$t( 'fulfillment post-suspension => manual_review', OrderStatus::MANUAL_REVIEW === $s2_order['status'] );
$t( 'offre NON renouvelée aveuglément', 'expired' === $jrepo->get( $sid )['status'] );
CompanyModeration::unsuspend( $admin, $cuuid );

echo "== APIs order / history / cross-company ==\n";
$os = $req( 'GET', '/postelio/v1/billing/orders/' . $order_uuid, null, $recA );
$t( 'GET order (owner) => 200', 200 === $os['status'] );
$t( 'order view : pas d\'ID SQL', false === strpos( wp_json_encode( $os['data']['data'] ), '"id"' ) );
$t( 'order view : pas de session/customer secret', false === strpos( wp_json_encode( $os['data']['data'] ), 'provider_session' ) && false === strpos( wp_json_encode( $os['data']['data'] ), 'customer' ) );
$t( 'GET order cross-company => 404', 404 === $req( 'GET', '/postelio/v1/billing/orders/' . $order_uuid, null, $recB )['status'] );
$hist = $req( 'GET', '/postelio/v1/billing/orders', null, $recA );
$t( 'history => 200 + contient l\'ordre', 200 === $hist['status'] && in_array( $order_uuid, array_map( static fn( $o ) => $o['order_uuid'], (array) $hist['data']['data'] ), true ) );
$t( 'candidat history => 403 (pas de pst_pay_renewal)', 403 === $req( 'GET', '/postelio/v1/billing/orders', null, $cand )['status'] );

echo "== Admin + health ==\n";
$t( 'recruteur admin/orders => 403', 403 === $req( 'GET', '/postelio/v1/billing/admin/orders', null, $recA )['status'] );
$t( 'admin admin/orders => 200', 200 === $req( 'GET', '/postelio/v1/billing/admin/orders', null, $admin )['status'] );
$h = $req( 'GET', '/postelio/v1/billing/health', null, $admin );
$t( 'health => 200', 200 === $h['status'] );
$t( 'health provider=fake mode=test status ok', 'fake' === ( $h['data']['data']['provider'] ?? '' ) && 'test' === ( $h['data']['data']['mode'] ?? '' ) && 'ok' === ( $h['data']['data']['status'] ?? '' ) );
$t( 'health seller_configured=false (aucune identité)', false === ( $h['data']['data']['seller_configured'] ?? true ) );
$t( 'recruteur health => 403', 403 === $req( 'GET', '/postelio/v1/billing/health', null, $recA )['status'] );

echo "== Événements billing audités ==\n";
foreach ( array( 'order.created', 'payment.succeeded', 'renewal.applied' ) as $ev ) {
	$t( "audit contient {$ev}", (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE action = %s", $ev ) ) >= 1 );
}

echo "== Nettoyage ==\n";
$wpdb->query( "DELETE FROM " . OrderRepository::table() );
$wpdb->query( "DELETE FROM " . PaymentRepository::table() );
$wpdb->query( "DELETE FROM " . $wpdb->prefix . 'postelio_billing_events' );
foreach ( array_unique( $jobs ) as $j ) { wp_delete_post( $j, true ); }
if ( $company_id ) { ( new \Postelio\Companies\Members\MembershipRepository() )->remove_all_for_company( $company_id ); wp_delete_post( $company_id, true ); }
foreach ( $users as $u ) { ( new CandidateProfileRepository() )->delete_for( $u ); ( new RecruiterProfileRepository() )->delete_for( $u ); wp_delete_user( $u ); }
$wpdb->query( "DELETE FROM {$audit} WHERE action LIKE 'order.%' OR action LIKE 'payment.%' OR action LIKE 'renewal.%' OR action LIKE 'checkout.%' OR action LIKE 'fulfillment.%' OR action LIKE 'job.%' OR action LIKE 'company.%' OR action LIKE 'user.%' OR action LIKE 'plugin.%'" );
echo "  ordres + paiements + events + offres + entreprise + comptes supprimés\n";

echo "\n";
if ( empty( $fail ) ) { WP_CLI::success( "Smoke billing OK : {$pass} vérifications passées." ); }
else { WP_CLI::error( count( $fail ) . ' échec(s) : ' . implode( ' | ', $fail ) ); }
