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
	 * ÉCHECS DÉFINITIFS de la file e-mail (diagnostic admin). Par livraison : date, type (template),
	 * destinataire MASQUÉ (résolu depuis l'adresse courante de l'utilisateur, comme à l'envoi),
	 * tentatives, dernière erreur, état. Aucun contenu, aucune adresse complète, aucun id SQL.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function delivery_failures( int $limit = 20 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		$table = \Postelio\Notifications\Notifications\DeliveryRepository::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT public_uuid, user_id, template, status, attempts, max_attempts, created_at, failed_at, last_error FROM {$table} WHERE status = %s ORDER BY failed_at DESC, id DESC LIMIT %d",
				\Postelio\Notifications\Notifications\DeliveryRepository::FAILED,
				$limit
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$user = get_userdata( (int) $r['user_id'] );
			$out[] = array(
				'uuid'             => (string) $r['public_uuid'],
				'template'         => (string) $r['template'],
				'status'           => (string) $r['status'],
				'attempts'         => (int) $r['attempts'],
				'max_attempts'     => (int) $r['max_attempts'],
				'created_at'       => (string) $r['created_at'],
				'failed_at'        => (string) ( $r['failed_at'] ?? '' ),
				'last_error'       => (string) ( $r['last_error'] ?? '' ),
				'recipient_masked' => $user ? \Postelio\Notifications\Notifications\EmailDispatcher::mask_email( (string) $user->user_email ) : '— (compte supprimé)',
			);
		}
		return $out;
	}

	/**
	 * Transport e-mail réellement actif (aucun secret).
	 *
	 * @return array{provider:string, label:string, detail:string, smtp_configured:bool, is_wp_mail:bool}
	 */
	public static function transport(): array {
		return \Postelio\Notifications\Plugin::instance()->emails()->transport();
	}

	/** Envoie un e-mail de test à l'adresse COURANTE de $user_id via le pipeline/provider Postelio. */
	public static function send_test( int $user_id ): \Postelio\Notifications\Email\DeliveryResult {
		return \Postelio\Notifications\Plugin::instance()->emails()->send_test( $user_id );
	}

	/** @return array<string,mixed>|null Résultat du dernier e-mail de test (destinataire masqué). */
	public static function last_test(): ?array {
		return \Postelio\Notifications\Plugin::instance()->emails()->last_test();
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
