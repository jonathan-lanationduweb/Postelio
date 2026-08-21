<?php
/**
 * Migration notifications #1 : notifications in-app + file de livraisons multi-canal.
 *
 * `dedup_key` UNIQUE garantit l'idempotence (un événement rejoué ne crée pas de
 * doublon). Les livraisons (e-mail V1, push futur) sont mises en file et traitées par
 * un worker Scheduler ; jamais d'envoi bloquant dans la requête HTTP.
 *
 * @package Postelio\Notifications\Migrations
 */

namespace Postelio\Notifications\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateNotificationTables implements Migration {

	public function version(): string {
		return '1';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = Migrator::charset_collate();
		$p       = $wpdb->prefix;

		$notifs = "CREATE TABLE {$p}postelio_notifications (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(50) NOT NULL,
			event_name VARCHAR(50) NOT NULL,
			priority VARCHAR(20) NOT NULL DEFAULT 'normal',
			title VARCHAR(255) NOT NULL,
			body TEXT NULL,
			resource_type VARCHAR(30) NULL,
			resource_uuid VARCHAR(36) NULL,
			action_type VARCHAR(40) NULL,
			action_payload LONGTEXT NULL,
			group_key VARCHAR(120) NULL,
			dedup_key VARCHAR(191) NOT NULL,
			read_at DATETIME NULL,
			resolved_at DATETIME NULL,
			expires_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			UNIQUE KEY dedup_key (dedup_key),
			KEY user_unread (user_id, read_at),
			KEY user_created (user_id, created_at),
			KEY grp (group_key)
		) {$collate};";

		$deliveries = "CREATE TABLE {$p}postelio_notification_deliveries (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			channel VARCHAR(20) NOT NULL DEFAULT 'email',
			template VARCHAR(60) NOT NULL,
			recipient_email VARCHAR(190) NULL,
			payload LONGTEXT NULL,
			dedup_key VARCHAR(191) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			priority VARCHAR(20) NOT NULL DEFAULT 'normal',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
			scheduled_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			processing_at DATETIME NULL,
			sent_at DATETIME NULL,
			failed_at DATETIME NULL,
			last_error VARCHAR(255) NULL,
			provider_message_id VARCHAR(190) NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			UNIQUE KEY dedup_channel (dedup_key, channel),
			KEY due (status, scheduled_at),
			KEY user (user_id)
		) {$collate};";

		dbDelta( $notifs );
		dbDelta( $deliveries );
	}
}
