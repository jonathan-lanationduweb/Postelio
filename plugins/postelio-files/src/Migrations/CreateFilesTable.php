<?php
/**
 * Migration files #1 : `wp_postelio_files` (fichiers privés unifiés).
 *
 * Consolidation (vs data-model cvs/documents/cv_snapshots) : une seule table avec
 * `type` + versions IMMUABLES. Le « snapshot CV » d'une candidature est une simple
 * référence immuable à une ligne (jamais remplacée physiquement) — pas de table de
 * snapshot dédiée. Décision documentée dans data-model.md.
 *
 * @package Postelio\Files\Migrations
 */

namespace Postelio\Files\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateFilesTable implements Migration {

	public function version(): string {
		return '1';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'postelio_files';
		$collate = Migrator::charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			owner_user_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'cv',
			storage_provider VARCHAR(20) NOT NULL DEFAULT 'local',
			storage_key VARCHAR(255) NOT NULL,
			original_name VARCHAR(255) NULL,
			stored_name VARCHAR(255) NOT NULL,
			mime_type VARCHAR(100) NOT NULL,
			size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sha256 CHAR(64) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'ready',
			is_primary TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			KEY owner_type (owner_user_id, type),
			KEY status (status),
			KEY sha256 (sha256)
		) {$collate};";

		dbDelta( $sql );
	}
}
