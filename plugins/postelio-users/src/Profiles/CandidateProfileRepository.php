<?php
/**
 * Accès DB au profil candidat (`wp_postelio_candidate_profiles`).
 *
 * Le Lot 02 gère les champs de BASE ; les listes riches (expériences, compétences…)
 * sont conservées telles quelles en JSON pour les lots suivants, sans logique métier.
 *
 * @package Postelio\Users\Profiles
 */

namespace Postelio\Users\Profiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CandidateProfileRepository {

	/** Champs stockés en JSON. */
	private const JSON_FIELDS = array(
		'metiers_alt',
		'alternance',
		'mobilite',
		'experiences',
		'formations',
		'competences_principales',
		'competences_complementaires',
		'langues',
		'certifications',
		'realisations',
		'liens',
		'visibility',
		'blocked_companies',
	);

	/** Champs scalaires modifiables. */
	private const SCALAR_FIELDS = array(
		'metier',
		'ville',
		'rayon_km',
		'contrat',
		'temps_travail',
		'teletravail',
		'salaire_souhaite',
		'niveau_etude',
		'disponibilite',
		'dispo_date',
		'statut_recherche',
		'statut_visible',
		'profile_visibility',
		'a_propos',
		'telephone',
		'photo',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_candidate_profiles';
	}

	/**
	 * @return array<string, mixed>|null Profil décodé (JSON → tableaux) ou null.
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
		return $this->decode( $row );
	}

	/**
	 * Crée la ligne de profil vide pour un nouvel utilisateur (avec UUID public).
	 */
	public function create_for( int $user_id ): void {
		global $wpdb;
		if ( null !== $this->get_by_user( $user_id ) ) {
			return;
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::table(),
			array(
				'user_id'            => $user_id,
				'public_uuid'        => wp_generate_uuid4(),
				'profile_visibility' => 'recruteurs',
				'statut_visible'     => 1,
				'created_at'         => $now,
				'date_maj'           => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Recherche par UUID public (identifiant exposé via l'API).
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE public_uuid = %s', $uuid ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		return $this->decode( $row );
	}

	/**
	 * Met à jour les champs fournis (whitelist). Retourne le profil à jour.
	 *
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

		foreach ( self::SCALAR_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$value = $input[ $field ];
			if ( in_array( $field, array( 'rayon_km', 'statut_visible', 'photo' ), true ) ) {
				$data[ $field ]  = null === $value ? null : (int) $value;
				$formats[]       = '%d';
			} else {
				$data[ $field ] = null === $value ? null : sanitize_text_field( (string) $value );
				$formats[]      = '%s';
				if ( 'a_propos' === $field && null !== $value ) {
					$data[ $field ] = sanitize_textarea_field( (string) $value );
				}
			}
		}

		foreach ( self::JSON_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$data[ $field ] = null === $input[ $field ] ? null : wp_json_encode( $input[ $field ] );
			$formats[]      = '%s';
		}

		$data['date_maj'] = current_time( 'mysql', true );
		$formats[]        = '%s';

		$wpdb->update( self::table(), $data, array( 'user_id' => $user_id ), $formats, array( '%d' ) );

		return $this->get_by_user( $user_id );
	}

	public function delete_for( int $user_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/**
	 * Vue destinée à un recruteur : respecte `profile_visibility` et masque les
	 * coordonnées selon `visibility` (email/tel). Ne renvoie jamais de données
	 * brutes sensibles non autorisées.
	 *
	 * @param array<string, mixed> $profile
	 * @return array<string, mixed>
	 */
	public static function recruiter_view( array $profile ): array {
		$visibility = is_array( $profile['visibility'] ?? null ) ? $profile['visibility'] : array();
		$tel_ok     = ! empty( $visibility['tel'] );

		$view = $profile;
		unset( $view['blocked_companies'] );

		// N'expose PAS les identifiants internes : l'UUID public est la seule référence.
		unset( $view['id'], $view['user_id'] );

		if ( ! $tel_ok ) {
			$view['telephone'] = null;
		}
		return $view;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function decode( array $row ): array {
		foreach ( self::JSON_FIELDS as $field ) {
			if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) && '' !== $row[ $field ] ) {
				$decoded       = json_decode( $row[ $field ], true );
				$row[ $field ] = null === $decoded ? null : $decoded;
			} else {
				$row[ $field ] = null;
			}
		}
		$row['id']             = (int) $row['id'];
		$row['user_id']        = (int) $row['user_id'];
		$row['statut_visible'] = (int) $row['statut_visible'];
		$row['photo']          = (int) $row['photo'];
		return $row;
	}
}
