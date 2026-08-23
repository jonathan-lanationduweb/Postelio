<?php
/**
 * Persistance des signalements utilisateurs. `reporter_user_id` reste INTERNE (jamais
 * exposé au contenu signalé). Description bornée (RGPD) ; pas de copie du contenu.
 *
 * @package Postelio\Moderation\Reports
 */

namespace Postelio\Moderation\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReportRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_moderation_reports';
	}

	/** @param array<string, mixed> $data */
	public function insert( array $data ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'public_uuid'      => $this->unique_uuid(),
				'reporter_user_id' => (int) $data['reporter_user_id'],
				'resource_type'    => (string) $data['resource_type'],
				'resource_uuid'    => (string) $data['resource_uuid'],
				'reason_code'      => (string) $data['reason_code'],
				'description'      => isset( $data['description'] ) ? mb_substr( (string) $data['description'], 0, 500 ) : null,
				'status'           => 'received',
				'case_id'          => isset( $data['case_id'] ) ? (int) $data['case_id'] : null,
				'created_at'       => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Un report identique récent (dédup) existe-t-il dans la fenêtre ? */
	public function recent_duplicate( int $reporter, string $resource_type, string $resource_uuid, string $reason, int $window_seconds ): bool {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $window_seconds ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . ' WHERE reporter_user_id = %d AND resource_type = %s AND resource_uuid = %s AND reason_code = %s AND created_at >= %s',
				$reporter, $resource_type, $resource_uuid, $reason, $since
			)
		);
	}

	/** Nombre de reports d'un utilisateur dans une fenêtre (rate limit). */
	public function count_recent_by_reporter( int $reporter, int $window_seconds ): int {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $window_seconds ) );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE reporter_user_id = %d AND created_at >= %s', $reporter, $since ) );
	}

	public function attach_case( int $report_id, int $case_id ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'case_id' => $case_id ), array( 'id' => $report_id ) );
	}

	/** @return array<int, array<string,mixed>> Reports d'un reporter (les siens uniquement). */
	public function list_for_reporter( int $reporter, int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE reporter_user_id = %d ORDER BY id DESC LIMIT %d', $reporter, max( 1, $limit ) ), ARRAY_A );
		return $rows ?: array();
	}

	/** Statut public simplifié d'un report (received|under_review|resolved). */
	public static function public_status( array $report, ?array $case ): string {
		if ( null === $case ) {
			return 'received';
		}
		if ( in_array( (string) $case['status'], array( 'resolved', 'dismissed' ), true ) ) {
			return 'resolved';
		}
		return 'under_review';
	}

	/** @return array<string,mixed>|null */
	public function get_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE public_uuid = %s', $uuid ), ARRAY_A );
		return $row ?: null;
	}

	private function unique_uuid(): string {
		do {
			$uuid = wp_generate_uuid4();
		} while ( null !== $this->get_by_uuid( $uuid ) );
		return $uuid;
	}
}
