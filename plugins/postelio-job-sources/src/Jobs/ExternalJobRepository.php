<?php
/**
 * Persistance des offres externes (`wp_postelio_external_jobs`). Upsert idempotent sur
 * `(source_key, external_id)` : le `public_uuid` et `local_visibility` sont POSTELIO-OWNED
 * et jamais écrasés par une resync. Le retrait confirmé anonymise la ligne (licence Art. 7).
 *
 * @package Postelio\JobSources\Jobs
 */

namespace Postelio\JobSources\Jobs;

use Postelio\JobSources\Sources\FranceTravail\FranceTravailProvider;
use Postelio\JobSources\Sources\NormalizedExternalJob;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ExternalJobRepository {

	private const JSON_COLS = array( 'attribution_data', 'source_metadata' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_external_jobs';
	}

	/**
	 * Insère ou met à jour une offre. Retourne {status: created|updated|unchanged, uuid}.
	 *
	 * @param array<string, mixed> $attribution
	 * @return array{status:string, uuid:string}
	 */
	public function upsert( NormalizedExternalJob $n, string $slice_key, array $attribution ): array {
		global $wpdb;
		$now      = current_time( 'mysql', true );
		$row      = $n->to_row();
		$existing = $this->get_by_source_id( $n->source_key, $n->external_id );

		if ( null === $existing ) {
			$uuid = $this->unique_uuid();
			$wpdb->insert(
				self::table(),
				array(
					'public_uuid'          => $uuid,
					'source_key'           => $row['source_key'],
					'external_id'          => $row['external_id'],
					'sync_status'          => 'active',
					'local_visibility'     => 'visible',
					'slice_key'            => $slice_key,
					'title'                => $row['title'],
					'description'          => $row['description'],
					'company_name'         => $row['company_name'],
					'company_logo_url'     => $row['company_logo_url'],
					'ville'                => $row['ville'],
					'commune_insee'        => $row['commune_insee'],
					'code_postal'          => $row['code_postal'],
					'latitude'             => $row['latitude'],
					'longitude'            => $row['longitude'],
					'contract_code_source' => $row['contract_code_source'],
					'contract_normalized'  => $row['contract_normalized'],
					'nature_contract'      => $row['nature_contract'],
					'rome_code'            => $row['rome_code'],
					'rome_label'           => $row['rome_label'],
					'naf_code'             => $row['naf_code'],
					'sector_label'         => $row['sector_label'],
					'experience_code'      => $row['experience_code'],
					'experience_label'     => $row['experience_label'],
					'salary_text'          => $row['salary_text'],
					'working_time'         => $row['working_time'],
					'alternance'           => $row['alternance'],
					'source_published_at'  => $row['source_published_at'],
					'source_updated_at'    => $row['source_updated_at'],
					'external_url'         => $row['external_url'],
					'external_apply_url'   => $row['external_apply_url'],
					'application_mode'     => $row['application_mode'],
					'attribution_data'     => wp_json_encode( $attribution ),
					'source_metadata'      => wp_json_encode( $row['source_metadata'] ),
					'content_hash'         => $row['content_hash'],
					'mapping_version'      => FranceTravailProvider::MAPPING_VERSION,
					'last_synced_at'       => $now,
					'created_at'           => $now,
					'updated_at'           => $now,
				)
			);
			return array( 'status' => 'created', 'uuid' => $uuid );
		}

		// Inchangé : on ne touche que last_synced_at (+ ré-active si réapparue) et le slice.
		if ( (string) $existing['content_hash'] === (string) $row['content_hash'] && 'active' === $existing['sync_status'] ) {
			$wpdb->update( self::table(), array( 'last_synced_at' => $now, 'slice_key' => $slice_key, 'updated_at' => $now ), array( 'id' => (int) $existing['id'] ) );
			return array( 'status' => 'unchanged', 'uuid' => (string) $existing['public_uuid'] );
		}

		// Mise à jour : champs SOURCE-OWNED réécrits ; UUID + local_visibility PRÉSERVÉS.
		$wpdb->update(
			self::table(),
			array(
				'sync_status'          => 'active',
				'removed_at'           => null,
				'slice_key'            => $slice_key,
				'title'                => $row['title'],
				'description'          => $row['description'],
				'company_name'         => $row['company_name'],
				'company_logo_url'     => $row['company_logo_url'],
				'ville'                => $row['ville'],
				'commune_insee'        => $row['commune_insee'],
				'code_postal'          => $row['code_postal'],
				'latitude'             => $row['latitude'],
				'longitude'            => $row['longitude'],
				'contract_code_source' => $row['contract_code_source'],
				'contract_normalized'  => $row['contract_normalized'],
				'nature_contract'      => $row['nature_contract'],
				'rome_code'            => $row['rome_code'],
				'rome_label'           => $row['rome_label'],
				'naf_code'             => $row['naf_code'],
				'sector_label'         => $row['sector_label'],
				'experience_code'      => $row['experience_code'],
				'experience_label'     => $row['experience_label'],
				'salary_text'          => $row['salary_text'],
				'working_time'         => $row['working_time'],
				'alternance'           => $row['alternance'],
				'source_published_at'  => $row['source_published_at'],
				'source_updated_at'    => $row['source_updated_at'],
				'external_url'         => $row['external_url'],
				'external_apply_url'   => $row['external_apply_url'],
				'attribution_data'     => wp_json_encode( $attribution ),
				'source_metadata'      => wp_json_encode( $row['source_metadata'] ),
				'content_hash'         => $row['content_hash'],
				'mapping_version'      => FranceTravailProvider::MAPPING_VERSION,
				'last_synced_at'       => $now,
				'updated_at'           => $now,
			),
			array( 'id' => (int) $existing['id'] )
		);
		return array( 'status' => 'updated', 'uuid' => (string) $existing['public_uuid'] );
	}

	/**
	 * Retrait CONFIRMÉ (§17 CAS B) : offres actives d'un slice non revues lors d'un refresh
	 * complet réussi → `removed` + **anonymisation** (licence Art. 7). Retourne le nb retiré.
	 *
	 * @param string[] $seen_ids
	 */
	public function mark_removed_for_slice( string $source_key, string $slice_key, array $seen_ids, string $run_started_at ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// Déterministe (pas de comparaison de temps, robuste à la même seconde) : on retire
		// les offres actives du slice NON revues dans ce refresh complet (absentes de $seen).
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, external_id FROM ' . self::table() . " WHERE source_key = %s AND slice_key = %s AND sync_status = 'active'", $source_key, $slice_key ), ARRAY_A );
		$seen  = array_flip( $seen_ids );
		$count = 0;
		foreach ( (array) $rows as $row ) {
			if ( isset( $seen[ (string) $row['external_id'] ] ) ) {
				continue; // revue dans ce run
			}
			$this->anonymize_and_remove( (int) $row['id'], $now );
			++$count;
		}
		return $count;
	}

	private function anonymize_and_remove( int $id, string $now ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'sync_status'       => 'removed',
				'removed_at'        => $now,
				'company_name'      => null,
				'company_logo_url'  => null,
				'description'       => null,
				'external_url'      => null,
				'external_apply_url' => null,
				'commune_insee'     => null,
				'code_postal'       => null,
				'ville'             => null,
				'source_metadata'   => null,
				'updated_at'        => $now,
			),
			array( 'id' => $id )
		);
	}

	/** @return array<string, mixed>|null */
	public function get_by_source_id( string $source_key, string $external_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE source_key = %s AND external_id = %s', $source_key, $external_id ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	/** @return array<string, mixed>|null */
	public function get_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE public_uuid = %s', $uuid ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	public function set_visibility( string $uuid, string $visibility ): bool {
		global $wpdb;
		$visibility = in_array( $visibility, array( 'visible', 'hidden' ), true ) ? $visibility : 'visible';
		return (bool) $wpdb->update( self::table(), array( 'local_visibility' => $visibility, 'updated_at' => current_time( 'mysql', true ) ), array( 'public_uuid' => $uuid ) );
	}

	public function count_active( string $source_key ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE source_key = %s AND sync_status = 'active'", $source_key ) );
	}

	/**
	 * Recherche publique : offres visibles + actives, filtres best-effort, tri date desc.
	 *
	 * @param array<string, mixed> $filters
	 * @param string[]             $disabled_sources
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function search_public( array $filters, int $limit, array $disabled_sources = array() ): array {
		global $wpdb;
		$where = "sync_status = 'active' AND local_visibility = 'visible'";
		$args  = array();
		if ( $disabled_sources ) {
			$where .= ' AND source_key NOT IN (' . implode( ',', array_fill( 0, count( $disabled_sources ), '%s' ) ) . ')';
			$args   = array_merge( $args, $disabled_sources );
		}
		if ( ! empty( $filters['ville'] ) ) {
			$where .= ' AND ville LIKE %s';
			$args[] = '%' . $wpdb->esc_like( (string) $filters['ville'] ) . '%';
		}
		if ( ! empty( $filters['contrat'] ) ) {
			$where .= ' AND contract_normalized = %s';
			$args[] = (string) $filters['contrat'];
		}
		if ( ! empty( $filters['experience'] ) ) {
			$where .= ' AND experience_code = %s';
			$args[] = (string) $filters['experience'];
		}
		if ( ! empty( $filters['q'] ) ) {
			$where .= ' AND ( title LIKE %s OR description LIKE %s )';
			$like   = '%' . $wpdb->esc_like( (string) $filters['q'] ) . '%';
			$args[]  = $like;
			$args[]  = $like;
		}
		// Filtre INTERNE (alertes) : offres dont la date de publication à la source est
		// postérieure/égale au curseur. Cohérent avec le natif (même clé `published_after`).
		if ( ! empty( $filters['published_after'] ) ) {
			$where .= ' AND source_published_at >= %s';
			$args[] = (string) $filters['published_after'];
		}
		$count_sql = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}";
		$total     = $args
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) )
			: (int) $wpdb->get_var( $count_sql );
		// La requête SELECT porte TOUJOURS un placeholder (%d du LIMIT) → prepare valide.
		$sql  = 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY source_published_at DESC, id DESC LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( max( 1, $limit ) ) ) ), ARRAY_A );
		return array( 'items' => array_map( array( $this, 'decode' ), $rows ?: array() ), 'total' => $total );
	}

	public function delete_all(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore
	}

	/** @return array<string, mixed> */
	private function decode( array $row ): array {
		$row['id'] = (int) $row['id'];
		foreach ( self::JSON_COLS as $c ) {
			$row[ $c ] = ( isset( $row[ $c ] ) && '' !== $row[ $c ] && null !== $row[ $c ] ) ? json_decode( (string) $row[ $c ], true ) : array();
		}
		$row['source_type'] = 'external';
		return $row;
	}

	private function unique_uuid(): string {
		do {
			$uuid = wp_generate_uuid4();
		} while ( null !== $this->get_by_uuid( $uuid ) );
		return $uuid;
	}
}
