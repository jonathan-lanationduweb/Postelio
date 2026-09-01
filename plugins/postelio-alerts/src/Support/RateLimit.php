<?php
/**
 * Limitation de débit par transient — même mécanisme que MessagingService/CommentService/
 * ReportService (le core ne fournit pas de rate limiter dédié). Compteur glissant simple par
 * (clé, fenêtre). Lève une ApiError 'rate_limited' (429) au dépassement.
 *
 * @package Postelio\Alerts\Support
 */

namespace Postelio\Alerts\Support;

use Postelio\Core\ApiError;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RateLimit {

	/**
	 * Incrémente le compteur pour $key ; lève si $max atteint dans la fenêtre $window (secondes).
	 */
	public static function hit( string $key, int $max, int $window, string $message = 'Trop de requêtes, réessayez dans un instant.' ): void {
		$max   = max( 1, $max );
		$tkey  = 'pst_alerts_rl_' . md5( $key );
		$count = (int) get_transient( $tkey );
		if ( $count >= $max ) {
			throw new ApiError( 'rate_limited', $message );
		}
		set_transient( $tkey, $count + 1, max( 1, $window ) );
	}
}
