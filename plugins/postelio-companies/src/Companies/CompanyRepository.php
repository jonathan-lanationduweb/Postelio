<?php
/**
 * Accès aux entreprises (CPT `postelio_company` + meta).
 *
 * Sépare le stockage en trois zones :
 *  - éditoriale (`pst_editorial`) : modifiable par le recruteur ;
 *  - légale déclarée (`pst_legal_declared`) : saisie recruteur avant vérification ;
 *  - légale vérifiée (`pst_legal_verified`) : renseignée UNIQUEMENT par le service de
 *    vérification (jamais par le recruteur).
 *
 * @package Postelio\Companies\Companies
 */

namespace Postelio\Companies\Companies;

use Postelio\Companies\Cpt\CompanyPostType;
use Postelio\Companies\Verification\Siren;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompanyRepository {

	public const META_UUID     = 'pst_uuid';
	public const META_SIREN    = 'pst_siren';      // SIREN effectif (vérifié sinon déclaré), pour l'unicité
	public const META_STATUS   = 'pst_verification_status';
	public const META_EDITORIAL = 'pst_editorial';
	public const META_LEGAL_DECLARED = 'pst_legal_declared';
	public const META_LEGAL_VERIFIED = 'pst_legal_verified';
	public const META_VERIFICATION   = 'pst_verification';

	/**
	 * Crée une entreprise. Retourne l'ID interne (post).
	 *
	 * @param array<string, mixed> $editorial
	 * @param array<string, mixed> $legal_declared
	 */
	public function create( int $author_id, string $nom, string $description, array $editorial, array $legal_declared ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => CompanyPostType::TYPE,
				'post_status'  => 'publish',
				'post_title'   => $nom,
				'post_content' => $description,
				'post_author'  => $author_id,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}
		$post_id = (int) $post_id;

		update_post_meta( $post_id, self::META_UUID, $this->unique_uuid() );
		update_post_meta( $post_id, self::META_EDITORIAL, wp_json_encode( $editorial ) );
		update_post_meta( $post_id, self::META_LEGAL_DECLARED, wp_json_encode( $legal_declared ) );
		update_post_meta( $post_id, self::META_STATUS, 'unverified' );
		update_post_meta( $post_id, self::META_SIREN, Siren::normalize( (string) ( $legal_declared['siren'] ?? '' ) ) );
		update_post_meta(
			$post_id,
			self::META_VERIFICATION,
			wp_json_encode( array( 'status' => 'unverified' ) )
		);

		return $post_id;
	}

	public function exists( int $id ): bool {
		$post = get_post( $id );
		return $post instanceof \WP_Post && CompanyPostType::TYPE === $post->post_type;
	}

	/**
	 * @return array<string, mixed>|null Modèle interne complet (non redacté).
	 */
	public function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || CompanyPostType::TYPE !== $post->post_type ) {
			return null;
		}
		return $this->assemble( $post );
	}

	/**
	 * Recherche par UUID public.
	 *
	 * Unicité **applicative** (l'UUID est en postmeta, non contraint par un index
	 * unique SQL) : générée côté serveur et contrôlée à la création. Comportement
	 * **déterministe** en cas de corruption historique exceptionnelle (deux lignes
	 * partageant le même UUID) : on retourne toujours l'ID interne le PLUS PETIT et
	 * on journalise l'anomalie. Voir docs/backend/data-model.md#identifiants.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_by_uuid( string $uuid ): ?array {
		$ids = get_posts(
			array(
				'post_type'      => CompanyPostType::TYPE,
				'post_status'    => 'any',
				'meta_key'       => self::META_UUID,
				'meta_value'     => $uuid,
				'fields'         => 'ids',
				'posts_per_page' => 2,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		if ( ! $ids ) {
			return null;
		}
		if ( count( $ids ) > 1 ) {
			\Postelio\Core\Log\Logger::error(
				'Collision d\'UUID entreprise détectée (unicité applicative violée).',
				array( 'uuid' => $uuid, 'ids' => array_map( 'intval', $ids ) )
			);
		}
		return $this->get( (int) $ids[0] );
	}

	/**
	 * ID d'entreprise portant ce SIREN (hors $exclude_id), ou 0. Détection de doublon.
	 */
	public function find_id_by_siren( string $siren, int $exclude_id = 0 ): int {
		$siren = Siren::normalize( $siren );
		if ( '' === $siren ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => CompanyPostType::TYPE,
				'post_status'    => 'any',
				'meta_key'       => self::META_SIREN,
				'meta_value'     => $siren,
				'fields'         => 'ids',
				'posts_per_page' => 2,
				'no_found_rows'  => true,
				'exclude'        => $exclude_id ? array( $exclude_id ) : array(),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Liste publique paginée (entreprises non suspendues).
	 *
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function list_public( int $page, int $per_page ): array {
		$q = new \WP_Query(
			array(
				'post_type'      => CompanyPostType::TYPE,
				'post_status'    => 'publish',
				'paged'          => max( 1, $page ),
				'posts_per_page' => $per_page,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$items = array();
		foreach ( $q->posts as $post ) {
			$items[] = $this->assemble( $post );
		}
		return array( 'items' => $items, 'total' => (int) $q->found_posts );
	}

	// --- Écritures ciblées -------------------------------------------------

	public function update_nom_description( int $id, ?string $nom, ?string $description ): void {
		$data = array( 'ID' => $id );
		if ( null !== $nom ) {
			$data['post_title'] = $nom;
		}
		if ( null !== $description ) {
			$data['post_content'] = $description;
		}
		if ( count( $data ) > 1 ) {
			wp_update_post( $data );
		}
	}

	/** @param array<string, mixed> $editorial */
	public function update_editorial( int $id, array $editorial ): void {
		update_post_meta( $id, self::META_EDITORIAL, wp_json_encode( $editorial ) );
	}

	/** @param array<string, mixed> $legal */
	public function update_legal_declared( int $id, array $legal ): void {
		update_post_meta( $id, self::META_LEGAL_DECLARED, wp_json_encode( $legal ) );
		// Le SIREN effectif suit le déclaré tant qu'aucune vérification n'a figé un vérifié.
		$verified = $this->json_meta( $id, self::META_LEGAL_VERIFIED );
		if ( empty( $verified['siren'] ) ) {
			update_post_meta( $id, self::META_SIREN, Siren::normalize( (string) ( $legal['siren'] ?? '' ) ) );
		}
	}

	/** @param array<string, mixed> $legal */
	public function set_legal_verified( int $id, array $legal ): void {
		update_post_meta( $id, self::META_LEGAL_VERIFIED, wp_json_encode( $legal ) );
		if ( ! empty( $legal['siren'] ) ) {
			update_post_meta( $id, self::META_SIREN, Siren::normalize( (string) $legal['siren'] ) );
		}
	}

	/** @param array<string, mixed> $verification */
	public function set_verification( int $id, array $verification ): void {
		update_post_meta( $id, self::META_VERIFICATION, wp_json_encode( $verification ) );
		update_post_meta( $id, self::META_STATUS, (string) ( $verification['status'] ?? 'unverified' ) );
	}

	public function delete( int $id ): void {
		wp_delete_post( $id, true );
	}

	// --- Interne -----------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function assemble( \WP_Post $post ): array {
		$id            = (int) $post->ID;
		$logo_id       = (int) get_post_meta( $id, '_thumbnail_id', true );
		$editorial     = $this->json_meta( $id, self::META_EDITORIAL );
		$editorial['logo_id']  = $logo_id ?: null;
		$editorial['logo_url'] = $logo_id ? (string) wp_get_attachment_url( $logo_id ) : null;

		return array(
			'id'             => $id,
			'uuid'           => (string) get_post_meta( $id, self::META_UUID, true ),
			'author_id'      => (int) $post->post_author,
			'nom'            => $post->post_title,
			'description'    => $post->post_content,
			'editorial'      => $editorial,
			'legal_declared' => $this->json_meta( $id, self::META_LEGAL_DECLARED ),
			'legal_verified' => $this->json_meta( $id, self::META_LEGAL_VERIFIED ),
			'verification'   => $this->json_meta( $id, self::META_VERIFICATION ) ?: array( 'status' => 'unverified' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function json_meta( int $id, string $key ): array {
		$raw = get_post_meta( $id, $key, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function unique_uuid(): string {
		do {
			$uuid = wp_generate_uuid4();
		} while ( null !== $this->get_by_uuid( $uuid ) );
		return $uuid;
	}
}
