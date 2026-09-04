<?php
/**
 * Contrat de LECTURE ADMIN des offres (consommé par postelio-backoffice). Fournit des compteurs et
 * une liste filtrée par statut métier, sans que le back-office ne lise directement le CPT/meta.
 * Lecture seule ; aucune écriture (les actions passent par JobService/JobModeration).
 *
 * @package Postelio\Jobs\Api
 */

namespace Postelio\Jobs\Api;

use Postelio\Jobs\Cpt\JobPostType;
use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Jobs\Jobs\JobStateMachine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobAdminDirectory {

	/** @return array<string,int> Compteurs natifs par statut métier + total. */
	public static function counts(): array {
		$statuses = array(
			JobStateMachine::DRAFT, JobStateMachine::PUBLISHED, JobStateMachine::EXPIRING,
			JobStateMachine::EXPIRED, JobStateMachine::FILLED, JobStateMachine::ARCHIVED, JobStateMachine::SUSPENDED,
		);
		$out   = array( 'total' => 0 );
		$total = 0;
		foreach ( $statuses as $s ) {
			$n          = self::count_by_status( $s );
			$out[ $s ]  = $n;
			$total     += $n;
		}
		$out['total'] = $total;
		return $out;
	}

	private static function count_by_status( string $status ): int {
		$q = new \WP_Query( array(
			'post_type'      => JobPostType::TYPE,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => false,
			'meta_key'       => JobRepository::META_STATUS,
			'meta_value'     => $status,
		) );
		return (int) $q->found_posts;
	}

	/**
	 * Liste admin paginée. Filtres : status (métier), q (titre).
	 *
	 * @param array<string,mixed> $filters
	 * @return array{items:array<int,array<string,mixed>>, total:int}
	 */
	public static function list( array $filters, int $page, int $per_page ): array {
		$args = array(
			'post_type'      => JobPostType::TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => max( 1, $page ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $filters['status'] ) && JobStateMachine::is_status( (string) $filters['status'] ) ) {
			$args['meta_key']   = JobRepository::META_STATUS;
			$args['meta_value'] = (string) $filters['status'];
		}
		if ( ! empty( $filters['q'] ) ) {
			$args['s'] = sanitize_text_field( (string) $filters['q'] );
		}
		$q     = new \WP_Query( $args );
		$repo  = new JobRepository();
		$items = array();
		foreach ( $q->posts as $p ) {
			$job = $repo->get( (int) $p->ID );
			if ( null === $job ) {
				continue;
			}
			$items[] = array(
				'uuid'            => (string) $job['uuid'],
				'title'           => (string) $job['titre'],
				'company'         => array( 'uuid' => (string) ( $job['company']['uuid'] ?? '' ), 'nom' => (string) ( $job['company']['nom'] ?? '' ) ),
				'status'          => (string) $job['status'],
				'contrat'         => (string) ( $job['contrat'] ?? '' ),
				'ville'           => (string) ( $job['ville'] ?? '' ),
				'source'          => 'postelio',
				'date_publication' => (string) ( $job['date_publication'] ?? '' ),
				'date_expiration' => (string) ( $job['date_expiration'] ?? '' ),
			);
		}
		return array( 'items' => $items, 'total' => (int) $q->found_posts );
	}

	/**
	 * Détail admin d'une offre native (tous champs, contexte admin). Retourne null si inconnue.
	 * Pour une offre EXTERNE, l'appelant utilisera JobDirectory::external($uuid).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function detail( string $uuid ): ?array {
		return ( new JobRepository() )->get_by_uuid( $uuid );
	}
}
