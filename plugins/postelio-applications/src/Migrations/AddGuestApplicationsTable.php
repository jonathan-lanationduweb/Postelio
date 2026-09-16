<?php
/**
 * Migration applications #2 : candidatures GUEST (parcours sans compte).
 *
 * `wp_postelio_guest_applications` stocke une candidature soumise sans compte, en attente
 * de confirmation d'e-mail (double opt-in). Elle n'est JAMAIS visible du recruteur tant
 * qu'elle n'est pas confirmée : à la confirmation elle est matérialisée en candidature
 * réelle (`wp_postelio_applications`) rattachée à un compte candidat (existant ou invité),
 * puis marquée `linked`.
 *
 * Sécurité : l'accès à une candidature guest exige le JETON signé (haché ici) ; l'UUID
 * public seul ne suffit pas. Le jeton a une durée de vie limitée. Consentement horodaté (RGPD).
 *
 * @package Postelio\Applications\Migrations
 */

namespace Postelio\Applications\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AddGuestApplicationsTable implements Migration {

	public function version(): string {
		return '2';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = Migrator::charset_collate();
		$p       = $wpdb->prefix;

		$guest = "CREATE TABLE {$p}postelio_guest_applications (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			job_id BIGINT UNSIGNED NOT NULL,
			job_uuid VARCHAR(36) NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			company_uuid VARCHAR(36) NOT NULL,
			email VARCHAR(191) NOT NULL,
			first_name VARCHAR(100) NOT NULL,
			last_name VARCHAR(100) NOT NULL,
			cv_reference VARCHAR(190) NULL,
			screening_answers LONGTEXT NULL,
			message TEXT NULL,
			consent_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			token_hash CHAR(64) NOT NULL,
			token_expires BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending_email',
			linked_application_uuid VARCHAR(36) NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			KEY token_hash (token_hash),
			KEY job_email (job_id, email(150)),
			KEY status (status)
		) {$collate};";

		dbDelta( $guest );
	}
}
