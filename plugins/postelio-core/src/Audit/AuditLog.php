<?php
/**
 * Écriture (append-only) et lecture du journal d'audit.
 *
 * @package Postelio\Core\Audit
 */

namespace Postelio\Core\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuditLog {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_audit_log';
	}

	/**
	 * Insère une entrée d'audit. Append-only : aucune mise à jour/suppression ici.
	 *
	 * @param array{
	 *   action:string, actor_id?:int|null, actor_role?:string|null,
	 *   resource_type?:string|null, resource_id?:string|null,
	 *   metadata?:array, ip?:string|null
	 * } $entry
	 * @return int|false ID inséré, ou false en cas d'échec.
	 */
	public function log( array $entry ) {
		global $wpdb;

		$action = trim( (string) ( $entry['action'] ?? '' ) );
		if ( '' === $action ) {
			return false;
		}

		$actor_id   = $entry['actor_id']   ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$actor_role = $entry['actor_role'] ?? self::current_role();
		$metadata   = isset( $entry['metadata'] ) && ! empty( $entry['metadata'] )
			? wp_json_encode( $entry['metadata'] )
			: null;

		$data = array(
			'actor_id'      => $actor_id ? (int) $actor_id : null,
			'actor_role'    => $actor_role ? substr( (string) $actor_role, 0, 50 ) : null,
			'action'        => substr( $action, 0, 100 ),
			'resource_type' => isset( $entry['resource_type'] ) ? substr( (string) $entry['resource_type'], 0, 50 ) : null,
			'resource_id'   => isset( $entry['resource_id'] ) ? substr( (string) $entry['resource_id'], 0, 64 ) : null,
			'metadata'      => $metadata,
			'ip'            => self::pack_ip( $entry['ip'] ?? null ),
			'created_at'    => current_time( 'mysql', true ),
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		$ok = $wpdb->insert( self::table(), $data, $formats );
		return false !== $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Nombre total d'entrées (lecture admin — utilisé par health/tests).
	 */
	public function count(): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nom de table interne, non issu de l'utilisateur.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	private static function current_role(): ?string {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return null;
		}
		$user = wp_get_current_user();
		if ( ! $user || empty( $user->roles ) ) {
			return null;
		}
		return (string) $user->roles[0];
	}

	/**
	 * Valide une IP (v4/v6) et la renvoie sous forme lisible. Null si absente/invalide.
	 * Conforme D7 : n'écrit une IP que si elle est explicitement fournie.
	 */
	private static function pack_ip( ?string $ip ): ?string {
		if ( null === $ip || '' === $ip ) {
			return null;
		}
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}
}
