<?php
/**
 * Préférences de notification par utilisateur. Stockage V1 = `user_meta` (document JSON
 * versionné) — pas de table dédiée. Le SERVEUR reste autoritaire : catalogue des
 * catégories, valeurs par défaut, séparation transactionnel/marketing. Le client ne peut
 * pas modifier une catégorie hors de son rôle ; les types marqués « obligatoires » (côté
 * Router) ignorent totalement ces préférences.
 *
 * @package Postelio\Notifications\Notifications
 */

namespace Postelio\Notifications\Notifications;

use Postelio\Users\Api\UserDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PreferenceService {

	public const META_KEY = 'pst_notification_prefs';
	public const VERSION  = 1;

	/**
	 * Catalogue des catégories : rôles concernés, nature (marketing ?) et défauts par canal.
	 *
	 * @return array<string, array{roles:string[], marketing:bool, in_app:bool, email:bool, label:string}>
	 */
	public static function catalog(): array {
		$catalog = array(
			'messages'           => array( 'roles' => array( 'candidate', 'recruiter' ), 'marketing' => false, 'in_app' => true, 'email' => true, 'label' => 'Nouveaux messages' ),
			'application_status' => array( 'roles' => array( 'candidate' ), 'marketing' => false, 'in_app' => true, 'email' => true, 'label' => 'Suivi de mes candidatures' ),
			'interviews'         => array( 'roles' => array( 'candidate', 'recruiter' ), 'marketing' => false, 'in_app' => true, 'email' => true, 'label' => 'Entretiens' ),
			'new_applications'   => array( 'roles' => array( 'recruiter' ), 'marketing' => false, 'in_app' => true, 'email' => true, 'label' => 'Nouvelles candidatures' ),
			'job_expiration'     => array( 'roles' => array( 'recruiter' ), 'marketing' => false, 'in_app' => true, 'email' => true, 'label' => 'Expiration des offres' ),
			'company'            => array( 'roles' => array( 'recruiter' ), 'marketing' => false, 'in_app' => true, 'email' => true, 'label' => 'Entreprise & vérification' ),
			'offers_reco'        => array( 'roles' => array( 'candidate' ), 'marketing' => true, 'in_app' => true, 'email' => false, 'label' => 'Offres recommandées' ),
			'news'               => array( 'roles' => array( 'candidate' ), 'marketing' => true, 'in_app' => true, 'email' => false, 'label' => 'Conseils Postelio' ),
			'newsletter'         => array( 'roles' => array( 'recruiter' ), 'marketing' => true, 'in_app' => false, 'email' => false, 'label' => 'Lettre d\'information recruteurs' ),
		);
		// Extensible : les modules métier (ex. postelio-alerts) ajoutent leurs catégories via ce
		// filtre — Notifications n'a AUCUNE dépendance en dur envers eux. Le serveur reste
		// autoritaire : seules les entrées bien formées (roles/in_app/email/label) sont conservées.
		if ( function_exists( 'apply_filters' ) ) {
			$extended = apply_filters( 'postelio/notifications/categories', $catalog );
			if ( is_array( $extended ) ) {
				$catalog = self::sanitize_catalog( $extended, $catalog );
			}
		}
		return $catalog;
	}

	/**
	 * Neutralise un catalogue étendu : garde les entrées bien formées, force les types, empêche
	 * un module tiers de casser la structure attendue par le reste du système.
	 *
	 * @param array<string, mixed> $extended
	 * @param array<string, array<string, mixed>> $base
	 * @return array<string, array{roles:string[], marketing:bool, in_app:bool, email:bool, label:string}>
	 */
	private static function sanitize_catalog( array $extended, array $base ): array {
		$out = array();
		foreach ( $extended as $key => $c ) {
			if ( ! is_string( $key ) || '' === $key || ! is_array( $c ) ) {
				continue;
			}
			if ( ! isset( $c['roles'] ) || ! is_array( $c['roles'] ) || ! isset( $c['label'] ) ) {
				continue;
			}
			$roles = array_values( array_intersect( array_map( 'strval', $c['roles'] ), array( 'candidate', 'recruiter' ) ) );
			if ( empty( $roles ) ) {
				continue;
			}
			$out[ $key ] = array(
				'roles'     => $roles,
				'marketing' => (bool) ( $c['marketing'] ?? false ),
				'in_app'    => (bool) ( $c['in_app'] ?? true ),
				'email'     => (bool) ( $c['email'] ?? false ),
				'label'     => (string) $c['label'],
			);
		}
		// Les catégories de base ne peuvent jamais être supprimées/écrasées par un tiers.
		foreach ( $base as $key => $c ) {
			$out[ $key ] = $c;
		}
		return $out;
	}

	private function role( int $user_id ): string {
		$r = UserDirectory::role( $user_id );
		return in_array( $r, array( 'candidate', 'recruiter' ), true ) ? $r : 'candidate';
	}

	/** Catégories disponibles pour le rôle de l'utilisateur. @return string[] */
	public function categories_for( int $user_id ): array {
		$role = $this->role( $user_id );
		$out  = array();
		foreach ( self::catalog() as $key => $c ) {
			if ( in_array( $role, $c['roles'], true ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Préférences résolues (défauts + surcharges utilisateur), restreintes à son rôle.
	 *
	 * @return array{version:int, categories: array<string, array{in_app:bool, email:bool, marketing:bool, label:string}>}
	 */
	public function resolved( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META_KEY, true );
		$stored = is_array( $stored ) ? $stored : array();
		$cats   = isset( $stored['categories'] ) && is_array( $stored['categories'] ) ? $stored['categories'] : array();

		$out = array();
		foreach ( self::catalog() as $key => $c ) {
			if ( ! in_array( $this->role( $user_id ), $c['roles'], true ) ) {
				continue;
			}
			$out[ $key ] = array(
				'in_app'    => isset( $cats[ $key ]['in_app'] ) ? (bool) $cats[ $key ]['in_app'] : $c['in_app'],
				'email'     => isset( $cats[ $key ]['email'] ) ? (bool) $cats[ $key ]['email'] : $c['email'],
				'marketing' => $c['marketing'],
				'label'     => $c['label'],
			);
		}
		return array( 'version' => self::VERSION, 'categories' => $out );
	}

	/** L'utilisateur autorise-t-il ce canal pour cette catégorie ? (défaut si non défini) */
	public function allows( int $user_id, string $category, string $channel ): bool {
		$resolved = $this->resolved( $user_id );
		if ( ! isset( $resolved['categories'][ $category ] ) ) {
			return false; // catégorie hors rôle → pas de canal
		}
		return (bool) ( $resolved['categories'][ $category ][ $channel ] ?? false );
	}

	/**
	 * Applique une mise à jour partielle. Ignore les catégories hors rôle et les canaux
	 * inconnus. Retourne les préférences résolues après écriture.
	 *
	 * @param array<string, mixed> $input  { categories: { cat: { in_app?:bool, email?:bool } } }
	 * @return array<string, mixed>
	 */
	public function update( int $user_id, array $input ): array {
		$allowed_cats = $this->categories_for( $user_id );
		$stored       = get_user_meta( $user_id, self::META_KEY, true );
		$stored       = is_array( $stored ) ? $stored : array();
		$cats         = isset( $stored['categories'] ) && is_array( $stored['categories'] ) ? $stored['categories'] : array();

		$incoming = isset( $input['categories'] ) && is_array( $input['categories'] ) ? $input['categories'] : array();
		foreach ( $incoming as $key => $channels ) {
			if ( ! in_array( $key, $allowed_cats, true ) || ! is_array( $channels ) ) {
				continue;
			}
			foreach ( array( 'in_app', 'email' ) as $ch ) {
				if ( array_key_exists( $ch, $channels ) ) {
					$cats[ $key ][ $ch ] = (bool) $channels[ $ch ];
				}
			}
		}
		update_user_meta( $user_id, self::META_KEY, array( 'version' => self::VERSION, 'categories' => $cats ) );
		return $this->resolved( $user_id );
	}
}
