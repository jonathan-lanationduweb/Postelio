<?php
/**
 * Migration billing #1 : orders + payments + events. EXACTEMENT 3 tables (aucune table
 * invoice en V1). Montants en entiers (centimes), jamais de FLOAT. Création idempotente
 * (`CREATE TABLE IF NOT EXISTS` + dédent) ; index composites en préfixe (limite 1000 o).
 *
 * @package Postelio\Billing\Migrations
 */

namespace Postelio\Billing\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateBillingTables implements Migration {

	public function version(): string {
		return '1';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = Migrator::charset_collate();
		$p       = $wpdb->prefix;

		$orders = "CREATE TABLE IF NOT EXISTS {$p}postelio_billing_orders (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			company_uuid VARCHAR(36) NOT NULL,
			buyer_user_id BIGINT UNSIGNED NOT NULL,
			product_code VARCHAR(40) NOT NULL,
			resource_type VARCHAR(30) NOT NULL,
			resource_uuid VARCHAR(191) NOT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'created',
			fulfillment_status VARCHAR(30) NOT NULL DEFAULT 'none',
			currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
			unit_amount INT UNSIGNED NOT NULL DEFAULT 0,
			tax_mode VARCHAR(20) NOT NULL DEFAULT 'inclusive',
			tax_rate INT UNSIGNED NOT NULL DEFAULT 0,
			tax_amount INT UNSIGNED NOT NULL DEFAULT 0,
			total_amount INT UNSIGNED NOT NULL DEFAULT 0,
			duration_days INT UNSIGNED NOT NULL DEFAULT 0,
			snapshot_json LONGTEXT NULL,
			invoice_number VARCHAR(40) NULL,
			provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
			provider_customer_id VARCHAR(191) NULL,
			provider_session_id VARCHAR(191) NULL,
			idempotency_key VARCHAR(64) NOT NULL,
			checkout_url TEXT NULL,
			checkout_expires_at DATETIME NULL,
			fulfillment_attempts INT UNSIGNED NOT NULL DEFAULT 0,
			last_fulfillment_error VARCHAR(255) NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			paid_at DATETIME NULL,
			fulfilled_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY company (company_id),
			KEY buyer (buyer_user_id),
			KEY resource (resource_type, resource_uuid(150)),
			KEY reuse (company_id, resource_type, resource_uuid(80), status),
			KEY status (status),
			KEY fulfillment (fulfillment_status),
			KEY session (provider_session_id)
		) {$collate};";

		$payments = "CREATE TABLE IF NOT EXISTS {$p}postelio_billing_payments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'created',
			amount INT UNSIGNED NOT NULL DEFAULT 0,
			currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
			provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
			provider_session_id VARCHAR(191) NULL,
			provider_payment_intent_id VARCHAR(191) NULL,
			provider_charge_id VARCHAR(191) NULL,
			receipt_url TEXT NULL,
			failure_code VARCHAR(60) NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			paid_at DATETIME NULL,
			failed_at DATETIME NULL,
			refunded_at DATETIME NULL,
			disputed_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			UNIQUE KEY provider_session (provider_session_id),
			UNIQUE KEY provider_pi (provider_payment_intent_id),
			KEY billing_order (order_id),
			KEY status (status)
		) {$collate};";

		$events = "CREATE TABLE IF NOT EXISTS {$p}postelio_billing_events (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
			event_id VARCHAR(191) NOT NULL,
			event_type VARCHAR(60) NOT NULL,
			related_order_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'received',
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			received_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			processed_at DATETIME NULL,
			last_error VARCHAR(255) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY provider_event (provider, event_id),
			KEY event_type (event_type),
			KEY billing_order (related_order_id),
			KEY status (status)
		) {$collate};";

		$dedent = static fn( string $sql ): string => (string) preg_replace( '/^[\t ]+/m', '', $sql );
		$wpdb->query( $dedent( $orders ) );   // phpcs:ignore WordPress.DB
		$wpdb->query( $dedent( $payments ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $dedent( $events ) );   // phpcs:ignore WordPress.DB
	}
}
