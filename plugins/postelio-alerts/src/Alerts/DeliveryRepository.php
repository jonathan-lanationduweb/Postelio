<?php
/**
 * Deliveries d'alertes : garantie qu'une même offre n'est notifiée qu'UNE fois par recherche.
 *
 * La réservation est ATOMIQUE via la contrainte UNIQUE (saved_search_id, job_source,
 * job_reference) : `reserve()` tente une insertion et retourne true UNIQUEMENT si la ligne a été
 * créée (offre nouvelle pour cette alerte). Deux workers/retries concurrents qui réservent la
 * même offre : un seul obtient true. La réservation précède toujours la notification (§6).
 *
 * Rétention (§7) : purge planifiée des deliveries anciennes (≈13 mois par défaut, filtrable).
 * Ne supprime JAMAIS les recherches elles-mêmes.
 *
 * @package Postelio\Alerts\Alerts
 */

namespace Postelio\Alerts\Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_ALERTS_TESTING' ) ) {
		exit;
	}
}

final class DeliveryRepository {

	public const STATUS_RESERVED = 'reserved';
	public const STATUS_SENT     = 'sent';
	public const STATUS_SKIPPED  = 'skipped';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_alert_deliveries';
	}

	/**
	 * Réserve une (offre × recherche). Retourne true si NOUVELLE réservation, false si déjà
	 * connue (doublon → à ignorer). Atomique : `INSERT IGNORE` sur la contrainte UNIQUE.
	 */
	public function reserve( int $saved_search_id, string $job_source, string $job_reference ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// INSERT IGNORE : rows_affected = 1 si insérée, 0 si la contrainte UNIQUE a bloqué.
		$sql = 'INSERT IGNORE INTO ' . self::table()
			. ' (saved_search_id, job_source, job_reference, status, reserved_at, created_at) VALUES (%d, %s, %s, %s, %s, %s)';
		$wpdb->query( $wpdb->prepare( $sql, $saved_search_id, $job_source, $job_reference, self::STATUS_RESERVED, $now, $now ) ); // phpcs:ignore WordPress.DB
		return 1 === (int) $wpdb->rows_affected;
	}

	/** Marque une réservation comme envoyée (notification émise). */
	public function mark_sent( int $saved_search_id, string $job_source, string $job_reference ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array( 'status' => self::STATUS_SENT, 'sent_at' => current_time( 'mysql', true ) ),
			array( 'saved_search_id' => $saved_search_id, 'job_source' => $job_source, 'job_reference' => $job_reference )
		);
	}

	/** Marque une réservation comme ignorée (ex. destinataire inactif au moment de l'envoi). */
	public function mark_skipped( int $saved_search_id, string $job_source, string $job_reference ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array( 'status' => self::STATUS_SKIPPED ),
			array( 'saved_search_id' => $saved_search_id, 'job_source' => $job_source, 'job_reference' => $job_reference )
		);
	}

	/** Nombre de deliveries envoyées récemment (supervision admin, agrégé). */
	public function count_sent_since( string $datetime_utc ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = %s AND sent_at >= %s", self::STATUS_SENT, $datetime_utc ) );
	}

	/** Supprime toutes les deliveries d'une recherche (purge RGPD / suppression de recherche). */
	public function delete_for_search( int $saved_search_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'saved_search_id' => $saved_search_id ) );
	}

	/**
	 * Purge de rétention : supprime les deliveries plus anciennes que $before_utc. Bornée par
	 * lot (LIMIT) pour éviter une requête massive ; retourne le nombre supprimé.
	 */
	public function purge_before( string $before_utc, int $limit = 5000 ): int {
		global $wpdb;
		$sql = 'DELETE FROM ' . self::table() . ' WHERE reserved_at < %s LIMIT %d';
		return (int) $wpdb->query( $wpdb->prepare( $sql, $before_utc, max( 1, $limit ) ) ); // phpcs:ignore WordPress.DB
	}
}
