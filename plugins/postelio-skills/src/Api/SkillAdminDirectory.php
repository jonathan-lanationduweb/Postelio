<?php
/**
 * Contrat de LECTURE ADMIN des savoir-faire (consommé par postelio-admin). Compteurs par statut
 * (+ masqués modération), liste filtrée, détail. Lecture seule ; les actions (hide/unhide)
 * passent par SkillModeration.
 *
 * @package Postelio\Skills\Api
 */

namespace Postelio\Skills\Api;

use Postelio\Skills\Comments\CommentRepository;
use Postelio\Skills\Cpt\SkillPostType;
use Postelio\Skills\Skills\SkillPresenter;
use Postelio\Skills\Skills\SkillRepository;
use Postelio\Skills\Skills\SkillStateMachine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SkillAdminDirectory {

	/** @return array<string,int> */
	public static function counts(): array {
		$out = array( 'total' => 0, 'hidden' => self::count_meta( SkillRepository::META_MOD_HIDDEN, '1' ) );
		$total = 0;
		foreach ( array( SkillStateMachine::DRAFT, SkillStateMachine::PUBLISHED, SkillStateMachine::ARCHIVED ) as $s ) {
			$n         = self::count_meta( SkillRepository::META_STATUS, $s );
			$out[ $s ] = $n;
			$total    += $n;
		}
		$out['total'] = $total;
		return $out;
	}

	private static function count_meta( string $key, string $value ): int {
		$q = new \WP_Query( array( 'post_type' => SkillPostType::TYPE, 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => $key, 'meta_value' => $value ) );
		return (int) $q->found_posts;
	}

	/**
	 * @param array<string,mixed> $f  status|hidden, q, category, author_type, company_id
	 * @return array{items:array<int,array<string,mixed>>, total:int}
	 */
	public static function list( array $f, int $page, int $per_page ): array {
		$meta = array();
		if ( ! empty( $f['hidden'] ) ) {
			$meta[] = array( 'key' => SkillRepository::META_MOD_HIDDEN, 'value' => '1' );
		} elseif ( ! empty( $f['status'] ) && SkillStateMachine::is_status( (string) $f['status'] ) ) {
			$meta[] = array( 'key' => SkillRepository::META_STATUS, 'value' => (string) $f['status'] );
		}
		if ( ! empty( $f['author_type'] ) ) {
			$meta[] = array( 'key' => SkillRepository::META_AUTHOR_TYPE, 'value' => (string) $f['author_type'] );
		}
		if ( ! empty( $f['company_id'] ) ) {
			$meta[] = array( 'key' => SkillRepository::META_COMPANY_ID, 'value' => (int) $f['company_id'] );
		}
		$args = array(
			'post_type'      => SkillPostType::TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => max( 1, $page ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( $meta ) {
			$meta['relation'] = 'AND';
			$args['meta_query'] = $meta;
		}
		if ( ! empty( $f['category'] ) ) {
			$args['tax_query'] = array( array( 'taxonomy' => SkillPostType::TAX_CAT, 'field' => 'slug', 'terms' => array( sanitize_title( (string) $f['category'] ) ) ) );
		}
		if ( ! empty( $f['q'] ) ) {
			$args['s'] = sanitize_text_field( (string) $f['q'] );
		}
		$q     = new \WP_Query( $args );
		$repo  = new SkillRepository();
		$items = array();
		foreach ( $q->posts as $p ) {
			$s = $repo->get( (int) $p->ID );
			if ( null === $s ) {
				continue;
			}
			$items[] = array(
				'uuid'         => (string) $s['uuid'],
				'title'        => (string) $s['title'],
				'author_type'  => (string) $s['author_type'],
				'author_name'  => class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) ? \Postelio\Users\Api\UserDirectory::display_name( (int) $s['author_id'] ) : '',
				'company_uuid' => (string) $s['company_uuid'],
				'category'     => (string) $s['category'],
				'status'       => (string) $s['status'],
				'mod_hidden'   => ! empty( $s['mod_hidden'] ),
				'comments'     => self::comments_count( (int) $s['id'] ),
				'modified_at'  => (string) $s['modified_at'],
			);
		}
		return array( 'items' => $items, 'total' => (int) $q->found_posts );
	}

	/** @return array<string,mixed>|null */
	public static function detail( string $uuid ): ?array {
		$s = ( new SkillRepository() )->get_by_uuid( $uuid );
		if ( null === $s ) {
			return null;
		}
		$view                = SkillPresenter::author_view( $s );
		$view['comments']    = self::comments_count( (int) $s['id'] );
		$view['author_name'] = class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) ? \Postelio\Users\Api\UserDirectory::display_name( (int) $s['author_id'] ) : '';
		return $view;
	}

	private static function comments_count( int $skill_id ): int {
		global $wpdb;
		if ( ! class_exists( '\\Postelio\\Skills\\Comments\\CommentRepository' ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CommentRepository::table() . ' WHERE skill_id = %d AND status = %s', $skill_id, CommentRepository::PUBLISHED ) );
	}
}
