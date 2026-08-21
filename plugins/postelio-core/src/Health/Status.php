<?php
/**
 * État de santé interne du socle Postelio.
 *
 * @package Postelio\Core\Health
 */

namespace Postelio\Core\Health;

use Postelio\Core\Audit\AuditLog;
use Postelio\Core\Plugin;
use Postelio\Core\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Status {

	private Registry $registry;

	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function snapshot(): array {
		$db_ok       = $this->db_reachable();
		$audit_ready = $this->audit_table_exists();
		$schema      = (string) get_option( Plugin::SCHEMA_OPTION, '0' );
		$deps        = $this->registry->missing_dependencies();

		$healthy = $db_ok && $audit_ready && empty( $deps );

		return array(
			'status'         => $healthy ? 'ok' : 'degraded',
			'core_version'   => POSTELIO_CORE_VERSION,
			'api_namespace'  => POSTELIO_REST_NAMESPACE,
			'schema_version' => $schema,
			'checks'         => array(
				'database'          => $db_ok,
				'audit_table'       => $audit_ready,
				'dependencies_met'  => empty( $deps ),
			),
			'modules'        => array_keys( $this->registry->all() ),
		);
	}

	private function db_reachable(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( 'SELECT 1' );
	}

	private function audit_table_exists(): bool {
		global $wpdb;
		$table = AuditLog::table();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
