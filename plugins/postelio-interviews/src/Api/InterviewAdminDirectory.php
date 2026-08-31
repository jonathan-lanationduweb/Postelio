<?php
/**
 * Contrat de LECTURE ADMIN des entretiens (consommé par postelio-admin). Compteurs par statut,
 * liste paginée SANS coordonnées sensibles (adresse / téléphone / lien visio JAMAIS en liste), et
 * détail avec chronologie. Les coordonnées ne sont renvoyées par detail() que si l'appelant est
 * explicitement autorisé ($include_coordinates), charge à la page d'exiger la capacité. Lecture seule.
 *
 * @package Postelio\Interviews\Api
 */

namespace Postelio\Interviews\Api;

use Postelio\Interviews\Interviews\InterviewRepository;
use Postelio\Interviews\Interviews\InterviewHistoryRepository;
use Postelio\Interviews\Interviews\InterviewStateMachine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class InterviewAdminDirectory {

	private const STATUSES = array(
		InterviewStateMachine::PROPOSED, InterviewStateMachine::CONFIRMED, InterviewStateMachine::RESCHEDULE_REQUESTED,
		InterviewStateMachine::DECLINED, InterviewStateMachine::CANCELLED, InterviewStateMachine::COMPLETED,
	);

	/** @return array<string,int> */
	public static function counts(): array {
		global $wpdb;
		$table = InterviewRepository::table();
		$out   = array( 'total' => 0 );
		$total = 0;
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ) as $r ) {
			$out[ (string) $r['status'] ] = (int) $r['n'];
			$total += (int) $r['n'];
		}
		$out['total'] = $total;
		return $out;
	}

	/**
	 * @param array<string,mixed> $filters status, company_id, type
	 * @return array{items:array<int,array<string,mixed>>, total:int}
	 */
	public static function list( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$table = InterviewRepository::table();
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) && in_array( (string) $filters['status'], self::STATUSES, true ) ) {
			$where[] = 'status = %s';
			$args[]  = (string) $filters['status'];
		}
		if ( ! empty( $filters['company_id'] ) ) {
			$where[] = 'company_id = %d';
			$args[]  = (int) $filters['company_id'];
		}
		if ( ! empty( $filters['type'] ) ) {
			$where[] = 'type = %s';
			$args[]  = (string) $filters['type'];
		}
		$clause = implode( ' AND ', $where );
		$total  = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$clause}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$clause}" ) );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		// SELECT explicite : jamais location_data / video_data / phone_data en liste.
		$sql  = "SELECT public_uuid, application_uuid, job_uuid, candidate_user_id, company_id, type, status, scheduled_at, timezone, created_at FROM {$table} WHERE {$clause} ORDER BY scheduled_at DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::row_view( $row );
		}
		return array( 'items' => $items, 'total' => $total );
	}

	/**
	 * @param bool $include_coordinates Si vrai, ajoute les coordonnées sensibles (la page DOIT avoir
	 *                                  vérifié la capacité avant de passer true).
	 * @return array<string,mixed>|null
	 */
	public static function detail( string $uuid, bool $include_coordinates = false ): ?array {
		$row = ( new InterviewRepository() )->get_by_uuid( $uuid );
		if ( null === $row ) {
			return null;
		}
		$view                     = self::row_view( $row );
		$view['instructions']     = (string) ( $row['instructions'] ?? '' );
		$view['proposed_at']      = (string) ( $row['proposed_scheduled_at'] ?? '' );
		$view['proposed_message'] = (string) ( $row['proposed_message'] ?? '' );
		$view['cancelled_at']     = (string) ( $row['cancelled_at'] ?? '' );
		$view['updated_at']       = (string) ( $row['updated_at'] ?? '' );
		$view['history']          = ( new InterviewHistoryRepository() )->list_for_interview( (int) $row['id'] );
		$view['has_coordinates']  = self::has_coords( $row );

		if ( $include_coordinates ) {
			$view['coordinates'] = self::coords( $row );
		}
		return $view;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function row_view( array $row ): array {
		$candidate = class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) ? \Postelio\Users\Api\UserDirectory::display_name( (int) $row['candidate_user_id'] ) : '';
		$company   = class_exists( '\\Postelio\\Companies\\Api\\CompanyDirectory' ) ? (string) \Postelio\Companies\Api\CompanyDirectory::name_of( (int) $row['company_id'] ) : '';
		$job_title = '';
		if ( '' !== (string) ( $row['job_uuid'] ?? '' ) && class_exists( '\\Postelio\\Jobs\\Api\\JobDirectory' ) ) {
			$jid       = \Postelio\Jobs\Api\JobDirectory::id_from_uuid( (string) $row['job_uuid'] );
			$job_title = $jid > 0 ? (string) \Postelio\Jobs\Api\JobDirectory::title_of( $jid ) : '';
		}
		return array(
			'uuid'             => (string) $row['public_uuid'],
			'application_uuid' => (string) ( $row['application_uuid'] ?? '' ),
			'candidate'        => '' !== $candidate ? $candidate : '—',
			'company'          => '' !== $company ? $company : '—',
			'job_title'        => '' !== $job_title ? $job_title : '—',
			'type'             => (string) $row['type'],
			'status'           => (string) $row['status'],
			'scheduled_at'     => (string) ( $row['scheduled_at'] ?? '' ),
			'timezone'         => (string) ( $row['timezone'] ?? 'UTC' ),
			'created_at'       => (string) ( $row['created_at'] ?? '' ),
		);
	}

	/** @param array<string,mixed> $row */
	private static function has_coords( array $row ): bool {
		foreach ( array( 'location_data', 'video_data', 'phone_data' ) as $k ) {
			$raw = (string) ( $row[ $k ] ?? '' );
			if ( '' !== $raw && '[]' !== $raw && 'null' !== $raw ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function coords( array $row ): array {
		$decode = static function ( $raw ): array {
			$d = ( is_string( $raw ) && '' !== $raw ) ? json_decode( $raw, true ) : null;
			return is_array( $d ) ? $d : array();
		};
		return array(
			'location' => $decode( $row['location_data'] ?? '' ),
			'video'    => $decode( $row['video_data'] ?? '' ),
			'phone'    => $decode( $row['phone_data'] ?? '' ),
		);
	}
}
