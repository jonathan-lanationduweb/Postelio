<?php
/**
 * Persistance des savoir-faire (CPT `postelio_skill` + meta + taxonomies). Le `post_status` WP
 * reste `publish` ; le statut MÉTIER est en meta. Deux drapeaux de masquage à CAUSE DISTINCTE :
 * `pst_mod_hidden` (modération) et `pst_susp_hidden` (suspension user/entreprise) — la levée
 * d'une suspension ne réexpose jamais un contenu masqué par la modération. UUID public exposé,
 * jamais l'ID WP.
 *
 * @package Postelio\Skills\Skills
 */

namespace Postelio\Skills\Skills;

use Postelio\Skills\Cpt\SkillPostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SkillRepository {

	public const META_UUID        = 'pst_uuid';
	public const META_STATUS      = 'pst_status';
	public const META_AUTHOR_TYPE = 'pst_author_type';
	public const META_COMPANY_ID  = 'pst_company_id';
	public const META_COMPANY_UUID = 'pst_company_uuid';
	public const META_REVISION    = 'pst_revision';
	public const META_SUMMARY     = 'pst_summary';
	public const META_DETAILS     = 'pst_details';
	public const META_MOD_HIDDEN  = 'pst_mod_hidden';
	public const META_SUSP_HIDDEN = 'pst_susp_hidden';

	public const AUTHOR_CANDIDATE = 'candidate';
	public const AUTHOR_COMPANY   = 'company';

	/**
	 * @param array<string,mixed> $data
	 * @return int post id (0 si échec)
	 */
	public function create( int $author_id, array $data ): int {
		$post_id = wp_insert_post( array(
			'post_type'    => SkillPostType::TYPE,
			'post_status'  => 'publish', // interne ; le statut métier est en meta
			'post_author'  => $author_id,
			'post_title'   => (string) $data['title'],
			'post_content' => (string) $data['content'],
		), true );
		if ( is_wp_error( $post_id ) || 0 === (int) $post_id ) {
			return 0;
		}
		$post_id = (int) $post_id;
		update_post_meta( $post_id, self::META_UUID, $this->unique_uuid() );
		update_post_meta( $post_id, self::META_STATUS, SkillStateMachine::DRAFT );
		update_post_meta( $post_id, self::META_AUTHOR_TYPE, (string) ( $data['author_type'] ?? self::AUTHOR_CANDIDATE ) );
		update_post_meta( $post_id, self::META_COMPANY_ID, (int) ( $data['company_id'] ?? 0 ) );
		update_post_meta( $post_id, self::META_COMPANY_UUID, (string) ( $data['company_uuid'] ?? '' ) );
		update_post_meta( $post_id, self::META_REVISION, 1 );
		update_post_meta( $post_id, self::META_SUMMARY, (string) ( $data['summary'] ?? '' ) );
		update_post_meta( $post_id, self::META_DETAILS, wp_json_encode( $data['details'] ?? array() ) );
		update_post_meta( $post_id, self::META_MOD_HIDDEN, '0' );
		update_post_meta( $post_id, self::META_SUSP_HIDDEN, '0' );
		$this->set_terms( $post_id, (string) ( $data['category'] ?? '' ), (array) ( $data['tags'] ?? array() ) );
		if ( ! empty( $data['image_id'] ) ) {
			set_post_thumbnail( $post_id, (int) $data['image_id'] );
		}
		return $post_id;
	}

	public function set_terms( int $id, string $category, array $tags ): void {
		if ( '' !== $category ) {
			wp_set_object_terms( $id, array( $category ), SkillPostType::TAX_CAT, false );
		}
		if ( $tags ) {
			wp_set_object_terms( $id, $tags, SkillPostType::TAX_TAG, false );
		}
	}

	/** @param array<string,mixed> $fields */
	public function update_content( int $id, array $fields ): void {
		$post = array( 'ID' => $id );
		if ( array_key_exists( 'title', $fields ) ) {
			$post['post_title'] = (string) $fields['title'];
		}
		if ( array_key_exists( 'content', $fields ) ) {
			$post['post_content'] = (string) $fields['content'];
		}
		if ( count( $post ) > 1 ) {
			wp_update_post( $post );
		}
		if ( array_key_exists( 'summary', $fields ) ) {
			update_post_meta( $id, self::META_SUMMARY, (string) $fields['summary'] );
		}
		if ( array_key_exists( 'details', $fields ) ) {
			update_post_meta( $id, self::META_DETAILS, wp_json_encode( $fields['details'] ) );
		}
		if ( array_key_exists( 'category', $fields ) || array_key_exists( 'tags', $fields ) ) {
			$this->set_terms( $id, (string) ( $fields['category'] ?? $this->category_of( $id ) ), array_key_exists( 'tags', $fields ) ? (array) $fields['tags'] : $this->tags_of( $id ) );
		}
		if ( array_key_exists( 'image_id', $fields ) && ! empty( $fields['image_id'] ) ) {
			set_post_thumbnail( $id, (int) $fields['image_id'] );
		}
	}

	public function set_status( int $id, string $status ): void {
		update_post_meta( $id, self::META_STATUS, $status );
	}

	public function bump_revision( int $id ): int {
		$rev = (int) get_post_meta( $id, self::META_REVISION, true ) + 1;
		update_post_meta( $id, self::META_REVISION, $rev );
		return $rev;
	}

	public function set_mod_hidden( int $id, bool $hidden ): void {
		update_post_meta( $id, self::META_MOD_HIDDEN, $hidden ? '1' : '0' );
	}
	public function set_susp_hidden( int $id, bool $hidden ): void {
		update_post_meta( $id, self::META_SUSP_HIDDEN, $hidden ? '1' : '0' );
	}

	public function exists( int $id ): bool {
		$p = get_post( $id );
		return $p instanceof \WP_Post && SkillPostType::TYPE === $p->post_type;
	}

	/** @return array<string,mixed>|null */
	public function get( int $id ): ?array {
		$p = get_post( $id );
		return ( $p instanceof \WP_Post && SkillPostType::TYPE === $p->post_type ) ? $this->assemble( $p ) : null;
	}

	/** @return array<string,mixed>|null */
	public function get_by_uuid( string $uuid ): ?array {
		$ids = get_posts( array(
			'post_type'      => SkillPostType::TYPE,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_key'       => self::META_UUID,
			'meta_value'     => $uuid,
		) );
		return $ids ? $this->get( (int) $ids[0] ) : null;
	}

	/**
	 * Liste PUBLIQUE : published + non masqué (ni modération ni suspension).
	 *
	 * @param array<string,mixed> $f
	 * @return array{items:array<int,array<string,mixed>>, total:int}
	 */
	public function list_public( array $f, int $page, int $per_page ): array {
		$meta = array(
			array( 'key' => self::META_STATUS, 'value' => SkillStateMachine::PUBLISHED ),
			array( 'key' => self::META_MOD_HIDDEN, 'value' => '1', 'compare' => '!=' ),
			array( 'key' => self::META_SUSP_HIDDEN, 'value' => '1', 'compare' => '!=' ),
		);
		if ( ! empty( $f['author_type'] ) && in_array( $f['author_type'], array( self::AUTHOR_CANDIDATE, self::AUTHOR_COMPANY ), true ) ) {
			$meta[] = array( 'key' => self::META_AUTHOR_TYPE, 'value' => (string) $f['author_type'] );
		}
		if ( ! empty( $f['company_id'] ) ) {
			$meta[] = array( 'key' => self::META_COMPANY_ID, 'value' => (int) $f['company_id'] );
		}
		$meta['relation'] = 'AND';

		$tax = array();
		if ( ! empty( $f['category'] ) ) {
			$tax[] = array( 'taxonomy' => SkillPostType::TAX_CAT, 'field' => 'slug', 'terms' => array( sanitize_title( (string) $f['category'] ) ) );
		}
		if ( ! empty( $f['tag'] ) ) {
			$tax[] = array( 'taxonomy' => SkillPostType::TAX_TAG, 'field' => 'slug', 'terms' => array( sanitize_title( (string) $f['tag'] ) ) );
		}

		$args = array(
			'post_type'      => SkillPostType::TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'meta_query'     => $meta,
			'orderby'        => 'date',
			'order'          => ( 'oldest' === ( $f['sort'] ?? 'recent' ) ) ? 'ASC' : 'DESC',
		);
		if ( $tax ) {
			$tax['relation'] = 'AND';
			$args['tax_query'] = $tax;
		}
		if ( ! empty( $f['q'] ) ) {
			$args['s'] = sanitize_text_field( (string) $f['q'] );
		}
		$q = new \WP_Query( $args );
		return array( 'items' => array_map( array( $this, 'assemble' ), $q->posts ), 'total' => (int) $q->found_posts );
	}

	/** @return array<int,array<string,mixed>> Tous statuts, propriété d'un user. */
	public function list_for_user( int $user_id ): array {
		$q = new \WP_Query( array(
			'post_type'      => SkillPostType::TYPE,
			'post_status'    => 'publish',
			'author'         => $user_id,
			'posts_per_page' => 100,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );
		return array_map( array( $this, 'assemble' ), $q->posts );
	}

	/** @return int[] IDs des savoir-faire PERSONNELS (author_type candidate) d'un user. */
	public function personal_ids_of_user( int $user_id ): array {
		return array_map( 'intval', get_posts( array(
			'post_type'      => SkillPostType::TYPE,
			'post_status'    => 'publish',
			'author'         => $user_id,
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_key'       => self::META_AUTHOR_TYPE,
			'meta_value'     => self::AUTHOR_CANDIDATE,
		) ) );
	}

	/** @return int[] IDs des savoir-faire d'une entreprise. */
	public function ids_of_company( int $company_id ): array {
		return array_map( 'intval', get_posts( array(
			'post_type'      => SkillPostType::TYPE,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_key'       => self::META_COMPANY_ID,
			'meta_value'     => $company_id,
		) ) );
	}

	private function category_of( int $id ): string {
		$terms = wp_get_object_terms( $id, SkillPostType::TAX_CAT, array( 'fields' => 'slugs' ) );
		return ( ! is_wp_error( $terms ) && $terms ) ? (string) $terms[0] : '';
	}

	/** @return string[] */
	private function tags_of( int $id ): array {
		$terms = wp_get_object_terms( $id, SkillPostType::TAX_TAG, array( 'fields' => 'names' ) );
		return ( ! is_wp_error( $terms ) && $terms ) ? array_map( 'strval', $terms ) : array();
	}

	/** @return array<string,mixed> */
	private function assemble( \WP_Post $p ): array {
		$id      = (int) $p->ID;
		$logo_id = (int) get_post_thumbnail_id( $id );
		return array(
			'id'            => $id,
			'uuid'          => (string) get_post_meta( $id, self::META_UUID, true ),
			'slug'          => (string) $p->post_name,
			'status'        => (string) ( get_post_meta( $id, self::META_STATUS, true ) ?: SkillStateMachine::DRAFT ),
			'author_type'   => (string) ( get_post_meta( $id, self::META_AUTHOR_TYPE, true ) ?: self::AUTHOR_CANDIDATE ),
			'author_id'     => (int) $p->post_author,
			'company_id'    => (int) get_post_meta( $id, self::META_COMPANY_ID, true ),
			'company_uuid'  => (string) get_post_meta( $id, self::META_COMPANY_UUID, true ),
			'title'         => (string) $p->post_title,
			'summary'       => (string) get_post_meta( $id, self::META_SUMMARY, true ),
			'content'       => (string) $p->post_content,
			'details'       => (array) json_decode( (string) get_post_meta( $id, self::META_DETAILS, true ), true ),
			'category'      => $this->category_of( $id ),
			'tags'          => $this->tags_of( $id ),
			'image_url'     => $logo_id ? (string) wp_get_attachment_url( $logo_id ) : null,
			'gallery'       => $this->gallery_urls( $id ),
			'mod_hidden'    => '1' === (string) get_post_meta( $id, self::META_MOD_HIDDEN, true ),
			'susp_hidden'   => '1' === (string) get_post_meta( $id, self::META_SUSP_HIDDEN, true ),
			'revision'      => (int) get_post_meta( $id, self::META_REVISION, true ),
			'created_at'    => (string) $p->post_date_gmt,
			'modified_at'   => (string) $p->post_modified_gmt,
		);
	}

	/** @return string[] */
	private function gallery_urls( int $id ): array {
		$details = (array) json_decode( (string) get_post_meta( $id, self::META_DETAILS, true ), true );
		$ids     = isset( $details['galerie'] ) && is_array( $details['galerie'] ) ? $details['galerie'] : array();
		$urls    = array();
		foreach ( $ids as $aid ) {
			$u = wp_get_attachment_url( (int) $aid );
			if ( $u ) {
				$urls[] = (string) $u;
			}
		}
		return $urls;
	}

	public function is_public_visible( array $skill ): bool {
		return SkillStateMachine::PUBLISHED === $skill['status'] && empty( $skill['mod_hidden'] ) && empty( $skill['susp_hidden'] );
	}

	private function unique_uuid(): string {
		global $wpdb;
		do {
			$uuid = wp_generate_uuid4();
			$n    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", self::META_UUID, $uuid ) );
		} while ( $n > 0 );
		return $uuid;
	}
}
