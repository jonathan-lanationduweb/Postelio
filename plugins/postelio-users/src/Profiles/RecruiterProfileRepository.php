<?php
/**
 * Accès DB au profil recruteur (`wp_postelio_recruiter_profiles`).
 *
 * @package Postelio\Users\Profiles
 */

namespace Postelio\Users\Profiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RecruiterProfileRepository {

	private const FIELDS = array( 'prenom', 'nom', 'fonction', 'email_pro', 'telephone_pro' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_recruiter_profiles';
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_by_user( int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d', $user_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['id']         = (int) $row['id'];
		$row['user_id']    = (int) $row['user_id'];
		$row['company_id'] = null !== $row['company_id'] ? (int) $row['company_id'] : null;
		return $row;
	}

	public function create_for( int $user_id ): void {
		global $wpdb;
		if ( null !== $this->get_by_user( $user_id ) ) {
			return;
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::table(),
			array(
				'user_id'    => $user_id,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function update( int $user_id, array $input ): array {
		global $wpdb;

		if ( null === $this->get_by_user( $user_id ) ) {
			$this->create_for( $user_id );
		}

		$data    = array();
		$formats = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$value = $input[ $field ];
			if ( in_array( $field, array( 'email_pro' ), true ) && null !== $value ) {
				$data[ $field ] = sanitize_email( (string) $value );
			} else {
				$data[ $field ] = null === $value ? null : sanitize_text_field( (string) $value );
			}
			$formats[] = '%s';
		}
		$data['updated_at'] = current_time( 'mysql', true );
		$formats[]          = '%s';

		$wpdb->update( self::table(), $data, array( 'user_id' => $user_id ), $formats, array( '%d' ) );

		return $this->get_by_user( $user_id );
	}

	/**
	 * Rattache le profil recruteur à une entreprise (dénormalisation `company_id`).
	 * Appelé par l'écouteur d'événement `company.member_added` — jamais par l'API
	 * (le recruteur ne fixe pas lui-même son `company_id`).
	 */
	public function set_company( int $user_id, ?int $company_id ): void {
		global $wpdb;
		$this->create_for( $user_id );
		$wpdb->update(
			self::table(),
			array( 'company_id' => $company_id, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'user_id' => $user_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	public function delete_for( int $user_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
	}
}
