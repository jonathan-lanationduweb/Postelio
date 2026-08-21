<?php
/**
 * Migration applications #1 : tables transactionnelles des candidatures.
 *
 *  - wp_postelio_applications         (candidature + snapshot d'offre + réponses)
 *  - wp_postelio_application_history   (historique append-only)
 *  - wp_postelio_recruiter_notes       (notes internes privées)
 *
 * La contrainte UNIQUE (job_id, candidate_user_id) garantit au niveau BASE la règle
 * V1 « 1 candidat = 1 candidature par offre » (y compris après retrait : la ligne
 * subsiste → pas de re-candidature). Voir data-model.md.
 *
 * @package Postelio\Applications\Migrations
 */

namespace Postelio\Applications\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateApplicationsTables implements Migration {

	public function version(): string {
		return '1';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = Migrator::charset_collate();
		$p       = $wpdb->prefix;

		$apps = "CREATE TABLE {$p}postelio_applications (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			candidate_user_id BIGINT UNSIGNED NOT NULL,
			job_id BIGINT UNSIGNED NOT NULL,
			job_uuid VARCHAR(36) NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			company_uuid VARCHAR(36) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			cv_reference VARCHAR(190) NULL,
			job_revision INT NOT NULL DEFAULT 1,
			job_snapshot LONGTEXT NULL,
			screening_answers LONGTEXT NULL,
			candidate_message TEXT NULL,
			sort_order INT NOT NULL DEFAULT 0,
			source VARCHAR(40) NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			withdrawn_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			UNIQUE KEY job_candidate (job_id, candidate_user_id),
			KEY candidate (candidate_user_id),
			KEY company_status (company_id, status),
			KEY job (job_id),
			KEY status (status)
		) {$collate};";

		$hist = "CREATE TABLE {$p}postelio_application_history (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			application_id BIGINT UNSIGNED NOT NULL,
			from_status VARCHAR(20) NULL,
			to_status VARCHAR(20) NULL,
			action VARCHAR(40) NOT NULL,
			actor_id BIGINT UNSIGNED NULL,
			actor_role VARCHAR(30) NULL,
			metadata LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY application (application_id, created_at)
		) {$collate};";

		$notes = "CREATE TABLE {$p}postelio_recruiter_notes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			application_id BIGINT UNSIGNED NOT NULL,
			author_id BIGINT UNSIGNED NOT NULL,
			body TEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY application (application_id)
		) {$collate};";

		dbDelta( $apps );
		dbDelta( $hist );
		dbDelta( $notes );
	}
}
