<?php
/**
 * Façade de lecture AGRÉGÉE pour la supervision admin (privacy-first). N'expose QUE des
 * compteurs et l'état du planificateur — JAMAIS le contenu des recherches, les termes/villes
 * recherchés, ni les favoris d'un utilisateur donné (§31). Aucune donnée personnelle.
 *
 * @package Postelio\Alerts\Api
 */

namespace Postelio\Alerts\Api;

use Postelio\Alerts\Alerts\AlertDispatcher;
use Postelio\Alerts\Alerts\DeliveryRepository;
use Postelio\Alerts\Favorites\FavoriteRepository;
use Postelio\Alerts\Searches\SavedSearchRepository;
use Postelio\Core\Jobs\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AlertsAdminDirectory {

	/**
	 * Indicateurs de supervision (agrégés).
	 *
	 * @return array<string, mixed>
	 */
	public static function stats(): array {
		$favorites  = new FavoriteRepository();
		$searches   = new SavedSearchRepository();
		$deliveries = new DeliveryRepository();

		$since_24h = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$hook      = Scheduler::HOOK_PREFIX . AlertDispatcher::HOOK_DISPATCH;
		$next_ts   = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( $hook ) : false;

		return array(
			'favorites_total'      => $favorites->count_all(),
			'saved_searches_total' => $searches->count_all(),
			'active_alerts_total'  => $searches->count_active_all(),
			'digests_sent_24h'     => $deliveries->count_sent_since( $since_24h ),
			'scheduler'            => array(
				'dispatch_armed'   => (bool) $next_ts,
				'next_dispatch_at' => $next_ts ? gmdate( 'Y-m-d H:i:s', (int) $next_ts ) : null,
			),
		);
	}
}
