<?php
/**
 * Service des recherches sauvegardées + alertes (candidat). create/update/delete/list/get/
 * preview/run. Validation STRICTE des filtres (whitelist Jobs, clé inconnue => 422). Quotas V1
 * filtrables. Déduplication par empreinte de filtres (§14). Ownership strict (non-propriétaire =>
 * 404). Le curseur/planification restent gérés par le moteur (MatchingService) et le scheduler.
 *
 * @package Postelio\Alerts\Searches
 */

namespace Postelio\Alerts\Searches;

use Postelio\Alerts\Alerts\DeliveryRepository;
use Postelio\Alerts\Alerts\MatchingService;
use Postelio\Alerts\Time\ParisSchedule;
use Postelio\Core\ApiError;
use Postelio\Core\Events;
use Postelio\Jobs\Search\FilterValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SavedSearchService {

	private SavedSearchRepository $repo;
	private DeliveryRepository $deliveries;
	private MatchingService $matching;
	private Events $events;

	public function __construct( SavedSearchRepository $repo, DeliveryRepository $deliveries, MatchingService $matching, Events $events ) {
		$this->repo       = $repo;
		$this->deliveries = $deliveries;
		$this->matching   = $matching;
		$this->events     = $events;
	}

	public function repository(): SavedSearchRepository {
		return $this->repo;
	}

	private function max_searches(): int {
		return max( 1, (int) apply_filters( 'postelio/alerts/max_saved_searches', 20 ) );
	}
	private function max_active_alerts(): int {
		return max( 1, (int) apply_filters( 'postelio/alerts/max_active_alerts', 10 ) );
	}

	// --- CRUD -----------------------------------------------------------------

	/**
	 * Crée une recherche sauvegardée. $input : { name?, filters?:{}, alert_frequency? }.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed> Ligne créée.
	 */
	public function create( int $candidate_user_id, array $input ): array {
		$name      = $this->clean_name( $input['name'] ?? '' );
		$filters   = FilterValidator::validate( $this->incoming_filters( $input ), true ); // strict
		$hash      = FilterValidator::fingerprint( $filters );
		$frequency = $this->clean_frequency( $input['alert_frequency'] ?? SavedSearchRepository::FREQ_DISABLED );

		// Quotas.
		if ( $this->repo->count_for_candidate( $candidate_user_id ) >= $this->max_searches() ) {
			throw new ApiError( 'conflict', 'Nombre maximal de recherches sauvegardées atteint.', array( 'limit' => $this->max_searches(), 'reason' => 'saved_searches_quota' ) );
		}
		if ( SavedSearchRepository::FREQ_DISABLED !== $frequency
			&& $this->repo->count_active_for_candidate( $candidate_user_id ) >= $this->max_active_alerts() ) {
			throw new ApiError( 'conflict', 'Nombre maximal d\'alertes actives atteint.', array( 'limit' => $this->max_active_alerts(), 'reason' => 'active_alerts_quota' ) );
		}

		// Déduplication (§14) : filtres identiques => on refuse et on pointe l'existante.
		$dup = $this->repo->get_by_hash( $candidate_user_id, $hash );
		if ( null !== $dup ) {
			throw new ApiError( 'conflict', 'Une recherche avec des filtres identiques existe déjà.', array(
				'reason'            => 'duplicate_filters',
				'saved_search_uuid' => (string) $dup['public_uuid'],
			) );
		}

		$next_run_at = SavedSearchRepository::FREQ_DISABLED !== $frequency
			? ParisSchedule::next_run( $frequency, time() )
			: null;

		$id = $this->repo->insert( array(
			'public_uuid'       => wp_generate_uuid4(),
			'candidate_user_id' => $candidate_user_id,
			'name'              => $name,
			'filters'           => (string) wp_json_encode( $filters ),
			'filters_hash'      => $hash,
			'alert_frequency'   => $frequency,
			'timezone'          => ParisSchedule::TIMEZONE,
			'cursor_ts'         => null,
			'next_run_at'       => $next_run_at,
		) );
		if ( 0 === $id ) {
			// Course sur la contrainte UNIQUE : traiter comme doublon.
			throw new ApiError( 'conflict', 'Une recherche avec des filtres identiques existe déjà.', array( 'reason' => 'duplicate_filters' ) );
		}

		$row = $this->repo->get_by_uuid( $this->uuid_of_id( $candidate_user_id, $id ) );
		$row = $row ?: array();
		$this->events->emit( 'saved_search.created', array(
			'candidate_user_id' => $candidate_user_id,
			'saved_search_uuid' => (string) ( $row['public_uuid'] ?? '' ),
			'alert_frequency'   => $frequency,
			'resource_type'     => 'saved_search',
			'resource_id'       => (string) $id,
		) );
		return $row;
	}

	/**
	 * Met à jour une recherche. $input : { name?, filters?:{}, alert_frequency? }.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function update( int $candidate_user_id, string $uuid, array $input ): array {
		$row    = $this->owned_or_404( $candidate_user_id, $uuid );
		$id     = (int) $row['id'];
		$fields = array();

		if ( array_key_exists( 'name', $input ) ) {
			$fields['name'] = $this->clean_name( $input['name'] );
		}

		if ( array_key_exists( 'filters', $input ) ) {
			$filters = FilterValidator::validate( (array) $input['filters'], true );
			$hash    = FilterValidator::fingerprint( $filters );
			$dup     = $this->repo->get_by_hash( $candidate_user_id, $hash );
			if ( null !== $dup && (int) $dup['id'] !== $id ) {
				throw new ApiError( 'conflict', 'Une autre recherche avec des filtres identiques existe déjà.', array(
					'reason'            => 'duplicate_filters',
					'saved_search_uuid' => (string) $dup['public_uuid'],
				) );
			}
			$fields['filters']      = (string) wp_json_encode( $filters );
			$fields['filters_hash'] = $hash;
			// Les filtres changent : le curseur repart de maintenant (évite de renotifier tout
			// l'historique sous les nouveaux critères ; la table de deliveries reste la garantie).
			$fields['cursor_ts']    = current_time( 'mysql', true );
		}

		$old_freq = (string) $row['alert_frequency'];
		$new_freq = $old_freq;
		if ( array_key_exists( 'alert_frequency', $input ) ) {
			$new_freq = $this->clean_frequency( $input['alert_frequency'] );
			$becoming_active = ( SavedSearchRepository::FREQ_DISABLED === $old_freq && SavedSearchRepository::FREQ_DISABLED !== $new_freq );
			if ( $becoming_active && $this->repo->count_active_for_candidate( $candidate_user_id ) >= $this->max_active_alerts() ) {
				throw new ApiError( 'conflict', 'Nombre maximal d\'alertes actives atteint.', array( 'limit' => $this->max_active_alerts(), 'reason' => 'active_alerts_quota' ) );
			}
			$fields['alert_frequency'] = $new_freq;
			if ( SavedSearchRepository::FREQ_DISABLED === $new_freq ) {
				$fields['next_run_at'] = null;
			} elseif ( $new_freq !== $old_freq ) {
				$fields['next_run_at'] = ParisSchedule::next_run( $new_freq, time() );
			}
		}

		if ( ! empty( $fields ) ) {
			$this->repo->update( $id, $fields );
		}
		$updated = $this->repo->get_by_uuid( $uuid ) ?: $row;
		$this->events->emit( 'saved_search.updated', array(
			'candidate_user_id' => $candidate_user_id,
			'saved_search_uuid' => $uuid,
			'alert_frequency'   => $new_freq,
			'resource_type'     => 'saved_search',
			'resource_id'       => (string) $id,
		) );
		return $updated;
	}

	public function delete( int $candidate_user_id, string $uuid ): void {
		$row = $this->owned_or_404( $candidate_user_id, $uuid );
		$id  = (int) $row['id'];
		$this->deliveries->delete_for_search( $id );
		$this->repo->delete( $id );
		$this->events->emit( 'saved_search.deleted', array(
			'candidate_user_id' => $candidate_user_id,
			'saved_search_uuid' => $uuid,
			'resource_type'     => 'saved_search',
			'resource_id'       => (string) $id,
		) );
	}

	/** @return array<int, array<string,mixed>> */
	public function list( int $candidate_user_id ): array {
		return $this->repo->list_for_candidate( $candidate_user_id );
	}

	/** @return array<string, mixed> */
	public function get( int $candidate_user_id, string $uuid ): array {
		return $this->owned_or_404( $candidate_user_id, $uuid );
	}

	// --- Preview / Run --------------------------------------------------------

	/**
	 * Prévisualise les résultats ACTUELS d'une recherche (sans notification, sans dédup).
	 *
	 * @return array{count:int, sample: array<int, array<string,mixed>>, total_is_exact:bool}
	 */
	public function preview( int $candidate_user_id, string $uuid ): array {
		$row     = $this->owned_or_404( $candidate_user_id, $uuid );
		$filters = FilterValidator::validate( $this->decode_filters( $row ), false );
		return $this->matching->preview( $filters );
	}

	/**
	 * Exécute immédiatement l'alerte (run-now). Respecte curseur + deliveries : ne peut pas
	 * renvoyer deux fois la même offre ni servir de spam.
	 *
	 * @return array{matched:int, digest:bool}
	 */
	public function run_now( int $candidate_user_id, string $uuid ): array {
		$row = $this->owned_or_404( $candidate_user_id, $uuid );
		return $this->matching->run( $row, 'run_now' );
	}

	// --- Helpers --------------------------------------------------------------

	/** @return array<string, mixed> */
	private function owned_or_404( int $candidate_user_id, string $uuid ): array {
		$row = $this->repo->get_by_uuid( $uuid );
		if ( null === $row || (int) $row['candidate_user_id'] !== $candidate_user_id ) {
			throw ApiError::not_found();
		}
		return $row;
	}

	/** @param mixed $name */
	private function clean_name( $name ): string {
		$name = sanitize_text_field( (string) $name );
		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, 190 );
		} else {
			$name = substr( $name, 0, 190 );
		}
		return $name;
	}

	/** @param mixed $freq */
	private function clean_frequency( $freq ): string {
		$freq = is_string( $freq ) ? $freq : SavedSearchRepository::FREQ_DISABLED;
		return in_array( $freq, SavedSearchRepository::frequencies(), true ) ? $freq : SavedSearchRepository::FREQ_DISABLED;
	}

	/** @param array<string, mixed> $input @return array<string, mixed> */
	private function incoming_filters( array $input ): array {
		return isset( $input['filters'] ) && is_array( $input['filters'] ) ? $input['filters'] : array();
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private function decode_filters( array $row ): array {
		$d = json_decode( (string) ( $row['filters'] ?? '' ), true );
		return is_array( $d ) ? $d : array();
	}

	/** Retrouve l'UUID d'une ligne fraîchement insérée (par id + candidat). */
	private function uuid_of_id( int $candidate_user_id, int $id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT public_uuid FROM ' . SavedSearchRepository::table() . ' WHERE id = %d AND candidate_user_id = %d',
			$id,
			$candidate_user_id
		) );
	}
}
