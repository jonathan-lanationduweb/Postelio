<?php
/**
 * Contrat public STABLE des notifications (lecture seule inter-plugin / header). Les
 * autres plugins NE créent jamais de notification directement : ils émettent des
 * événements que le Router consomme. Ce contrat sert au compteur de la cloche et à un
 * aperçu récent.
 *
 * @package Postelio\Notifications\Api
 */

namespace Postelio\Notifications\Api;

use Postelio\Notifications\Notifications\NotificationPresenter;
use Postelio\Notifications\Notifications\NotificationRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NotificationDirectory {

	/** Nombre de notifications in-app NON LUES (cloche). Distinct du compteur messagerie. */
	public static function unread_count( int $user_id ): int {
		return ( new NotificationRepository() )->unread_count( $user_id );
	}

	/**
	 * Aperçu récent (présenté, sans ID interne).
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function recent( int $user_id, int $limit = 10 ): array {
		$limit = max( 1, min( 50, $limit ) );
		$res   = ( new NotificationRepository() )->list_for_user( $user_id, array(), 1, $limit );
		return array_map( array( NotificationPresenter::class, 'view' ), $res['items'] );
	}
}
