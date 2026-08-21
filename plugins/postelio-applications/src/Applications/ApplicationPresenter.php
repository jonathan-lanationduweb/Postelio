<?php
/**
 * Présentation des candidatures selon l'audience. N'expose jamais d'ID interne
 * (uniquement des UUID). Le candidat ne voit ni notes, ni métadonnées d'historique
 * (motif interne), ni reviewer, ni données d'autres candidats.
 *
 * @package Postelio\Applications\Applications
 */

namespace Postelio\Applications\Applications;

use Postelio\Users\Api\UserDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApplicationPresenter {

	/**
	 * Vue CANDIDAT (sa candidature). Le job affiché = le SNAPSHOT auquel il a répondu.
	 *
	 * @param array<string, mixed>            $a
	 * @param array<int, array<string,mixed>> $timeline
	 * @return array<string, mixed>
	 */
	public static function candidate_view( array $a, array $timeline ): array {
		$snap = is_array( $a['job_snapshot'] ) ? $a['job_snapshot'] : array();
		return array(
			'uuid'              => $a['public_uuid'],
			'status'            => $a['status'],
			'job'               => array(
				'uuid'     => $snap['job_uuid'] ?? $a['job_uuid'],
				'titre'    => $snap['titre'] ?? null,
				'revision' => (int) $a['job_revision'],
				'company'  => array(
					'uuid' => $snap['company_uuid'] ?? $a['company_uuid'],
					'nom'  => $snap['company_name'] ?? null,
				),
			),
			'cv_reference'      => $a['cv_reference'],
			'candidate_message' => $a['candidate_message'],
			'screening_answers' => $a['screening_answers'],
			'created_at'        => $a['created_at'],
			'updated_at'        => $a['updated_at'],
			'withdrawn_at'      => $a['withdrawn_at'],
			'timeline'          => self::timeline_public( $timeline ),
		);
	}

	/**
	 * Vue RECRUTEUR (candidature reçue par son entreprise) : + candidat, notes,
	 * métadonnées d'historique (motif interne). Toujours dans le contexte entreprise.
	 *
	 * @param array<string, mixed>            $a
	 * @param array<int, array<string,mixed>> $timeline
	 * @param array<int, array<string,mixed>> $notes
	 * @return array<string, mixed>
	 */
	public static function recruiter_view( array $a, array $timeline, array $notes ): array {
		$snap = is_array( $a['job_snapshot'] ) ? $a['job_snapshot'] : array();
		$cid  = (int) $a['candidate_user_id'];
		return array(
			'uuid'              => $a['public_uuid'],
			'status'            => $a['status'],
			'sort_order'        => $a['sort_order'],
			'candidate'         => array(
				'profile_uuid' => UserDirectory::candidate_profile_uuid( $cid ),
				'display_name' => UserDirectory::display_name( $cid ),
			),
			'job'               => array(
				'uuid'     => $snap['job_uuid'] ?? $a['job_uuid'],
				'titre'    => $snap['titre'] ?? null,
				'revision' => (int) $a['job_revision'],
			),
			'company'           => array( 'uuid' => $a['company_uuid'] ),
			'cv_reference'      => $a['cv_reference'],
			'candidate_message' => $a['candidate_message'],
			'screening_answers' => $a['screening_answers'],
			'created_at'        => $a['created_at'],
			'updated_at'        => $a['updated_at'],
			'withdrawn_at'      => $a['withdrawn_at'],
			'timeline'          => self::timeline_full( $timeline ),
			'notes'             => $notes,
		);
	}

	/** Ligne de liste (candidat). @param array<string,mixed> $a @return array<string,mixed> */
	public static function candidate_row( array $a ): array {
		$snap = is_array( $a['job_snapshot'] ) ? $a['job_snapshot'] : array();
		return array(
			'uuid'       => $a['public_uuid'],
			'status'     => $a['status'],
			'job'        => array( 'uuid' => $snap['job_uuid'] ?? $a['job_uuid'], 'titre' => $snap['titre'] ?? null, 'company' => array( 'nom' => $snap['company_name'] ?? null ) ),
			'created_at' => $a['created_at'],
		);
	}

	/** Ligne de liste (recruteur / Kanban). @param array<string,mixed> $a @return array<string,mixed> */
	public static function recruiter_row( array $a ): array {
		$cid  = (int) $a['candidate_user_id'];
		$snap = is_array( $a['job_snapshot'] ) ? $a['job_snapshot'] : array();
		return array(
			'uuid'       => $a['public_uuid'],
			'status'     => $a['status'],
			'sort_order' => $a['sort_order'],
			'candidate'  => array( 'profile_uuid' => UserDirectory::candidate_profile_uuid( $cid ), 'display_name' => UserDirectory::display_name( $cid ) ),
			'job'        => array( 'uuid' => $snap['job_uuid'] ?? $a['job_uuid'], 'titre' => $snap['titre'] ?? null ),
			'created_at' => $a['created_at'],
		);
	}

	/** Timeline candidat : action + statuts + date, SANS métadonnée interne. */
	private static function timeline_public( array $timeline ): array {
		return array_map(
			static fn( array $e ) => array(
				'action'      => $e['action'],
				'from_status' => $e['from_status'],
				'to_status'   => $e['to_status'],
				'created_at'  => $e['created_at'],
			),
			$timeline
		);
	}

	/** Timeline recruteur : inclut la métadonnée (motif interne, etc.). */
	private static function timeline_full( array $timeline ): array {
		return $timeline;
	}
}
