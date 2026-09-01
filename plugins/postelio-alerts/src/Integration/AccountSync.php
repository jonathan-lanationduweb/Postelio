<?php
/**
 * Intégration cycle de vie du compte (RGPD, §30) :
 *  - `user.deleted` (hard-delete/purge du compte) => purge des favoris + recherches + deliveries.
 *  - filtre `postelio/users/export` => ajoute favoris et recherches à l'export de l'utilisateur.
 *
 * La SUSPENSION n'est PAS une suppression : elle est gérée ailleurs (aucune mutation ni run tant
 * que le compte est suspendu ; les données sont conservées).
 *
 * @package Postelio\Alerts\Integration
 */

namespace Postelio\Alerts\Integration;

use Postelio\Alerts\Alerts\DeliveryRepository;
use Postelio\Alerts\Favorites\FavoriteService;
use Postelio\Alerts\Searches\SavedSearchPresenter;
use Postelio\Alerts\Searches\SavedSearchRepository;
use Postelio\Core\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccountSync {

	private Events $events;
	private FavoriteService $favorites;
	private SavedSearchRepository $searches;
	private DeliveryRepository $deliveries;

	public function __construct( Events $events, FavoriteService $favorites, SavedSearchRepository $searches, DeliveryRepository $deliveries ) {
		$this->events     = $events;
		$this->favorites  = $favorites;
		$this->searches   = $searches;
		$this->deliveries = $deliveries;
	}

	public function register(): void {
		$this->events->on( 'user.deleted', array( $this, 'on_user_deleted' ) );
		add_filter( 'postelio/users/export', array( $this, 'append_export' ), 10, 2 );
	}

	/** @param array<string,mixed> $p */
	public function on_user_deleted( $p ): void {
		$uid = (int) ( is_array( $p ) ? ( $p['id'] ?? 0 ) : 0 );
		if ( $uid <= 0 ) {
			return;
		}
		$this->favorites->repository()->delete_for_candidate( $uid );
		foreach ( $this->searches->delete_for_candidate( $uid ) as $search_id ) {
			$this->deliveries->delete_for_search( (int) $search_id );
		}
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 */
	public function append_export( $data, int $user_id ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$favorites = $this->favorites->list( $user_id, 1, 500 );
		$searches  = array_map( array( SavedSearchPresenter::class, 'view' ), $this->searches->list_for_candidate( $user_id ) );

		$data['favorites']      = $favorites['items'];
		$data['saved_searches'] = $searches;
		return $data;
	}
}
