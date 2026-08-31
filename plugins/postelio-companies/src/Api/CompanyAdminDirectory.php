<?php
/**
 * Contrat de LECTURE ADMIN des entreprises (consommé par postelio-admin). Compteurs par statut
 * de vérification + liste filtrée, sans lecture directe du CPT/meta par le back-office. Lecture
 * seule ; les actions (vérifier/suspendre…) passent par VerificationService/CompanyModeration.
 *
 * @package Postelio\Companies\Api
 */

namespace Postelio\Companies\Api;

use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Companies\Cpt\CompanyPostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompanyAdminDirectory {

	private const STATUSES = array( 'unverified', 'pending', 'manual_review', 'verified', 'rejected', 'suspended' );

	/** @return array<string,int> */
	public static function counts(): array {
		$out   = array( 'total' => 0 );
		$total = 0;
		foreach ( self::STATUSES as $s ) {
			$n         = self::count_by_status( $s );
			$out[ $s ] = $n;
			$total    += $n;
		}
		$out['total'] = $total;
		return $out;
	}

	private static function count_by_status( string $status ): int {
		$q = new \WP_Query( array(
			'post_type'      => CompanyPostType::TYPE,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'meta_key'       => CompanyRepository::META_STATUS,
			'meta_value'     => $status,
		) );
		return (int) $q->found_posts;
	}

	/**
	 * @param array<string,mixed> $filters  status, q
	 * @return array{items:array<int,array<string,mixed>>, total:int}
	 */
	public static function list( array $filters, int $page, int $per_page ): array {
		$args = array(
			'post_type'      => CompanyPostType::TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => max( 1, $page ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $filters['status'] ) && in_array( (string) $filters['status'], self::STATUSES, true ) ) {
			$args['meta_key']   = CompanyRepository::META_STATUS;
			$args['meta_value'] = (string) $filters['status'];
		}
		if ( ! empty( $filters['q'] ) ) {
			$args['s'] = sanitize_text_field( (string) $filters['q'] );
		}
		$q     = new \WP_Query( $args );
		$repo  = new CompanyRepository();
		$items = array();
		foreach ( $q->posts as $p ) {
			$c = $repo->get( (int) $p->ID );
			if ( null === $c ) {
				continue;
			}
			$legal = is_array( $c['legal_verified'] ?? null ) && $c['legal_verified'] ? $c['legal_verified'] : ( is_array( $c['legal_declared'] ?? null ) ? $c['legal_declared'] : array() );
			$items[] = array(
				'uuid'    => (string) $c['uuid'],
				'nom'     => (string) $c['nom'],
				'status'  => (string) ( $c['verification']['status'] ?? 'unverified' ),
				'siren'   => (string) ( $legal['siren'] ?? '' ),
				'ville'   => (string) ( $legal['ville_siege'] ?? '' ),
				'owner_id' => (int) ( $c['author_id'] ?? 0 ),
			);
		}
		return array( 'items' => $items, 'total' => (int) $q->found_posts );
	}

	/**
	 * Détail admin d'une entreprise : identité + éditorial + légal + vérification + membres.
	 * Retourne null si inconnue.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function detail( string $uuid ): ?array {
		$id = CompanyDirectory::id_from_uuid( $uuid );
		if ( $id <= 0 ) {
			return null;
		}
		$c = ( new CompanyRepository() )->get( $id );
		if ( null === $c ) {
			return null;
		}
		$members = array();
		foreach ( ( new \Postelio\Companies\Members\MembershipRepository() )->members_of( $id ) as $m ) {
			$uid       = (int) ( is_array( $m ) ? ( $m['user_id'] ?? $m['ID'] ?? 0 ) : $m );
			$members[] = array(
				'user_id' => $uid,
				'role'    => (string) ( is_array( $m ) ? ( $m['role'] ?? '' ) : '' ),
				'name'    => class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) ? \Postelio\Users\Api\UserDirectory::display_name( $uid ) : '',
			);
		}
		$c['members']    = $members;
		$c['company_id'] = $id;
		return $c;
	}
}
