<?php
/**
 * Service des notifications in-app (façade de NotificationRepository). Ne crée jamais de
 * notification pour un destinataire inexistant/inactif (compte supprimé/désactivé).
 * Neutralise le contenu dynamique (titre/corps) contre le XSS.
 *
 * @package Postelio\Notifications\Notifications
 */

namespace Postelio\Notifications\Notifications;

use Postelio\Users\Api\UserDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NotificationService {

	private NotificationRepository $repo;

	public function __construct( NotificationRepository $repo ) {
		$this->repo = $repo;
	}

	public function repository(): NotificationRepository {
		return $this->repo;
	}

	/**
	 * Crée une notification in-app (idempotente via dedup_key). Retourne l'id ou 0
	 * (doublon, ou destinataire invalide).
	 *
	 * @param array<string, mixed> $data
	 */
	public function create( array $data ): int {
		$user_id = (int) ( $data['user_id'] ?? 0 );
		if ( $user_id <= 0 || ! UserDirectory::exists( $user_id ) || ! UserDirectory::is_active( $user_id ) ) {
			return 0; // §59 : rien pour un compte inexistant/inactif
		}
		$data['title'] = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		$data['body']  = isset( $data['body'] ) ? sanitize_textarea_field( (string) $data['body'] ) : null;
		return $this->repo->insert( $data );
	}

	public function unread_count( int $user_id ): int {
		return $this->repo->unread_count( $user_id );
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function list( int $user_id, array $filters, int $page, int $per_page ): array {
		return $this->repo->list_for_user( $user_id, $filters, $page, $per_page );
	}

	public function mark_read( string $uuid, int $user_id ): bool {
		return $this->repo->mark_read( $uuid, $user_id );
	}

	public function mark_all_read( int $user_id ): int {
		return $this->repo->mark_all_read( $user_id );
	}

	public function resolve_group( int $user_id, string $group_key ): int {
		return $this->repo->resolve_group( $user_id, $group_key );
	}
}
