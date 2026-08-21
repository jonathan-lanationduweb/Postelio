<?php
/**
 * Autoloader PSR-4 minimal (aucune dépendance Composer requise).
 *
 * @package Postelio\Core
 */

namespace Postelio\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Autoloader {

	/**
	 * Enregistre un préfixe de namespace vers un répertoire de base.
	 *
	 * @param string $prefix   Préfixe de namespace, ex. "Postelio\\Core\\".
	 * @param string $base_dir Répertoire racine des sources correspondantes.
	 */
	public static function register( string $prefix, string $base_dir ): void {
		// Normalise : exactement un backslash final, quel que soit l'appelant.
		$prefix   = rtrim( ltrim( $prefix, '\\' ), '\\' ) . '\\';
		$base_dir = rtrim( $base_dir, '/\\' ) . '/';

		spl_autoload_register(
			static function ( string $class ) use ( $prefix, $base_dir ): void {
				$class = ltrim( $class, '\\' );
				$len   = strlen( $prefix );

				// Ne traite que les classes du préfixe géré.
				if ( 0 !== strncmp( $class, $prefix, $len ) ) {
					return;
				}

				$relative = substr( $class, $len );
				$path     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_file( $path ) ) {
					require $path;
				}
			}
		);
	}
}
