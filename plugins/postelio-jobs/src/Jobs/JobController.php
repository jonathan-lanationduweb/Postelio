<?php
/**
 * Endpoints des offres (namespace `postelio/v1`). Identification publique par UUID.
 *
 *  GET  /jobs                     (public, liste + filtres)
 *  GET  /jobs/{uuid}              (public ; seulement published/expiring)
 *  GET  /jobs/me                  (recruteur : offres de son entreprise, tous statuts)
 *  POST /jobs                     (recruteur + e-mail vérifié : crée un BROUILLON)
 *  PUT  /jobs/{uuid}              (recruteur + e-mail vérifié : édite)
 *  POST /jobs/{uuid}/publish      (recruteur + e-mail vérifié ; entreprise VERIFIED — D1)
 *  POST /jobs/{uuid}/fill         (recruteur : pourvue)
 *  POST /jobs/{uuid}/archive      (recruteur : archive)
 *  POST /jobs/{uuid}/duplicate    (recruteur + e-mail vérifié : nouveau brouillon)
 *  POST /jobs/{uuid}/status       (admin : suspend | published)
 *
 * @package Postelio\Jobs\Jobs
 */

namespace Postelio\Jobs\Jobs;

use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Core\Support\Response;
use Postelio\Jobs\Search\JobSearchProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobController extends Controller {

	private JobRepository $jobs;
	private JobService $service;
	private JobSearchProvider $search;

	public function __construct( JobRepository $jobs, JobService $service, JobSearchProvider $search ) {
		$this->jobs    = $jobs;
		$this->service = $service;
		$this->search  = $search;
	}

	private const UUID = '(?P<uuid>[0-9a-fA-F-]{36})';

	public function register_routes(): void {
		$ns = $this->namespace();

		register_rest_route( $ns, '/jobs', array(
			array( 'methods' => 'GET', 'permission_callback' => Guard::public_access(), 'callback' => $this->guarded( array( $this, 'list_public' ) ) ),
			array( 'methods' => 'POST', 'permission_callback' => Guard::require_all( 'pst_edit_own_company_jobs', 'pst_email_verified' ), 'callback' => $this->guarded( array( $this, 'create' ) ) ),
		) );

		register_rest_route( $ns, '/jobs/me', array(
			'methods' => 'GET', 'permission_callback' => Guard::require_cap( 'pst_edit_own_company_jobs' ), 'callback' => $this->guarded( array( $this, 'list_mine' ) ),
		) );

		register_rest_route( $ns, '/jobs/' . self::UUID, array(
			array( 'methods' => 'GET', 'permission_callback' => Guard::public_access(), 'callback' => $this->guarded( array( $this, 'get_public' ) ) ),
			array( 'methods' => 'PUT', 'permission_callback' => Guard::require_all( 'pst_edit_own_company_jobs', 'pst_email_verified' ), 'callback' => $this->guarded( array( $this, 'update' ) ) ),
		) );

		// Actions de cycle de vie.
		register_rest_route( $ns, '/jobs/' . self::UUID . '/publish', array( 'methods' => 'POST', 'permission_callback' => Guard::require_all( 'pst_publish_job', 'pst_email_verified' ), 'callback' => $this->guarded( array( $this, 'publish' ) ) ) );
		register_rest_route( $ns, '/jobs/' . self::UUID . '/fill', array( 'methods' => 'POST', 'permission_callback' => Guard::require_cap( 'pst_edit_own_company_jobs' ), 'callback' => $this->guarded( array( $this, 'fill' ) ) ) );
		register_rest_route( $ns, '/jobs/' . self::UUID . '/archive', array( 'methods' => 'POST', 'permission_callback' => Guard::require_cap( 'pst_edit_own_company_jobs' ), 'callback' => $this->guarded( array( $this, 'archive' ) ) ) );
		register_rest_route( $ns, '/jobs/' . self::UUID . '/duplicate', array( 'methods' => 'POST', 'permission_callback' => Guard::require_all( 'pst_duplicate_job', 'pst_email_verified' ), 'callback' => $this->guarded( array( $this, 'duplicate' ) ) ) );
		register_rest_route( $ns, '/jobs/' . self::UUID . '/status', array( 'methods' => 'POST', 'permission_callback' => Guard::require_cap( 'pst_manage_all_jobs' ), 'callback' => $this->guarded( array( $this, 'admin_status' ) ) ) );
	}

	public function list_public( \WP_REST_Request $r ): \WP_REST_Response {
		$page     = max( 1, (int) $r->get_param( 'page' ) );
		$per_page = Response::clamp_per_page( (int) $r->get_param( 'per_page' ) ); // borné à MAX_PER_PAGE
		$filters  = $this->sanitize_filters( $r );

		$res   = $this->search->search( $filters, $page, $per_page );
		$items = array_map( array( JobPresenter::class, 'public_view' ), $res['items'] );
		$env   = Response::paginated( $items, $page, $per_page, (int) $res['total'] );
		// Honnêteté du total : un moteur composite (natif + externe) peut être approximatif
		// au-delà de sa fenêtre de fusion. Défaut exact pour le moteur natif seul.
		$env['meta']['pagination']['total_is_exact'] = (bool) ( $res['total_is_exact'] ?? true );
		return $this->raw( $env );
	}

	/**
	 * Valide/normalise les filtres publics (whitelist stricte + typage). Toute clé
	 * inconnue est ignorée ; une valeur invalide est simplement écartée (0 résultat,
	 * jamais d'erreur serveur).
	 *
	 * @return array<string, mixed>
	 */
	private function sanitize_filters( \WP_REST_Request $r ): array {
		$out = array();
		foreach ( array( 'q', 'ville', 'contrat', 'categorie', 'teletravail', 'niveau_etude', 'experience' ) as $k ) {
			$v = $r->get_param( $k );
			if ( null !== $v && '' !== $v ) {
				$out[ $k ] = sanitize_text_field( (string) $v );
			}
		}
		$sal = $r->get_param( 'salaire_min' );
		if ( null !== $sal && '' !== $sal && is_numeric( $sal ) ) {
			$out['salaire_min'] = max( 0, (int) $sal );
		}
		foreach ( array( 'alternance', 'stage', 'debutant' ) as $flag ) {
			$v = $r->get_param( $flag );
			if ( in_array( $v, array( '1', 1, true, 'true' ), true ) ) {
				$out[ $flag ] = true;
			}
		}
		// Filtre de provenance (Lot 10). Défaut = toutes les sources autorisées.
		$source = (string) ( $r->get_param( 'source' ) ?? '' );
		if ( in_array( $source, array( 'all', 'postelio', 'partners' ), true ) ) {
			$out['source'] = $source;
		}
		return $out;
	}

	public function get_public( \WP_REST_Request $r ): \WP_REST_Response {
		$uuid = self::uuid_param( $r );
		$j    = $this->jobs->get_by_uuid( $uuid );
		if ( null !== $j && JobStateMachine::is_public( $j['status'] ) ) {
			return $this->ok( JobPresenter::public_view( $j ) );
		}
		// Repli offre EXTERNE (Lot 10) : résolution déléguée à postelio-job-sources.
		if ( null === $j ) {
			$ext = apply_filters( 'postelio/jobs/resolve_external', null, $uuid );
			if ( is_array( $ext ) && ! empty( $ext['found'] ) ) {
				// Retirée à la source (removed + anonymisée) → 410 Gone (définitif).
				if ( 'removed' === ( $ext['sync_status'] ?? '' ) ) {
					return new \WP_REST_Response( array( 'error' => array( 'code' => 'gone', 'message' => 'Cette offre a été retirée.', 'details' => (object) array() ) ), 410 );
				}
				// Source désactivée/non configurée OU masquée localement → indisponible (404).
				if ( empty( $ext['source_available'] ) || 'visible' !== ( $ext['local_visibility'] ?? '' ) ) {
					throw ApiError::not_found();
				}
				return $this->ok( (array) $ext['public_view'] );
			}
		}
		throw ApiError::not_found();
	}

	public function list_mine(): \WP_REST_Response {
		$company = \Postelio\Companies\Api\CompanyDirectory::company_of_user( get_current_user_id() );
		if ( 0 === $company ) {
			throw ApiError::not_found( 'Aucune entreprise rattachée.' );
		}
		$items = array_map( array( JobPresenter::class, 'owner_view' ), $this->jobs->list_by_company( $company ) );
		return $this->ok( $items );
	}

	public function create( \WP_REST_Request $r ): \WP_REST_Response {
		$id = $this->service->create( get_current_user_id(), (array) $r->get_json_params() );
		return $this->ok( JobPresenter::owner_view( $this->jobs->get( $id ) ), array(), 201 );
	}

	public function update( \WP_REST_Request $r ): \WP_REST_Response {
		$id = $this->resolve( $r );
		return $this->ok( JobPresenter::owner_view( $this->service->update( get_current_user_id(), $id, (array) $r->get_json_params() ) ) );
	}

	public function publish( \WP_REST_Request $r ): \WP_REST_Response {
		return $this->ok( JobPresenter::owner_view( $this->service->publish( get_current_user_id(), $this->resolve( $r ) ) ) );
	}

	public function fill( \WP_REST_Request $r ): \WP_REST_Response {
		return $this->ok( JobPresenter::owner_view( $this->service->fill( get_current_user_id(), $this->resolve( $r ) ) ) );
	}

	public function archive( \WP_REST_Request $r ): \WP_REST_Response {
		return $this->ok( JobPresenter::owner_view( $this->service->archive( get_current_user_id(), $this->resolve( $r ) ) ) );
	}

	public function duplicate( \WP_REST_Request $r ): \WP_REST_Response {
		return $this->ok( JobPresenter::owner_view( $this->service->duplicate( get_current_user_id(), $this->resolve( $r ) ) ), array(), 201 );
	}

	public function admin_status( \WP_REST_Request $r ): \WP_REST_Response {
		$id       = $this->resolve( $r );
		$decision = (string) ( ( (array) $r->get_json_params() )['decision'] ?? '' );
		return $this->ok( JobPresenter::admin_view( $this->service->admin_transition( get_current_user_id(), $id, $decision ) ) );
	}

	private function resolve( \WP_REST_Request $r ): int {
		$j = $this->jobs->get_by_uuid( self::uuid_param( $r ) );
		if ( null === $j ) {
			throw ApiError::not_found();
		}
		return (int) $j['id'];
	}

	/**
	 * UUID depuis les paramètres d'URL (route) UNIQUEMENT — jamais depuis le corps,
	 * pour qu'une clé `uuid` dans le body ne puisse pas détourner la ressource ciblée.
	 */
	private static function uuid_param( \WP_REST_Request $r ): string {
		$url = $r->get_url_params();
		return (string) ( $url['uuid'] ?? '' );
	}
}
