<?php
/**
 * Notes recruteur PRIVÉES (`wp_postelio_recruiter_notes`). Jamais exposées au candidat.
 *
 * @package Postelio\Applications\Applications
 */

namespace Postelio\Applications\Applications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NoteRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_recruiter_notes';
	}

	public function add( int $application_id, int $author_id, string $body ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::table(),
			array(
				'application_id' => $application_id,
				'author_id'      => $author_id,
				'body'           => $body,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public function list_for_application( int $application_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, author_id, body, created_at, updated_at FROM ' . self::table() . ' WHERE application_id = %d ORDER BY id ASC', $application_id ),
			ARRAY_A
		);
		return array_map(
			static function ( array $r ): array {
				$r['id']        = (int) $r['id'];
				$r['author_id'] = (int) $r['author_id'];
				return $r;
			},
			$rows ?: array()
		);
	}

	public function count_for_application( int $application_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE application_id = %d', $application_id ) );
	}

	public function delete_for_application( int $application_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'application_id' => $application_id ), array( '%d' ) );
	}
}
