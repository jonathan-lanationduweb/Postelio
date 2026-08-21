<?php
/**
 * Migration users #2 : table `wp_postelio_recruiter_profiles` (1-1 avec l'utilisateur).
 *
 * `company_id` reste nullable : le rattachement à une entreprise relève de
 * postelio-companies (Lot 03). Voir docs/backend/data-model.md#recruiterprofile.
 *
 * @package Postelio\Users\Migrations
 */

namespace Postelio\Users\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateRecruiterProfilesTable implements Migration {

	public function version(): string {
		return '2';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'postelio_recruiter_profiles';
		$collate = Migrator::charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			prenom VARCHAR(100) NULL,
			nom VARCHAR(100) NULL,
			fonction VARCHAR(150) NULL,
			email_pro VARCHAR(190) NULL,
			telephone_pro VARCHAR(40) NULL,
			company_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY company_id (company_id)
		) {$collate};";

		dbDelta( $sql );
	}
}
