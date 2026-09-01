<?php
/**
 * Migration alerts #1 : favoris, recherches sauvegardées, deliveries.
 *
 * - `postelio_job_favorites`     : 1 favori par (candidat, source, référence). Identité d'offre
 *   CANONIQUE = (job_source, job_reference) — un UUID Postelio et un identifiant externe ne
 *   partagent PAS le même espace de noms (§4). Aucun snapshot : la vue est résolue à la lecture.
 * - `postelio_saved_searches`    : filtres validés (whitelist Jobs) + fréquence d'alerte. Modèle
 *   UNIQUE de vérité : `alert_frequency` (disabled|daily|weekly) — pas de drapeau `enabled`
 *   redondant. `filters_hash` empêche les doublons pour un même candidat (§14).
 * - `postelio_alert_deliveries`  : garantie anti-doublon. La contrainte UNIQUE
 *   (saved_search_id, job_source, job_reference) est réservée AVANT toute notification (§6) :
 *   deux workers/retries concurrents ne peuvent pas notifier deux fois la même offre.
 *
 * Migration idempotente (CREATE TABLE IF NOT EXISTS).
 *
 * @package Postelio\Alerts\Migrations
 */

namespace Postelio\Alerts\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateAlertsTables implements Migration {

	public function version(): string {
		return '1';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = Migrator::charset_collate();
		$p       = $wpdb->prefix;

		$favorites = "CREATE TABLE IF NOT EXISTS {$p}postelio_job_favorites (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			candidate_user_id BIGINT UNSIGNED NOT NULL,
			job_source VARCHAR(20) NOT NULL DEFAULT 'native',
			job_reference VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			UNIQUE KEY favorite_identity (candidate_user_id, job_source, job_reference),
			KEY candidate (candidate_user_id)
		) {$collate};";

		$searches = "CREATE TABLE IF NOT EXISTS {$p}postelio_saved_searches (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			candidate_user_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL DEFAULT '',
			filters LONGTEXT NOT NULL,
			filters_hash CHAR(40) NOT NULL DEFAULT '',
			alert_frequency VARCHAR(10) NOT NULL DEFAULT 'disabled',
			timezone VARCHAR(40) NOT NULL DEFAULT 'Europe/Paris',
			cursor_ts DATETIME NULL,
			last_run_at DATETIME NULL,
			next_run_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			UNIQUE KEY search_identity (candidate_user_id, filters_hash),
			KEY candidate (candidate_user_id),
			KEY due (alert_frequency, next_run_at)
		) {$collate};";

		$deliveries = "CREATE TABLE IF NOT EXISTS {$p}postelio_alert_deliveries (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			saved_search_id BIGINT UNSIGNED NOT NULL,
			job_source VARCHAR(20) NOT NULL DEFAULT 'native',
			job_reference VARCHAR(64) NOT NULL,
			status VARCHAR(10) NOT NULL DEFAULT 'reserved',
			reserved_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			sent_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY delivery_identity (saved_search_id, job_source, job_reference),
			KEY search (saved_search_id),
			KEY reserved (reserved_at)
		) {$collate};";

		foreach ( array( $favorites, $searches, $deliveries ) as $sql ) {
			$wpdb->query( (string) preg_replace( '/^[\t ]+/m', '', $sql ) ); // phpcs:ignore WordPress.DB
		}
	}
}
