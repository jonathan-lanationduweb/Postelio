<?php
/**
 * Garde de permissions transversale.
 *
 * `can()` s'appuie sur les capabilities WordPress (current_user_can). Les plugins
 * métier fournissent EN PLUS le contrôle de propriété (company_id, ownership)
 * — non implémenté ici (transversal uniquement).
 *
 * @package Postelio\Core\Permissions
 */

namespace Postelio\Core\Permissions;

use Postelio\Core\Errors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Guard {

	/**
	 * L'utilisateur courant possède-t-il la capability ?
	 *
	 * @param mixed ...$args Arguments contextuels transmis à current_user_can.
	 */
	public static function can( string $capability, ...$args ): bool {
		return current_user_can( $capability, ...$args );
	}

	public static function is_authenticated(): bool {
		return is_user_logged_in();
	}

	/**
	 * Fabrique un `permission_callback` REST exigeant une capability.
	 * Retourne un WP_Error normalisé (401/403) en cas de refus.
	 *
	 * @return callable
	 */
	public static function require_cap( string $capability ): callable {
		return static function () use ( $capability ) {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error(
					'unauthenticated',
					__( 'Authentification requise.', 'postelio-core' ),
					array( 'status' => Errors::http_status( 'unauthenticated' ) )
				);
			}
			if ( ! current_user_can( $capability ) ) {
				return new \WP_Error(
					'forbidden',
					__( 'Action non autorisée.', 'postelio-core' ),
					array( 'status' => Errors::http_status( 'forbidden' ) )
				);
			}
			return true;
		};
	}

	/**
	 * Fabrique un `permission_callback` REST exigeant PLUSIEURS capabilities.
	 *
	 * Primitive générique de composition (le core ignore la sémantique métier des
	 * capabilities). Ex. côté plugin métier :
	 *   Guard::require_all( 'pst_apply_job', 'pst_email_verified' )
	 * pour n'autoriser l'action qu'à un candidat dont l'e-mail est vérifié.
	 *
	 * @return callable
	 */
	public static function require_all( string ...$capabilities ): callable {
		return static function () use ( $capabilities ) {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error(
					'unauthenticated',
					__( 'Authentification requise.', 'postelio-core' ),
					array( 'status' => Errors::http_status( 'unauthenticated' ) )
				);
			}
			foreach ( $capabilities as $capability ) {
				if ( ! current_user_can( $capability ) ) {
					return new \WP_Error(
						'forbidden',
						__( 'Action non autorisée.', 'postelio-core' ),
						array( 'status' => Errors::http_status( 'forbidden' ) )
					);
				}
			}
			return true;
		};
	}

	/**
	 * `permission_callback` public (accès non authentifié autorisé).
	 *
	 * @return callable
	 */
	public static function public_access(): callable {
		return static function () {
			return true;
		};
	}
}
