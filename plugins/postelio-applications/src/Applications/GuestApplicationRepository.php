<?php
/**
 * Accès DB aux candidatures guest (`wp_postelio_guest_applications`).
 *
 * @package Postelio\Applications\Applications
 */

namespace Postelio\Applications\Applications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GuestApplicationRepository {

	public const STATUS_PENDING = 'pending_email';
	public const STATUS_LINKED  = 'linked';
	public const STATUS_EXPIRED = 'expired';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_guest_applications';
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::table(),
			array(
				'public_uuid'       => $this->unique_uuid(),
				'job_id'            => (int) $data['job_id'],
				'job_uuid'          => (string) $data['job_uuid'],
				'company_id'        => (int) $data['company_id'],
				'company_uuid'      => (string) $data['company_uuid'],
				'email'             => (string) $data['email'],
				'first_name'        => (string) $data['first_name'],
				'last_name'         => (string) $data['last_name'],
				'cv_reference'      => isset( $data['cv_reference'] ) ? (string) $data['cv_reference'] : null,
				'screening_answers' => wp_json_encode( $data['screening_answers'] ?? array() ),
				'message'           => isset( $data['message'] ) ? (string) $data['message'] : null,
				'consent_at'        => (string) $data['consent_at'],
				'token_hash'        => (string) $data['token_hash'],
				'token_expires'     => (int) $data['token_expires'],
				'status'            => self::STATUS_PENDING,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/** @return array<string, mixed>|null */
	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	/** @return array<string, mixed>|null */
	public function get_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE public_uuid = %s', $uuid ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	/** Recherche une candidature guest par (uuid, token haché) — accès protégé par jeton. */
	public function get_by_uuid_and_token( string $uuid, string $token_hash ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE public_uuid = %s AND token_hash = %s', $uuid, $token_hash ),
			ARRAY_A
		);
		return $row ? $this->decode( $row ) : null;
	}

	/** Candidature guest en attente pour (offre, e-mail) — dédoublonnage/renvoi. */
	public function pending_for( int $job_id, string $email ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE job_id = %d AND email = %s AND status = %s ORDER BY id DESC LIMIT 1',
				$job_id,
				$email,
				self::STATUS_PENDING
			),
			ARRAY_A
		);
		return $row ? $this->decode( $row ) : null;
	}

	/** Régénère le jeton (renvoi de confirmation) sur une candidature en attente. */
	public function refresh_token( int $id, string $token_hash, int $token_expires ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array( 'token_hash' => $token_hash, 'token_expires' => $token_expires, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	public function mark_linked( int $id, string $application_uuid ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'                  => self::STATUS_LINKED,
				'linked_application_uuid' => $application_uuid,
				'token_hash'              => '', // jeton consommé
				'updated_at'              => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/** Purge RGPD : candidatures guest non confirmées et expirées. Retourne le nb supprimé. */
	public function purge_expired( int $now ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE status = %s AND token_expires < %d',
				self::STATUS_PENDING,
				$now
			)
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function decode( array $row ): array {
		$row['id']                = (int) $row['id'];
		$row['job_id']            = (int) $row['job_id'];
		$row['company_id']        = (int) $row['company_id'];
		$row['token_expires']     = (int) $row['token_expires'];
		$row['screening_answers'] = is_string( $row['screening_answers'] ?? null ) && '' !== $row['screening_answers']
			? ( json_decode( $row['screening_answers'], true ) ?: array() )
			: array();
		return $row;
	}

	private function unique_uuid(): string {
		do {
			$uuid = wp_generate_uuid4();
		} while ( null !== $this->get_by_uuid( $uuid ) );
		return $uuid;
	}
}
