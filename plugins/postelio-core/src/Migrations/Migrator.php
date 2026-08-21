<?php
/**
 * Framework de migrations DB par module.
 *
 * Chaque module déclare une option `postelio_<module>_schema` stockant la version
 * de schéma installée, et une liste de migrations ordonnées. `migrate()` exécute
 * les migrations dont la version dépasse celle installée. Idempotent.
 *
 * @package Postelio\Core\Migrations
 */

namespace Postelio\Core\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Migrator {

	/** @var array<string, array{option:string, migrations: Migration[]}> */
	private array $modules = array();

	/**
	 * Déclare les migrations d'un module.
	 *
	 * @param string      $module     Nom court (ex. "core", "jobs").
	 * @param string      $option     Nom de l'option de version de schéma.
	 * @param Migration[] $migrations Migrations (seront triées par version).
	 */
	public function register( string $module, string $option, array $migrations ): void {
		usort(
			$migrations,
			static fn( Migration $a, Migration $b ): int => version_compare( $a->version(), $b->version() )
		);
		$this->modules[ $module ] = array(
			'option'     => $option,
			'migrations' => $migrations,
		);
	}

	/**
	 * Version cible (la plus haute déclarée) d'un module. "0" si aucune.
	 */
	public function target_version( string $module ): string {
		$migrations = $this->modules[ $module ]['migrations'] ?? array();
		if ( empty( $migrations ) ) {
			return '0';
		}
		return $migrations[ count( $migrations ) - 1 ]->version();
	}

	public function installed_version( string $module ): string {
		$option = $this->modules[ $module ]['option'] ?? '';
		if ( '' === $option ) {
			return '0';
		}
		return (string) get_option( $option, '0' );
	}

	/**
	 * Exécute les migrations en attente d'un module. Retourne le nombre appliqué.
	 */
	public function migrate( string $module ): int {
		if ( ! isset( $this->modules[ $module ] ) ) {
			return 0;
		}
		$option    = $this->modules[ $module ]['option'];
		$installed = (string) get_option( $option, '0' );
		$applied   = 0;

		foreach ( $this->modules[ $module ]['migrations'] as $migration ) {
			if ( version_compare( $migration->version(), $installed, '>' ) ) {
				$migration->up();
				$installed = $migration->version();
				update_option( $option, $installed, false );
				++$applied;
			}
		}

		return $applied;
	}

	/**
	 * Charge-t-il de quoi calculer le charset/collation de la base ?
	 * Helper commun aux migrations (dbDelta).
	 */
	public static function charset_collate(): string {
		global $wpdb;
		return $wpdb->get_charset_collate();
	}
}
