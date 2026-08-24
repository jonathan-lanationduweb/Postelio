<?php
/**
 * Migration skills #1 : commentaires (« Avis » V1) `wp_postelio_skill_comments`. Table dédiée
 * (pas les commentaires WP) → intégration propre avec ModerationGateway (pré-insert), UUID
 * public, soft-delete. Migration idempotente ; index préfixés au besoin. AUCUN état `pending`.
 *
 * @package Postelio\Skills\Migrations
 */

namespace Postelio\Skills\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateSkillCommentsTable implements Migration {

	public function version(): string {
		return '1';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = Migrator::charset_collate();
		$p       = $wpdb->prefix;

		$sql = "CREATE TABLE IF NOT EXISTS {$p}postelio_skill_comments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			skill_id BIGINT UNSIGNED NOT NULL,
			skill_uuid VARCHAR(36) NOT NULL,
			author_user_id BIGINT UNSIGNED NOT NULL,
			author_role VARCHAR(30) NOT NULL DEFAULT '',
			body TEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'published',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			KEY skill (skill_id),
			KEY author (author_user_id),
			KEY status (status),
			KEY created (created_at)
		) {$collate};";

		$wpdb->query( (string) preg_replace( '/^[\t ]+/m', '', $sql ) ); // phpcs:ignore WordPress.DB
	}
}
