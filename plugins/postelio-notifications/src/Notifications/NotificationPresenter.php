<?php
/**
 * Présentation d'une notification pour l'API : UUID uniquement, action structurée
 * (jamais d'URL absolue), aucun ID interne. Compatible web + Tauri (le client traduit
 * `action` en navigation).
 *
 * @package Postelio\Notifications\Notifications
 */

namespace Postelio\Notifications\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NotificationPresenter {

	/**
	 * @param array<string, mixed> $n
	 * @return array<string, mixed>
	 */
	public static function view( array $n ): array {
		return array(
			'uuid'       => (string) $n['public_uuid'],
			'type'       => (string) $n['type'],
			'priority'   => (string) $n['priority'],
			'title'      => (string) $n['title'],
			'body'       => $n['body'] ?? null,
			'read'       => null !== ( $n['read_at'] ?? null ),
			'resolved'   => null !== ( $n['resolved_at'] ?? null ),
			'action'     => array(
				'type'          => $n['action_type'] ?? null,
				'resource_type' => $n['resource_type'] ?? null,
				'resource_uuid' => $n['resource_uuid'] ?? null,
			),
			'group_key'  => $n['group_key'] ?? null,
			'created_at' => self::iso( (string) $n['created_at'] ),
		);
	}

	private static function iso( string $mysql_utc ): ?string {
		if ( '' === $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return null;
		}
		return str_replace( ' ', 'T', $mysql_utc ) . 'Z';
	}
}
