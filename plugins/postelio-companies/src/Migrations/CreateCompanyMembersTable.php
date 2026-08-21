<?php
/**
 * Migration companies #1 : `wp_postelio_company_members` (data-model.md).
 *
 * Relation n-n recruteur ↔ entreprise (une entreprise peut avoir plusieurs
 * recruteurs ; un rôle owner|recruiter par appartenance).
 *
 * @package Postelio\Companies\Migrations
 */

namespace Postelio\Companies\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateCompanyMembersTable implements Migration {

	public function version(): string {
		return '1';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'postelio_company_members';
		$collate = Migrator::charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			role_in_company VARCHAR(20) NOT NULL DEFAULT 'recruiter',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY company_user (company_id, user_id),
			KEY company_id (company_id),
			KEY user_id (user_id)
		) {$collate};";

		dbDelta( $sql );
	}
}
