<?php
/**
 * Migration moderation #1 : reports + cases + case_events (append-only).
 * Exactement 3 tables (pas de table decisions/cache en V1). Création idempotente.
 *
 * @package Postelio\Moderation\Migrations
 */

namespace Postelio\Moderation\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateModerationTables implements Migration {

	public function version(): string {
		return '1';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = Migrator::charset_collate();
		$p       = $wpdb->prefix;

		$reports = "CREATE TABLE IF NOT EXISTS {$p}postelio_moderation_reports (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			reporter_user_id BIGINT UNSIGNED NOT NULL,
			resource_type VARCHAR(30) NOT NULL,
			resource_uuid VARCHAR(191) NOT NULL,
			reason_code VARCHAR(40) NOT NULL,
			description VARCHAR(500) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'received',
			case_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			KEY reporter (reporter_user_id),
			KEY resource (resource_type, resource_uuid(150)),
			KEY dedup (reporter_user_id, resource_type, resource_uuid(100), reason_code),
			KEY cases (case_id)
		) {$collate};";

		$cases = "CREATE TABLE IF NOT EXISTS {$p}postelio_moderation_cases (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			resource_type VARCHAR(30) NOT NULL,
			resource_uuid VARCHAR(191) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			priority VARCHAR(20) NOT NULL DEFAULT 'medium',
			risk_level VARCHAR(20) NOT NULL DEFAULT 'medium',
			origin VARCHAR(20) NOT NULL DEFAULT 'report',
			assigned_to BIGINT UNSIGNED NULL,
			assigned_at DATETIME NULL,
			reports_count INT UNSIGNED NOT NULL DEFAULT 0,
			opened_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			resolved_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			KEY resource (resource_type, resource_uuid),
			KEY status (status),
			KEY priority (priority),
			KEY assigned (assigned_to)
		) {$collate};";

		$events = "CREATE TABLE IF NOT EXISTS {$p}postelio_moderation_case_events (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			case_id BIGINT UNSIGNED NOT NULL,
			actor_user_id BIGINT UNSIGNED NULL,
			actor_role VARCHAR(30) NULL,
			event VARCHAR(30) NOT NULL,
			decision VARCHAR(30) NULL,
			action VARCHAR(40) NULL,
			reason_codes LONGTEXT NULL,
			from_state VARCHAR(20) NULL,
			to_state VARCHAR(20) NULL,
			note TEXT NULL,
			policy_version VARCHAR(20) NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY moderation_case (case_id),
			KEY event (event)
		) {$collate};";

		$dedent = static fn( string $sql ): string => (string) preg_replace( '/^[\t ]+/m', '', $sql );
		$wpdb->query( $dedent( $reports ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $dedent( $cases ) );   // phpcs:ignore WordPress.DB
		$wpdb->query( $dedent( $events ) );  // phpcs:ignore WordPress.DB
	}
}
