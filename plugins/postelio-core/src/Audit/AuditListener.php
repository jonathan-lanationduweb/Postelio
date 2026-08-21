<?php
/**
 * Écoute générique du bus d'événements et journalisation d'audit.
 *
 * Le core écoute TOUS les événements (`postelio/event`) et journalise ceux qui
 * sont auditables. Politique minimale et non sensible : on enregistre le nom de
 * l'événement, le type/ID de ressource et une métadonnée d'audit explicite, sans
 * dumper la charge utile (qui peut contenir des données personnelles).
 *
 * @package Postelio\Core\Audit
 */

namespace Postelio\Core\Audit;

use Postelio\Core\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuditListener {

	/** Événements non audités (métriques / bruit). Voir docs/backend/events.md. */
	private const DENYLIST = array(
		'message.read',
		'notification.created',
		'core.ready',
	);

	private AuditLog $log;
	private Events $events;

	public function __construct( AuditLog $log, Events $events ) {
		$this->log    = $log;
		$this->events = $events;
	}

	public function subscribe(): void {
		$this->events->on_any( array( $this, 'handle' ), 100 );
	}

	/**
	 * @param string               $event
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $context
	 */
	public function handle( string $event, array $payload = array(), array $context = array() ): void {
		if ( in_array( $event, self::DENYLIST, true ) ) {
			return;
		}
		if ( ! self::is_auditable( $event ) ) {
			return;
		}

		$this->log->log(
			array(
				'action'        => $event,
				'resource_type' => isset( $payload['resource_type'] ) ? (string) $payload['resource_type'] : null,
				'resource_id'   => self::resource_id( $payload ),
				'metadata'      => isset( $payload['audit'] ) && is_array( $payload['audit'] ) ? $payload['audit'] : array(),
				'ip'            => isset( $payload['ip'] ) ? (string) $payload['ip'] : null,
			)
		);
	}

	/**
	 * Autorise l'écriture : n'audite que si la table existe (plugin actif/migré).
	 */
	private static function is_auditable( string $event ): bool {
		if ( '' === trim( $event ) ) {
			return false;
		}
		static $table_ready = null;
		if ( null === $table_ready ) {
			global $wpdb;
			$table       = AuditLog::table();
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$table_ready = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		}
		return (bool) $table_ready;
	}

	private static function resource_id( array $payload ): ?string {
		if ( isset( $payload['resource_id'] ) ) {
			return (string) $payload['resource_id'];
		}
		if ( isset( $payload['id'] ) ) {
			return (string) $payload['id'];
		}
		return null;
	}
}
