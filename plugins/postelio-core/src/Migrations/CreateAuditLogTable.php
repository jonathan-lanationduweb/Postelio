<?php
/**
 * Migration core #1 : table d'audit `wp_postelio_audit_log` (append-only).
 *
 * Champs : docs/backend/security.md §7. L'IP n'est renseignée que pour les
 * événements de sécurité/audit justifiés (décision V1 — D7).
 *
 * @package Postelio\Core\Migrations
 */

namespace Postelio\Core\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateAuditLogTable implements Migration {

	public function version(): string {
		return '1';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'postelio_audit_log';
		$collate = Migrator::charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_id BIGINT UNSIGNED NULL,
			actor_role VARCHAR(50) NULL,
			action VARCHAR(100) NOT NULL,
			resource_type VARCHAR(50) NULL,
			resource_id VARCHAR(64) NULL,
			metadata LONGTEXT NULL,
			ip VARCHAR(45) NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY actor_id (actor_id),
			KEY resource (resource_type, resource_id),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta( $sql );
	}
}
