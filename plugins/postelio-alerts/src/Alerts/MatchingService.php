<?php
/**
 * Moteur d'alertes : exécute UNE recherche sauvegardée et produit AU PLUS un digest.
 *
 * Étapes (§21) : filtres validés → `published_after` (curseur) → recherche via le CONTRAT Jobs
 * (natif + externe, une seule logique de matching, aucun SQL direct) → pagination jusqu'à
 * épuisement OU limite de sécurité explicite (anomalie loguée, jamais silencieuse) → réservation
 * de chaque delivery (contrainte UNIQUE) → seules les NOUVELLES réservations sont retenues →
 * digest → événement `job_alert.matches_found` → deliveries marquées → curseur/horodatages mis à
 * jour. AUCUN e-mail direct : l'événement est consommé par Notifications.
 *
 * La déduplication est garantie par la table de deliveries (UNIQUE), pas par le curseur : ce
 * dernier n'est qu'une optimisation de fenêtre (borne la requête `published_after`).
 *
 * @package Postelio\Alerts\Alerts
 */

namespace Postelio\Alerts\Alerts;

use Postelio\Alerts\Searches\SavedSearchRepository;
use Postelio\Alerts\Time\ParisSchedule;
use Postelio\Core\Events;
use Postelio\Core\Log\Logger;
use Postelio\Jobs\Api\JobSearchDirectory;
use Postelio\Jobs\Search\FilterValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MatchingService {

	private SavedSearchRepository $searches;
	private DeliveryRepository $deliveries;
	private Events $events;

	public function __construct( SavedSearchRepository $searches, DeliveryRepository $deliveries, Events $events ) {
		$this->searches   = $searches;
		$this->deliveries = $deliveries;
		$this->events     = $events;
	}

	private function per_page(): int {
		return max( 1, (int) apply_filters( 'postelio/alerts/match_per_page', 50 ) );
	}
	/** Limite de sécurité : nombre max de pages parcourues par exécution. */
	private function max_pages(): int {
		return max( 1, (int) apply_filters( 'postelio/alerts/match_max_pages', 40 ) );
	}
	/** Nombre d'offres dans l'échantillon du digest. */
	private function sample_size(): int {
		return max( 1, (int) apply_filters( 'postelio/alerts/digest_sample', 5 ) );
	}

	/**
	 * Exécute une recherche sauvegardée. $reason ∈ {'cron','run_now'}.
	 *
	 * @param array<string, mixed> $search Ligne complète de la recherche.
	 * @return array{matched:int, digest:bool} Nombre de NOUVELLES offres et si un digest a été émis.
	 */
	public function run( array $search, string $reason = 'cron' ): array {
		$id             = (int) $search['id'];
		$candidate      = (int) $search['candidate_user_id'];
		$frequency      = (string) $search['alert_frequency'];
		$timezone       = (string) ( $search['timezone'] ?: ParisSchedule::TIMEZONE );
		$now_utc        = current_time( 'mysql', true );
		$now_ts         = time();

		$filters = FilterValidator::validate( $this->decode_filters( $search ), false );
		// Curseur : premier passage = à partir de la création (pas tout le back-catalogue).
		$cursor = (string) ( $search['cursor_ts'] ?? '' );
		if ( '' === $cursor ) {
			$cursor = (string) ( $search['created_at'] ?? $now_utc );
		}
		$filters['published_after'] = $cursor;

		$candidates = $this->collect( $filters, $id );

		$new = array();
		foreach ( $candidates as $c ) {
			if ( $this->deliveries->reserve( $id, $c['source'], $c['reference'] ) ) {
				$new[] = $c;
			}
		}

		$digest_sent = false;
		if ( ! empty( $new ) ) {
			$this->emit_digest( $search, $new );
			foreach ( $new as $c ) {
				$this->deliveries->mark_sent( $id, $c['source'], $c['reference'] );
			}
			$digest_sent = true;
		}

		// Mise à jour de l'état d'exécution.
		$next_run_at = ( 'cron' === $reason )
			? ParisSchedule::next_run( $frequency, $now_ts, $timezone )
			: ( $search['next_run_at'] ?? null ); // run-now ne modifie pas la planification
		$this->searches->update_run_state( $id, $now_utc, $now_utc, $next_run_at );

		return array( 'matched' => count( $new ), 'digest' => $digest_sent );
	}

	/**
	 * Prévisualisation : matches ACTUELS (sans curseur, sans réservation, sans notification).
	 * Renvoie un total (borné à la fenêtre de sécurité) + un échantillon.
	 *
	 * @param array<string, mixed> $filters Déjà validés.
	 * @return array{count:int, sample: array<int, array<string,mixed>>, total_is_exact:bool}
	 */
	public function preview( array $filters ): array {
		$page           = 1;
		$per            = $this->per_page();
		$first          = JobSearchDirectory::search( $filters, $page, $per );
		$sample         = array();
		foreach ( array_slice( (array) $first['items'], 0, $this->sample_size() ) as $it ) {
			$sample[] = $this->card( $it );
		}
		return array(
			'count'          => (int) $first['total'],
			'sample'         => $sample,
			'total_is_exact' => (bool) $first['total_is_exact'],
		);
	}

	/**
	 * Parcourt les pages jusqu'à épuisement ou limite de sécurité. En cas de plafond atteint,
	 * loggue/émet une anomalie (jamais d'avance silencieuse en perdant des offres, §10).
	 *
	 * @param array<string, mixed> $filters
	 * @return array<int, array{source:string, reference:string, row:array<string,mixed>}>
	 */
	private function collect( array $filters, int $search_id ): array {
		$per      = $this->per_page();
		$max      = $this->max_pages();
		$out      = array();
		$page     = 1;
		do {
			$res   = JobSearchDirectory::search( $filters, $page, $per );
			$items = (array) $res['items'];
			foreach ( $items as $it ) {
				$out[] = array(
					'source'    => self::source_of( $it ),
					'reference' => (string) ( $it['uuid'] ?? '' ),
					'row'       => $it,
				);
			}
			$exhausted = count( $items ) < $per;
			++$page;
			if ( ! $exhausted && $page > $max ) {
				Logger::warning( 'Alerte : limite de sécurité de pagination atteinte', array(
					'saved_search_id' => $search_id,
					'pages'           => $max,
					'per_page'        => $per,
				) );
				$this->events->emit( 'job_alert.run_failed', array(
					'saved_search_id' => $search_id,
					'reason'          => 'result_cap',
					'pages'           => $max,
					'resource_type'   => 'saved_search',
					'resource_id'     => (string) $search_id,
				) );
				break;
			}
		} while ( ! $exhausted );

		// Filtre les références vides (robustesse) et déduplique dans le cycle.
		$seen  = array();
		$clean = array();
		foreach ( $out as $c ) {
			if ( '' === $c['reference'] ) {
				continue;
			}
			$k = $c['source'] . '|' . $c['reference'];
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$clean[]    = $c;
		}
		return $clean;
	}

	/**
	 * Émet le digest (UNE notification par cycle). Payload minimal, sans donnée sensible.
	 *
	 * @param array<string, mixed> $search
	 * @param array<int, array{source:string, reference:string, row:array<string,mixed>}> $new
	 */
	private function emit_digest( array $search, array $new ): void {
		$sample = array();
		foreach ( array_slice( $new, 0, $this->sample_size() ) as $c ) {
			$sample[] = $this->card( $c['row'] );
		}
		$this->events->emit( 'job_alert.matches_found', array(
			'candidate_user_id' => (int) $search['candidate_user_id'],
			'saved_search_uuid' => (string) $search['public_uuid'],
			'saved_search_name' => (string) $search['name'],
			'match_count'       => count( $new ),
			'sample'            => $sample,
			'resource_type'     => 'saved_search',
			'resource_id'       => (string) $search['id'],
		) );
	}

	/**
	 * Réduit un item de recherche à une carte publique minimale (digest/preview).
	 *
	 * @param array<string, mixed> $it
	 * @return array{job_uuid:string, titre:string, company:string, ville:?string, source:string}
	 */
	private function card( array $it ): array {
		$company = '';
		if ( isset( $it['company'] ) && is_array( $it['company'] ) ) {
			$company = (string) ( $it['company']['nom'] ?? '' );
		}
		return array(
			'job_uuid' => (string) ( $it['uuid'] ?? '' ),
			'titre'    => (string) ( $it['titre'] ?? '' ),
			'company'  => $company,
			'ville'    => isset( $it['ville'] ) ? ( null !== $it['ville'] ? (string) $it['ville'] : null ) : null,
			'source'   => self::source_of( $it ),
		);
	}

	/** Provenance depuis une vue publique uniforme (source.type), défaut 'native'. */
	private static function source_of( array $it ): string {
		if ( isset( $it['source'] ) && is_array( $it['source'] ) && isset( $it['source']['type'] ) ) {
			return (string) $it['source']['type'];
		}
		return (string) ( $it['source_type'] ?? 'native' );
	}

	/** @param array<string, mixed> $search @return array<string, mixed> */
	private function decode_filters( array $search ): array {
		$raw = (string) ( $search['filters'] ?? '' );
		if ( '' === $raw ) {
			return array();
		}
		$d = json_decode( $raw, true );
		return is_array( $d ) ? $d : array();
	}
}
