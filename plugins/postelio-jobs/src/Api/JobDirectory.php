<?php
/**
 * Contrat public STABLE des offres pour les autres plugins (postelio-applications…).
 *
 * Évite toute lecture directe de `pst_status`/`pst_revision`/`pst_detail` par les
 * consommateurs. Fournit la candidatabilité et le SNAPSHOT minimal nécessaire à une
 * candidature.
 *
 * @package Postelio\Jobs\Api
 */

namespace Postelio\Jobs\Api;

use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Jobs\Jobs\JobStateMachine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobDirectory {

	private static function repo(): JobRepository {
		return new JobRepository();
	}

	public static function id_from_uuid( string $uuid ): int {
		$j = self::repo()->get_by_uuid( $uuid );
		return $j ? (int) $j['id'] : 0;
	}

	public static function exists( int $job_id ): bool {
		return self::repo()->exists( $job_id );
	}

	/**
	 * Une offre (par UUID public) est-elle une offre EXTERNE (Lot 10) ? Le natif est résolu
	 * par le CPT ; sinon on interroge postelio-job-sources via un filtre (pas de dépendance).
	 */
	public static function is_external( string $job_uuid ): bool {
		if ( self::id_from_uuid( $job_uuid ) > 0 ) {
			return false; // offre native
		}
		$ext = apply_filters( 'postelio/jobs/resolve_external', null, $job_uuid );
		return is_array( $ext ) && ! empty( $ext['found'] );
	}

	/**
	 * Descripteur d'une offre externe (source/application_mode/état/public_view), ou null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function external( string $job_uuid ): ?array {
		$ext = apply_filters( 'postelio/jobs/resolve_external', null, $job_uuid );
		return ( is_array( $ext ) && ! empty( $ext['found'] ) ) ? $ext : null;
	}

	/** Mode de candidature : `postelio` (natif), `external_redirect` (externe), ou null. */
	public static function application_mode( string $job_uuid ): ?string {
		if ( self::id_from_uuid( $job_uuid ) > 0 ) {
			return 'postelio';
		}
		$ext = self::external( $job_uuid );
		return $ext ? (string) $ext['application_mode'] : null;
	}

	/** UUID public depuis l'ID interne (pour construire une action de notification). */
	public static function uuid_of( int $job_id ): ?string {
		$j = self::repo()->get( $job_id );
		return $j ? (string) $j['uuid'] : null;
	}

	/** Titre de l'offre (pour le libellé d'une notification), ou null. */
	public static function title_of( int $job_id ): ?string {
		$j = self::repo()->get( $job_id );
		return $j ? (string) $j['titre'] : null;
	}

	public static function status( int $job_id ): ?string {
		$j = self::repo()->get( $job_id );
		return $j ? (string) $j['status'] : null;
	}

	/** Entreprise propriétaire de l'offre (0 si inconnue). Contrat pour postelio-billing. */
	public static function company_id_of( int $job_id ): int {
		$j = self::repo()->get( $job_id );
		return $j ? (int) ( $j['company']['id'] ?? 0 ) : 0;
	}

	/**
	 * Auteur (créateur) de l'offre — recruteur qui l'a publiée. Sert au ciblage des
	 * notifications (D3 Lot 09). Retourne null si l'offre n'existe pas / auteur absent.
	 */
	public static function created_by( int $job_id ): ?int {
		if ( ! self::repo()->exists( $job_id ) ) {
			return null;
		}
		$author = (int) get_post_field( 'post_author', $job_id );
		return $author > 0 ? $author : null;
	}

	/**
	 * Une offre accepte-t-elle des candidatures ? V1 : uniquement `published`/`expiring`
	 * (pas `draft`, `expired`, `filled`, `archived`, `suspended`).
	 */
	public static function is_candidateable( int $job_id ): bool {
		$s = self::status( $job_id );
		return null !== $s && JobStateMachine::is_public( $s );
	}

	/**
	 * Snapshot minimal figé au moment d'une candidature.
	 *
	 * @return array{job_id:int, job_uuid:string, revision:int, titre:string, company_id:int, company_uuid:string, company_name:string, questions_preselection:array}|null
	 */
	public static function application_snapshot( int $job_id ): ?array {
		$j = self::repo()->get( $job_id );
		if ( null === $j ) {
			return null;
		}
		$detail = is_array( $j['detail'] ) ? $j['detail'] : array();
		return array(
			'job_id'                 => (int) $j['id'],
			'job_uuid'               => (string) $j['uuid'],
			'revision'               => (int) $j['revision'],
			'titre'                  => (string) $j['titre'],
			'company_id'             => (int) $j['company']['id'],
			'company_uuid'           => (string) $j['company']['uuid'],
			'company_name'           => (string) $j['company']['nom'],
			'questions_preselection' => isset( $detail['questions_preselection'] ) && is_array( $detail['questions_preselection'] )
				? $detail['questions_preselection']
				: array(),
		);
	}
}
