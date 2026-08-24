<?php
/**
 * Accès aux offres (CPT `postelio_job` + meta).
 *
 * Champs filtrables stockés en meta discrète (ville, contrat, catégorie…) ; le
 * reste en JSON (`pst_detail`). Le statut de cycle de vie vit dans `pst_status`
 * (le post_status WP reste `publish` en interne). UUID public (D2) en meta.
 *
 * @package Postelio\Jobs\Jobs
 */

namespace Postelio\Jobs\Jobs;

use Postelio\Jobs\Cpt\JobPostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobRepository {

	public const META_UUID       = 'pst_uuid';
	public const META_COMPANY_ID = 'pst_company_id';
	public const META_STATUS     = 'pst_status';
	public const META_DATE_PUB   = 'pst_date_publication';
	public const META_DATE_EXP   = 'pst_date_expiration';
	public const META_REVISION      = 'pst_revision';
	public const META_RENEWED_AT    = 'pst_renewed_at';
	public const META_RENEWAL_COUNT = 'pst_renewal_count';

	/** Registre d'idempotence des renouvellements (garantie de concurrence par UNIQUE). */
	public static function renewals_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_job_renewals';
	}

	/** Champs scalaires filtrables (meta discrète). */
	private const FILTER_FIELDS = array(
		'ville', 'departement', 'contrat', 'teletravail', 'categorie',
		'niveau_etude', 'experience', 'salaire_annuel', 'alternance', 'stage', 'debutant',
	);

	/** Champs détaillés (JSON). */
	private const DETAIL_FIELDS = array(
		'duree', 'temps_travail', 'salaire', 'resume', 'categorie_label',
		'niveau_etude_label', 'experience_label', 'missions', 'profil',
		'competences', 'avantages', 'email_reception', 'questions_preselection', 'processus',
	);

	/**
	 * @param array{id:int, uuid:string, nom:string} $company
	 * @param array<string, mixed> $data
	 */
	public function create( array $company, int $author_id, string $titre, string $description, array $data ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => JobPostType::TYPE,
				'post_status'  => 'publish',
				'post_title'   => $titre,
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
		update_post_meta( $post_id, self::META_COMPANY_ID, (int) $company['id'] );
		update_post_meta( $post_id, 'pst_company_uuid', (string) $company['uuid'] );
		update_post_meta( $post_id, 'pst_company_name', (string) $company['nom'] );
		update_post_meta( $post_id, self::META_STATUS, JobStateMachine::DRAFT );
		update_post_meta( $post_id, self::META_REVISION, 1 ); // version métier (pour snapshot candidature)
		$this->write_fields( $post_id, $data );

		return $post_id;
	}

	public function exists( int $id ): bool {
		$p = get_post( $id );
		return $p instanceof \WP_Post && JobPostType::TYPE === $p->post_type;
	}

	/** @return array<string, mixed>|null */
	public function get( int $id ): ?array {
		$p = get_post( $id );
		if ( ! $p instanceof \WP_Post || JobPostType::TYPE !== $p->post_type ) {
			return null;
		}
		return $this->assemble( $p );
	}

	/** @return array<string, mixed>|null */
	public function get_by_uuid( string $uuid ): ?array {
		$ids = get_posts(
			array(
				'post_type'      => JobPostType::TYPE,
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
			\Postelio\Core\Log\Logger::error( 'Collision UUID offre (unicité applicative).', array( 'uuid' => $uuid, 'ids' => array_map( 'intval', $ids ) ) );
		}
		return $this->get( (int) $ids[0] );
	}

	/**
	 * Liste publique (offres published/expiring) avec filtres.
	 *
	 * @param array<string, mixed> $filters
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function list_public( array $filters, int $page, int $per_page ): array {
		$meta = array(
			'relation' => 'AND',
			array( 'key' => self::META_STATUS, 'value' => array( JobStateMachine::PUBLISHED, JobStateMachine::EXPIRING ), 'compare' => 'IN' ),
		);
		foreach ( array( 'ville', 'contrat', 'categorie', 'teletravail', 'niveau_etude', 'experience' ) as $k ) {
			if ( ! empty( $filters[ $k ] ) ) {
				$meta[] = array( 'key' => 'pst_' . $k, 'value' => (string) $filters[ $k ], 'compare' => '=' );
			}
		}
		foreach ( array( 'alternance', 'stage', 'debutant' ) as $flag ) {
			if ( ! empty( $filters[ $flag ] ) ) {
				$meta[] = array( 'key' => 'pst_' . $flag, 'value' => '1', 'compare' => '=' );
			}
		}
		if ( ! empty( $filters['salaire_min'] ) ) {
			$meta[] = array( 'key' => 'pst_salaire_annuel', 'value' => (int) $filters['salaire_min'], 'compare' => '>=', 'type' => 'NUMERIC' );
		}

		$args = array(
			'post_type'      => JobPostType::TYPE,
			'post_status'    => 'publish',
			'paged'          => max( 1, $page ),
			'posts_per_page' => $per_page,
			'meta_query'     => $meta,
			'orderby'        => 'meta_value',
			'meta_key'       => self::META_DATE_PUB,
			'order'          => 'DESC',
		);
		if ( ! empty( $filters['q'] ) ) {
			$args['s'] = (string) $filters['q'];
		}

		$q     = new \WP_Query( $args );
		$items = array();
		foreach ( $q->posts as $p ) {
			$items[] = $this->assemble( $p );
		}
		return array( 'items' => $items, 'total' => (int) $q->found_posts );
	}

	/**
	 * Toutes les offres d'une entreprise (tous statuts).
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function list_by_company( int $company_id ): array {
		$q = new \WP_Query(
			array(
				'post_type'      => JobPostType::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'meta_key'       => self::META_COMPANY_ID,
				'meta_value'     => $company_id,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		return array_map( array( $this, 'assemble' ), $q->posts );
	}

	/**
	 * IDs d'offres dans un statut dont la date d'expiration est atteinte/proche.
	 *
	 * @return int[]
	 */
	public function ids_by_status_expiring_before( string $status, string $date ): array {
		return get_posts(
			array(
				'post_type'      => JobPostType::TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => self::META_STATUS, 'value' => $status, 'compare' => '=' ),
					array( 'key' => self::META_DATE_EXP, 'value' => $date, 'compare' => '<=', 'type' => 'DATE' ),
				),
			)
		);
	}

	public function update_title_description( int $id, ?string $titre, ?string $description ): void {
		$data = array( 'ID' => $id );
		if ( null !== $titre ) {
			$data['post_title'] = $titre;
		}
		if ( null !== $description ) {
			$data['post_content'] = $description;
		}
		if ( count( $data ) > 1 ) {
			wp_update_post( $data );
		}
	}

	/** @param array<string, mixed> $data */
	public function write_fields( int $id, array $data ): void {
		$detail = $this->json_meta( $id, 'pst_detail' );
		foreach ( self::FILTER_FIELDS as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$v = $data[ $k ];
				if ( in_array( $k, array( 'alternance', 'stage', 'debutant' ), true ) ) {
					update_post_meta( $id, 'pst_' . $k, $v ? '1' : '0' );
				} elseif ( 'salaire_annuel' === $k ) {
					update_post_meta( $id, 'pst_' . $k, (int) $v );
				} else {
					update_post_meta( $id, 'pst_' . $k, sanitize_text_field( (string) $v ) );
				}
			}
		}
		foreach ( self::DETAIL_FIELDS as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$detail[ $k ] = $data[ $k ];
			}
		}
		update_post_meta( $id, 'pst_detail', wp_json_encode( $detail ) );
	}

	/** @param array<string, mixed> $extra */
	public function set_status( int $id, string $status, array $extra = array() ): void {
		update_post_meta( $id, self::META_STATUS, $status );
		if ( array_key_exists( 'date_publication', $extra ) ) {
			update_post_meta( $id, self::META_DATE_PUB, (string) $extra['date_publication'] );
		}
		if ( array_key_exists( 'date_expiration', $extra ) ) {
			update_post_meta( $id, self::META_DATE_EXP, (string) $extra['date_expiration'] );
		}
	}

	/**
	 * Applique un renouvellement (appelé via Api\JobLifecycle, jamais par billing
	 * directement) : offre remise en ligne, nouvelle échéance, compteur incrémenté.
	 *
	 * @return array{new_expiration:string, count:int}
	 */
	public function apply_renewal( int $id, int $days ): array {
		$today   = gmdate( 'Y-m-d' );
		$current = (string) get_post_meta( $id, self::META_DATE_EXP, true );
		$base    = ( $current && $current > $today ) ? $current : $today; // prolonge sans perdre de temps restant
		$new_exp = gmdate( 'Y-m-d', strtotime( $base . ' +' . $days . ' days' ) );
		$count   = (int) get_post_meta( $id, self::META_RENEWAL_COUNT, true ) + 1;

		update_post_meta( $id, self::META_STATUS, JobStateMachine::PUBLISHED );
		update_post_meta( $id, self::META_DATE_EXP, $new_exp );
		update_post_meta( $id, self::META_RENEWED_AT, current_time( 'mysql', true ) );
		update_post_meta( $id, self::META_RENEWAL_COUNT, $count );

		return array( 'new_expiration' => $new_exp, 'count' => $count );
	}

	/** Un renouvellement a-t-il déjà été enregistré pour cette clé ? (order_uuid globalement unique) */
	public function renewal_applied( string $ref ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::renewals_table() . ' WHERE idempotency_key = %s', $ref ) ) > 0;
	}

	/**
	 * Renouvellement IDEMPOTENT (exactly-once) piloté par une clé métier `$ref` (= order_uuid).
	 *
	 * Concurrence : l'arbitrage repose sur la contrainte `UNIQUE(idempotency_key)` via un
	 * `INSERT IGNORE` atomique — PAS sur une hypothèse de séquentialité. Deux tentatives
	 * simultanées calculent chacune une cible, mais une SEULE gagne l'INSERT ; les autres lisent
	 * la MÊME cible persistée. Le registre est écrit AVANT l'application ; l'application est un
	 * SET ABSOLU (jamais un ++/+=), donc tout rejeu/retry/crash converge vers la même valeur.
	 * `won` = true seulement pour la tentative gagnante (→ un seul `job.renewed`).
	 *
	 * @return array{new_expiration:string, count:int, already_applied:bool, won:bool}
	 */
	public function apply_renewal_idempotent( int $id, int $days, string $ref ): array {
		global $wpdb;
		$table = self::renewals_table();

		// Cible prospective (peut être recalculée à l'identique par une tentative concurrente).
		$today   = gmdate( 'Y-m-d' );
		$current = (string) get_post_meta( $id, self::META_DATE_EXP, true );
		$base    = ( $current && $current > $today ) ? $current : $today; // ne perd pas de temps restant
		$new_exp = gmdate( 'Y-m-d', strtotime( $base . ' +' . $days . ' days' ) );
		$count   = (int) get_post_meta( $id, self::META_RENEWAL_COUNT, true ) + 1;
		$now     = current_time( 'mysql', true );

		// Claim ATOMIQUE : INSERT IGNORE → 1 ligne si gagné, 0 si collision UNIQUE (concurrent).
		$affected = (int) $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (job_id, idempotency_key, target_status, target_expiration, target_renewal_count, status, created_at, applied_at) VALUES (%d, %s, %s, %s, %d, %s, %s, %s)",
			$id, $ref, JobStateMachine::PUBLISHED, $new_exp, $count, 'applied', $now, $now
		) );

		// Cible AUTORITAIRE = la ligne persistée (celle du gagnant), pas notre calcul local.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT target_status, target_expiration, target_renewal_count, applied_at FROM {$table} WHERE idempotency_key = %s", $ref ), ARRAY_A );
		$t_status = $row ? (string) $row['target_status'] : JobStateMachine::PUBLISHED;
		$t_exp    = $row ? (string) $row['target_expiration'] : $new_exp;
		$t_count  = $row ? (int) $row['target_renewal_count'] : $count;
		$t_at     = $row ? (string) ( $row['applied_at'] ?: $now ) : $now;

		// Application par SET ABSOLU (idempotente : rejouer réécrit les mêmes valeurs).
		$this->set_renewal_absolute( $id, $t_status, $t_exp, $t_count, $t_at );

		return array(
			'new_expiration'  => $t_exp,
			'count'           => $t_count,
			'already_applied' => 1 !== $affected, // collision UNIQUE → déjà revendiqué ailleurs
			'won'             => 1 === $affected,
		);
	}

	/** Applique par valeurs ABSOLUES (idempotent au replay). */
	private function set_renewal_absolute( int $id, string $status, string $new_exp, int $count, string $renewed_at ): void {
		update_post_meta( $id, self::META_STATUS, $status );
		update_post_meta( $id, self::META_DATE_EXP, $new_exp );
		update_post_meta( $id, self::META_RENEWAL_COUNT, $count );
		update_post_meta( $id, self::META_RENEWED_AT, $renewed_at );
	}

	/** Incrémente la version métier (utilisée pour le snapshot de candidature). */
	public function bump_revision( int $id ): int {
		$rev = (int) get_post_meta( $id, self::META_REVISION, true ) + 1;
		update_post_meta( $id, self::META_REVISION, $rev );
		return $rev;
	}

	public function delete( int $id ): void {
		wp_delete_post( $id, true );
	}

	// --- Interne -----------------------------------------------------------

	/** @return array<string, mixed> */
	private function assemble( \WP_Post $p ): array {
		$id     = (int) $p->ID;
		$fields = array();
		foreach ( self::FILTER_FIELDS as $k ) {
			$raw = get_post_meta( $id, 'pst_' . $k, true );
			if ( in_array( $k, array( 'alternance', 'stage', 'debutant' ), true ) ) {
				$fields[ $k ] = '1' === (string) $raw;
			} elseif ( 'salaire_annuel' === $k ) {
				$fields[ $k ] = '' !== $raw ? (int) $raw : null;
			} else {
				$fields[ $k ] = '' !== $raw ? (string) $raw : null;
			}
		}
		return array_merge(
			array(
				'id'               => $id,
				'uuid'             => (string) get_post_meta( $id, self::META_UUID, true ),
				'author_id'        => (int) $p->post_author,
				'company'          => array(
					'id'   => (int) get_post_meta( $id, self::META_COMPANY_ID, true ),
					'uuid' => (string) get_post_meta( $id, 'pst_company_uuid', true ),
					'nom'  => (string) get_post_meta( $id, 'pst_company_name', true ),
				),
				'status'           => (string) ( get_post_meta( $id, self::META_STATUS, true ) ?: JobStateMachine::DRAFT ),
				'revision'         => (int) ( get_post_meta( $id, self::META_REVISION, true ) ?: 1 ),
				'renewal_count'    => (int) get_post_meta( $id, self::META_RENEWAL_COUNT, true ),
				'renewed_at'       => (string) get_post_meta( $id, self::META_RENEWED_AT, true ),
				'titre'            => $p->post_title,
				'description'      => $p->post_content,
				'date_publication' => (string) get_post_meta( $id, self::META_DATE_PUB, true ),
				'date_expiration'  => (string) get_post_meta( $id, self::META_DATE_EXP, true ),
				'detail'           => $this->json_meta( $id, 'pst_detail' ),
			),
			$fields
		);
	}

	/** @return array<string, mixed> */
	private function json_meta( int $id, string $key ): array {
		$raw = get_post_meta( $id, $key, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$d = json_decode( $raw, true );
		return is_array( $d ) ? $d : array();
	}

	private function unique_uuid(): string {
		do {
			$uuid = wp_generate_uuid4();
		} while ( null !== $this->get_by_uuid( $uuid ) );
		return $uuid;
	}
}
