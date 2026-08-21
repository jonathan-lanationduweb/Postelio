<?php
/**
 * Migration users #3 : UUID public du profil candidat (décision D2).
 *
 * Ajoute `public_uuid` + index unique s'ils manquent (installations existantes),
 * puis backfille les lignes sans UUID. Idempotente : réexécutée, elle ne fait rien.
 *
 * @package Postelio\Users\Migrations
 */

namespace Postelio\Users\Migrations;

use Postelio\Core\Migrations\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AddCandidateUuid implements Migration {

	public function version(): string {
		return '3';
	}

	public function up(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'postelio_candidate_profiles';

		// 1. Colonne (si absente).
		$has_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'public_uuid' ) );
		if ( ! $has_col ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN public_uuid VARCHAR(36) NULL AFTER user_id" );
		}

		// 2. Index unique (si absent).
		$has_idx = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'public_uuid' ) );
		if ( ! $has_idx ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY public_uuid (public_uuid)" );
		}

		// 3. Backfill des profils existants (UUID généré côté serveur, unique).
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( "SELECT id FROM {$table} WHERE public_uuid IS NULL OR public_uuid = ''" );
		foreach ( $ids as $id ) {
			$wpdb->update(
				$table,
				array( 'public_uuid' => wp_generate_uuid4() ),
				array( 'id' => (int) $id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}
}
