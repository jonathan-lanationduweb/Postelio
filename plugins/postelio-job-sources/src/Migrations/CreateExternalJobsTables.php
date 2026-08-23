<?php
/**
 * Migration job-sources #1 : offres externes + journal de synchronisation.
 *
 * Décision validée : les offres externes vont dans une **table dédiée** (jamais le CPT
 * `postelio_job`) — volumétrie potentielle (100k–500k) incompatible avec wp_posts/wp_postmeta.
 * `UNIQUE(source_key, external_id)` garantit qu'une offre resynchronisée N fois reste UNE
 * ressource ; `public_uuid` est l'identifiant public stable (favoris/alertes/recherche).
 *
 * @package Postelio\JobSources\Migrations
 */

namespace Postelio\JobSources\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateExternalJobsTables implements Migration {

	public function version(): string {
		return '1';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = Migrator::charset_collate();
		$p       = $wpdb->prefix;

		$jobs = "CREATE TABLE IF NOT EXISTS {$p}postelio_external_jobs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			source_key VARCHAR(40) NOT NULL,
			external_id VARCHAR(191) NOT NULL,
			sync_status VARCHAR(20) NOT NULL DEFAULT 'active',
			local_visibility VARCHAR(20) NOT NULL DEFAULT 'visible',
			slice_key VARCHAR(191) NULL,
			title VARCHAR(255) NOT NULL,
			description LONGTEXT NULL,
			company_name VARCHAR(255) NULL,
			company_logo_url VARCHAR(512) NULL,
			ville VARCHAR(191) NULL,
			commune_insee VARCHAR(10) NULL,
			code_postal VARCHAR(10) NULL,
			latitude DECIMAL(10,6) NULL,
			longitude DECIMAL(10,6) NULL,
			contract_code_source VARCHAR(20) NULL,
			contract_normalized VARCHAR(20) NULL,
			nature_contract VARCHAR(20) NULL,
			rome_code VARCHAR(10) NULL,
			rome_label VARCHAR(191) NULL,
			naf_code VARCHAR(10) NULL,
			sector_label VARCHAR(191) NULL,
			experience_code VARCHAR(10) NULL,
			experience_label VARCHAR(191) NULL,
			salary_text VARCHAR(255) NULL,
			working_time VARCHAR(100) NULL,
			alternance TINYINT(1) NOT NULL DEFAULT 0,
			source_published_at DATETIME NULL,
			source_updated_at DATETIME NULL,
			external_url VARCHAR(768) NULL,
			external_apply_url VARCHAR(768) NULL,
			application_mode VARCHAR(30) NOT NULL DEFAULT 'external_redirect',
			attribution_data LONGTEXT NULL,
			source_metadata LONGTEXT NULL,
			content_hash CHAR(40) NULL,
			mapping_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			last_synced_at DATETIME NULL,
			removed_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			UNIQUE KEY source_external (source_key, external_id),
			KEY source (source_key),
			KEY sync_status (sync_status),
			KEY local_visibility (local_visibility),
			KEY slice (slice_key),
			KEY commune (commune_insee),
			KEY contract (contract_normalized),
			KEY rome (rome_code),
			KEY published (source_published_at),
			KEY synced (last_synced_at)
		) {$collate};";

		$runs = "CREATE TABLE IF NOT EXISTS {$p}postelio_job_source_sync_runs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			provider_key VARCHAR(40) NOT NULL,
			slice_key VARCHAR(191) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'running',
			sync_cursor VARCHAR(191) NULL,
			pages INT UNSIGNED NOT NULL DEFAULT 0,
			fetched INT UNSIGNED NOT NULL DEFAULT 0,
			created_count INT UNSIGNED NOT NULL DEFAULT 0,
			updated_count INT UNSIGNED NOT NULL DEFAULT 0,
			unchanged_count INT UNSIGNED NOT NULL DEFAULT 0,
			stale_count INT UNSIGNED NOT NULL DEFAULT 0,
			removed_count INT UNSIGNED NOT NULL DEFAULT 0,
			error_count INT UNSIGNED NOT NULL DEFAULT 0,
			last_error VARCHAR(255) NULL,
			started_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			finished_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			KEY provider (provider_key),
			KEY provider_slice (provider_key, slice_key),
			KEY started (started_at)
		) {$collate};";

		// Création directe idempotente (CREATE TABLE IF NOT EXISTS) : plus robuste que
		// dbDelta pour ces tables dédiées volumineuses (dbDelta est sensible au formatage).
		// Les évolutions de schéma passeront par des migrations versionnées explicites.
		$dedent = static fn( string $sql ): string => (string) preg_replace( '/^[\t ]+/m', '', $sql );
		$wpdb->query( $dedent( $jobs ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $dedent( $runs ) ); // phpcs:ignore WordPress.DB
	}
}
