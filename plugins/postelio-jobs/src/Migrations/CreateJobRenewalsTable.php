<?php
/**
 * Migration jobs #1 : registre d'idempotence des renouvellements (`wp_postelio_job_renewals`).
 *
 * Sert l'EXACTLY-ONCE du contrat `JobLifecycle::renew_after_payment`. La garantie n'est PAS
 * une hypothèse « webhooks + scheduler séquentiels » : c'est la contrainte `UNIQUE`
 * (idempotency_key) qui arbitre atomiquement deux tentatives concurrentes (INSERT IGNORE →
 * une seule gagne, les autres relisent la MÊME cible). Aucune donnée financière ici : seulement
 * job + clé d'idempotence + cible métier + état d'application. Migration idempotente.
 *
 * @package Postelio\Jobs\Migrations
 */

namespace Postelio\Jobs\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateJobRenewalsTable implements Migration {

	public function version(): string {
		return '1';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = Migrator::charset_collate();
		$p       = $wpdb->prefix;

		$sql = "CREATE TABLE IF NOT EXISTS {$p}postelio_job_renewals (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT UNSIGNED NOT NULL,
			idempotency_key VARCHAR(64) NOT NULL,
			target_status VARCHAR(20) NOT NULL,
			target_expiration VARCHAR(10) NOT NULL,
			target_renewal_count INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'applied',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			applied_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY job (job_id)
		) {$collate};";

		$wpdb->query( (string) preg_replace( '/^[\t ]+/m', '', $sql ) ); // phpcs:ignore WordPress.DB
	}
}
