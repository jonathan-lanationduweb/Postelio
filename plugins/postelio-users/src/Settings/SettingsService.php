<?php
/**
 * Préférences de base de l'utilisateur (notifications, langue).
 *
 * La visibilité des coordonnées candidat vit dans le profil candidat
 * (`visibility`/`profile_visibility`) ; ici on ne gère que les préférences
 * transversales de compte, stockées en usermeta.
 *
 * @package Postelio\Users\Settings
 */

namespace Postelio\Users\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class SettingsService {

	public const META_KEY = 'postelio_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'langue'        => 'fr',
			'notifications' => array(
				'nouvelles_offres'      => true,
				'changement_statut'     => true,
				'nouveau_message'       => true,
				'proposition_entretien' => true,
				'rappels'               => true,
				'conseils'              => false,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META_KEY, true );
		$stored = is_array( $stored ) ? $stored : array();
		return $this->merge( self::defaults(), $stored );
	}

	/**
	 * Met à jour les préférences (fusion + whitelist). Retourne l'état complet.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function update( int $user_id, array $input ): array {
		$current  = $this->get( $user_id );
		$defaults = self::defaults();

		if ( isset( $input['langue'] ) ) {
			$langue            = sanitize_key( (string) $input['langue'] );
			$current['langue'] = in_array( $langue, array( 'fr', 'en' ), true ) ? $langue : $current['langue'];
		}

		if ( isset( $input['notifications'] ) && is_array( $input['notifications'] ) ) {
			foreach ( array_keys( $defaults['notifications'] ) as $key ) {
				if ( array_key_exists( $key, $input['notifications'] ) ) {
					$current['notifications'][ $key ] = (bool) $input['notifications'][ $key ];
				}
			}
		}

		update_user_meta( $user_id, self::META_KEY, $current );
		return $current;
	}

	/**
	 * Fusion superficielle défaut ← stocké (2 niveaux suffisent ici).
	 *
	 * @param array<string, mixed> $defaults
	 * @param array<string, mixed> $stored
	 * @return array<string, mixed>
	 */
	private function merge( array $defaults, array $stored ): array {
		$out = $defaults;
		foreach ( $stored as $key => $value ) {
			if ( isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && is_array( $value ) ) {
				$out[ $key ] = array_merge( $defaults[ $key ], $value );
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}
