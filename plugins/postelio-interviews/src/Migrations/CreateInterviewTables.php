<?php
/**
 * Migration interviews #1 : entretiens + historique.
 *
 * `wp_postelio_interviews` porte le créneau courant (UTC + fuseau métier), le type,
 * les données spécifiques (visio/sur place/téléphone en JSON), le statut, et la
 * proposition de re-créneau en attente (`proposed_*`). `wp_postelio_interview_history`
 * journalise chaque transition (acteur, action, from/to, métadonnée minimale).
 *
 * @package Postelio\Interviews\Migrations
 */

namespace Postelio\Interviews\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateInterviewTables implements Migration {

	public function version(): string {
		return '1';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = Migrator::charset_collate();
		$p       = $wpdb->prefix;

		$interviews = "CREATE TABLE {$p}postelio_interviews (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			application_id BIGINT UNSIGNED NOT NULL,
			application_uuid VARCHAR(36) NOT NULL,
			job_uuid VARCHAR(36) NULL,
			candidate_user_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			company_uuid VARCHAR(36) NULL,
			created_by BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'proposed',
			scheduled_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
			timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
			location_data LONGTEXT NULL,
			video_data LONGTEXT NULL,
			phone_data LONGTEXT NULL,
			instructions LONGTEXT NULL,
			proposed_scheduled_at DATETIME NULL,
			proposed_by BIGINT UNSIGNED NULL,
			proposed_message LONGTEXT NULL,
			candidate_response_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			cancelled_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			KEY application (application_id),
			KEY candidate (candidate_user_id),
			KEY company (company_id),
			KEY status (status),
			KEY scheduled (scheduled_at)
		) {$collate};";

		$history = "CREATE TABLE {$p}postelio_interview_history (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			interview_id BIGINT UNSIGNED NOT NULL,
			interview_uuid VARCHAR(36) NOT NULL,
			actor_user_id BIGINT UNSIGNED NULL,
			actor_role VARCHAR(20) NOT NULL,
			action VARCHAR(30) NOT NULL,
			from_status VARCHAR(30) NULL,
			to_status VARCHAR(30) NULL,
			metadata LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY interview (interview_id),
			KEY interview_uuid (interview_uuid)
		) {$collate};";

		dbDelta( $interviews );
		dbDelta( $history );
	}
}
