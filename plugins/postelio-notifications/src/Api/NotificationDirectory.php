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
	 * OBSERVABILITÉ ADMIN de la file d'e-mails (aucun contenu privé, aucun destinataire). Compteurs
	 * par statut + prochaine tentative planifiée. Lecture seule sur la table du domaine.
	 *
	 * @return array<string,mixed>
	 */
	public static function delivery_stats(): array {
		global $wpdb;
		$table = \Postelio\Notifications\Notifications\DeliveryRepository::table();
		$by    = array();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$by[ (string) $r['status'] ] = (int) $r['n'];
		}
		$next = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(scheduled_at) FROM {$table} WHERE status = %s", \Postelio\Notifications\Notifications\DeliveryRepository::PENDING ) );
		$last_failed = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(failed_at) FROM {$table} WHERE status = %s", \Postelio\Notifications\Notifications\DeliveryRepository::FAILED ) );
		return array(
			'pending'    => (int) ( $by['pending'] ?? 0 ),
			'processing' => (int) ( $by['processing'] ?? 0 ),
			'sent'       => (int) ( $by['sent'] ?? 0 ),
			'failed'     => (int) ( $by['failed'] ?? 0 ),
			'skipped'    => (int) ( $by['skipped'] ?? 0 ),
			'next_retry_at'  => $next ? (string) $next : null,
			'last_failed_at' => $last_failed ? (string) $last_failed : null,
		);
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
