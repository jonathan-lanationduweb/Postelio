<?php
/**
 * Contrat de LECTURE ADMIN des candidatures (consommé par postelio-backoffice). Compteurs par statut,
 * liste paginée avec libellés synthétiques (candidat/offre/entreprise résolus une fois par ligne
 * — max 50/page, pas de N+1 non borné) et détail. Lecture seule. N'expose JAMAIS les NOTES
 * RECRUTEUR (privées) ni d'ID SQL.
 *
 * @package Postelio\Applications\Api
 */

namespace Postelio\Applications\Api;

use Postelio\Applications\Applications\ApplicationRepository;
use Postelio\Applications\Applications\ApplicationStateMachine;
use Postelio\Applications\Applications\HistoryRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApplicationAdminDirectory {

	private const STATUSES = array(
		ApplicationStateMachine::NEW, ApplicationStateMachine::REVIEW, ApplicationStateMachine::SHORTLISTED,
		ApplicationStateMachine::INTERVIEW, ApplicationStateMachine::SELECTED, ApplicationStateMachine::REJECTED,
		ApplicationStateMachine::WITHDRAWN,
	);

	/** @return array<string,int> */
	public static function counts(): array {
		global $wpdb;
		$table = ApplicationRepository::table();
		$out   = array( 'total' => 0 );
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$total = 0;
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['status'] ] = (int) $r['n'];
			$total += (int) $r['n'];
		}
		$out['total'] = $total;
		return $out;
	}

	/**
	 * @param array<string,mixed> $filters status, company_id, job_uuid
	 * @return array{items:array<int,array<string,mixed>>, total:int}
	 */
	public static function list( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$table = ApplicationRepository::table();
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
		if ( ! empty( $filters['job_uuid'] ) ) {
			$where[] = 'job_uuid = %s';
			$args[]  = (string) $filters['job_uuid'];
		}
		$clause = implode( ' AND ', $where );
		$total  = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$clause}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$clause}" ) );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$clause} ORDER BY created_at DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::row_view( $row );
		}
		return array( 'items' => $items, 'total' => $total );
	}

	/** @return array<string,mixed>|null Détail (sans notes recruteur, qui sont privées au recruteur). */
	public static function detail( string $uuid ): ?array {
		$row = ( new ApplicationRepository() )->get_by_uuid( $uuid );
		if ( null === $row ) {
			return null;
		}
		$view                 = self::row_view( $row );
		$view['job_snapshot'] = is_array( $row['job_snapshot'] ?? null ) ? $row['job_snapshot'] : array();
		$view['answers']      = is_array( $row['screening_answers'] ?? null ) ? $row['screening_answers'] : array();
		$view['message']      = (string) ( $row['candidate_message'] ?? '' );
		$view['source']       = (string) ( $row['source'] ?? '' );
		$view['cv_reference'] = (string) ( $row['cv_reference'] ?? '' );
		$view['job_revision'] = (int) ( $row['job_revision'] ?? 0 );
		$view['withdrawn_at'] = (string) ( $row['withdrawn_at'] ?? '' );
		$view['history']      = ( new HistoryRepository() )->timeline( (int) $row['id'] );
		return $view;
	}

	/**
	 * Sous-ensemble des références CV réellement utilisées par une candidature (batch, pas de N+1).
	 * Consommé par le back-office pour la colonne « référencé par candidature ». Lecture propre table.
	 *
	 * @param string[] $cv_uuids
	 * @return array<string,bool> map uuid => true (seulement les référencés)
	 */
	public static function referenced_cv( array $cv_uuids ): array {
		$cv_uuids = array_values( array_unique( array_filter( array_map( 'strval', $cv_uuids ) ) ) );
		if ( empty( $cv_uuids ) ) {
			return array();
		}
		global $wpdb;
		$table = ApplicationRepository::table();
		$in    = implode( ',', array_fill( 0, count( $cv_uuids ), '%s' ) );
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT cv_reference FROM {$table} WHERE cv_reference IN ({$in})", $cv_uuids ) );
		$out   = array();
		foreach ( (array) $found as $u ) {
			$out[ (string) $u ] = true;
		}
		return $out;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function row_view( array $row ): array {
		$candidate = class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) ? \Postelio\Users\Api\UserDirectory::display_name( (int) $row['candidate_user_id'] ) : '';
		$job_title = '';
		if ( class_exists( '\\Postelio\\Jobs\\Api\\JobDirectory' ) ) {
			$jid = \Postelio\Jobs\Api\JobDirectory::id_from_uuid( (string) $row['job_uuid'] );
			$job_title = $jid > 0 ? (string) \Postelio\Jobs\Api\JobDirectory::title_of( $jid ) : '';
		}
		$company = class_exists( '\\Postelio\\Companies\\Api\\CompanyDirectory' ) ? (string) \Postelio\Companies\Api\CompanyDirectory::name_of( (int) $row['company_id'] ) : '';
		$has_iv  = class_exists( '\\Postelio\\Interviews\\Api\\InterviewDirectory' ) && \Postelio\Interviews\Api\InterviewDirectory::has_active_for_application( (int) $row['id'] );
		return array(
			'uuid'          => (string) $row['public_uuid'],
			'candidate'     => '' !== $candidate ? $candidate : '—',
			'job_title'     => '' !== $job_title ? $job_title : '—',
			'company'       => '' !== $company ? $company : '—',
			'status'        => (string) $row['status'],
			'created_at'    => (string) $row['created_at'],
			'has_interview' => $has_iv,
		);
	}
}
