<?php
/**
 * Contrat de LECTURE ADMIN des fichiers (consommé par postelio-admin). KPI globaux (par statut /
 * type / provider, volume de stockage) et liste TECHNIQUE minimale, MÉTADONNÉES uniquement.
 * N'expose JAMAIS : storage_key, chemin disque, nom de fichier original, contenu, ni identité du
 * propriétaire. Aucune mutation. Ce n'est PAS une bibliothèque de CV navigable.
 *
 * @package Postelio\Files\Api
 */

namespace Postelio\Files\Api;

use Postelio\Files\Files\FileRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FileAdminDirectory {

	/** Statuts connus (le scanner peut marquer « quarantined »). */
	private const STATUSES = array( 'ready', 'archived', 'quarantined', 'deleted' );

	/** @return array<string,mixed> counts par statut/type/provider + stockage. */
	public static function counts(): array {
		global $wpdb;
		$table = FileRepository::table();

		$by_status = array();
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ) as $r ) {
			$by_status[ (string) $r['status'] ] = (int) $r['n'];
		}
		$by_type = array();
		foreach ( (array) $wpdb->get_results( "SELECT type, COUNT(*) AS n FROM {$table} GROUP BY type", ARRAY_A ) as $r ) {
			$by_type[ (string) $r['type'] ] = (int) $r['n'];
		}
		$by_provider = array();
		foreach ( (array) $wpdb->get_results( "SELECT storage_provider, COUNT(*) AS n FROM {$table} GROUP BY storage_provider", ARRAY_A ) as $r ) {
			$by_provider[ (string) $r['storage_provider'] ] = (int) $r['n'];
		}
		// Stockage « vivant » (hors supprimés).
		$live_bytes = (int) $wpdb->get_var( "SELECT COALESCE(SUM(size_bytes),0) FROM {$table} WHERE status <> 'deleted'" );
		$total      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		return array(
			'total'        => $total,
			'by_status'    => $by_status,
			'by_type'      => $by_type,
			'by_provider'  => $by_provider,
			'live_bytes'   => $live_bytes,
			'quarantined'  => (int) ( $by_status['quarantined'] ?? 0 ),
		);
	}

	/**
	 * @param array<string,mixed> $filters status, type
	 * @return array{items:array<int,array<string,mixed>>, total:int}
	 */
	public static function list( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$table = FileRepository::table();
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) && in_array( (string) $filters['status'], self::STATUSES, true ) ) {
			$where[] = 'status = %s';
			$args[]  = (string) $filters['status'];
		}
		if ( ! empty( $filters['type'] ) ) {
			$where[] = 'type = %s';
			$args[]  = (string) $filters['type'];
		}
		$clause = implode( ' AND ', $where );
		$total  = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$clause}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$clause}" ) );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		// SELECT explicite : on ne récupère JAMAIS storage_key / original_name / stored_name / sha256.
		$sql  = "SELECT public_uuid, type, storage_provider, mime_type, size_bytes, status, is_primary, created_at FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'uuid'         => (string) $row['public_uuid'],
				'type'         => (string) $row['type'],
				'provider'     => (string) $row['storage_provider'],
				'mime'         => (string) $row['mime_type'],
				'size_bytes'   => (int) $row['size_bytes'],
				'status'       => (string) $row['status'],
				'is_primary'   => ( (int) $row['is_primary'] ) === 1,
				'created_at'   => (string) $row['created_at'],
			);
		}
		return array( 'items' => $items, 'total' => $total );
	}
}
