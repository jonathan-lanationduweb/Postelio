<?php
/**
 * Migration messaging #1 : conversations, participants, messages.
 *
 * `UNIQUE (application_id)` sur les conversations garantit EN BASE « 1 conversation
 * par candidature » (concurrence). `UNIQUE (conversation_id, user_id)` évite les
 * doublons de participant. Messages immuables (D6) : pas d'UPDATE du `body`.
 *
 * @package Postelio\Messaging\Migrations
 */

namespace Postelio\Messaging\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateMessagingTables implements Migration {

	public function version(): string {
		return '1';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = Migrator::charset_collate();
		$p       = $wpdb->prefix;

		$conv = "CREATE TABLE {$p}postelio_conversations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'application',
			application_id BIGINT UNSIGNED NULL,
			application_uuid VARCHAR(36) NULL,
			job_uuid VARCHAR(36) NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			company_uuid VARCHAR(36) NULL,
			company_name VARCHAR(255) NULL,
			subject VARCHAR(255) NULL,
			candidate_user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_message_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			UNIQUE KEY application_id (application_id),
			KEY company (company_id),
			KEY candidate (candidate_user_id),
			KEY last_message (last_message_at)
		) {$collate};";

		$part = "CREATE TABLE {$p}postelio_conversation_participants (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL,
			joined_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_read_at DATETIME NULL,
			last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			archived_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY conv_user (conversation_id, user_id),
			KEY user (user_id)
		) {$collate};";

		$msg = "CREATE TABLE {$p}postelio_messages (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_uuid VARCHAR(36) NOT NULL,
			conversation_id BIGINT UNSIGNED NOT NULL,
			sender_user_id BIGINT UNSIGNED NOT NULL,
			sender_role VARCHAR(20) NOT NULL,
			body LONGTEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'sent',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_uuid (public_uuid),
			KEY conversation_order (conversation_id, id)
		) {$collate};";

		dbDelta( $conv );
		dbDelta( $part );
		dbDelta( $msg );
	}
}
