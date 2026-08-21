<?php
/**
 * Registry des modules Postelio.
 *
 * Chaque plugin métier se déclare ici (nom, version, dépendances, ordre de
 * chargement). Le core refuse l'ordre de démarrage si une dépendance manque.
 * Classe volontairement SANS appel à WordPress → testable en isolation.
 *
 * @package Postelio\Core
 */

namespace Postelio\Core;

if ( ! defined( 'ABSPATH' ) ) {
	// Autorisé hors WordPress uniquement pour les tests unitaires headless.
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class Registry {

	/** @var array<string, array{version:string, requires:string[], load_order:int, text_domain:?string}> */
	private array $modules = array();

	/**
	 * Déclare un module.
	 *
	 * @param string               $name Nom court unique (ex. "jobs").
	 * @param array<string, mixed> $meta version, requires[], load_order, text_domain.
	 *
	 * @throws \InvalidArgumentException Si le nom est vide ou déjà enregistré.
	 */
	public function register( string $name, array $meta = array() ): void {
		$name = trim( $name );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'Nom de module vide.' );
		}
		if ( isset( $this->modules[ $name ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Module "%s" déjà enregistré.', $name ) );
		}

		$this->modules[ $name ] = array(
			'version'     => (string) ( $meta['version'] ?? '0.0.0' ),
			'requires'    => array_values( array_map( 'strval', (array) ( $meta['requires'] ?? array() ) ) ),
			'load_order'  => (int) ( $meta['load_order'] ?? 100 ),
			'text_domain' => isset( $meta['text_domain'] ) ? (string) $meta['text_domain'] : null,
		);
	}

	public function has( string $name ): bool {
		return isset( $this->modules[ $name ] );
	}

	/**
	 * @return array{version:string, requires:string[], load_order:int, text_domain:?string}|null
	 */
	public function get( string $name ): ?array {
		return $this->modules[ $name ] ?? null;
	}

	/**
	 * @return array<string, array{version:string, requires:string[], load_order:int, text_domain:?string}>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * Dépendances déclarées mais non enregistrées, par module.
	 *
	 * @return array<string, string[]>
	 */
	public function missing_dependencies(): array {
		$missing = array();
		foreach ( $this->modules as $name => $meta ) {
			foreach ( $meta['requires'] as $dep ) {
				if ( ! isset( $this->modules[ $dep ] ) ) {
					$missing[ $name ][] = $dep;
				}
			}
		}
		return $missing;
	}

	/**
	 * Ordre de démarrage : tri topologique (dépendances d'abord), départage par
	 * load_order puis par nom. Refuse les cycles.
	 *
	 * @return string[]
	 *
	 * @throws \RuntimeException Si une dépendance manque ou si un cycle est détecté.
	 */
	public function boot_order(): array {
		$missing = $this->missing_dependencies();
		if ( ! empty( $missing ) ) {
			$first = array_key_first( $missing );
			throw new \RuntimeException(
				sprintf( 'Dépendance manquante pour "%s" : %s.', $first, implode( ', ', $missing[ $first ] ) )
			);
		}

		$order    = array();
		$resolved = array();
		$visiting = array();

		// Ordre déterministe d'entrée : load_order puis nom.
		$names = array_keys( $this->modules );
		usort(
			$names,
			function ( string $a, string $b ): int {
				$cmp = $this->modules[ $a ]['load_order'] <=> $this->modules[ $b ]['load_order'];
				return 0 !== $cmp ? $cmp : strcmp( $a, $b );
			}
		);

		$visit = function ( string $name ) use ( &$visit, &$order, &$resolved, &$visiting ): void {
			if ( isset( $resolved[ $name ] ) ) {
				return;
			}
			if ( isset( $visiting[ $name ] ) ) {
				throw new \RuntimeException( sprintf( 'Cycle de dépendances détecté impliquant "%s".', $name ) );
			}
			$visiting[ $name ] = true;

			$requires = $this->modules[ $name ]['requires'];
			usort(
				$requires,
				function ( string $a, string $b ): int {
					$cmp = $this->modules[ $a ]['load_order'] <=> $this->modules[ $b ]['load_order'];
					return 0 !== $cmp ? $cmp : strcmp( $a, $b );
				}
			);
			foreach ( $requires as $dep ) {
				$visit( $dep );
			}

			unset( $visiting[ $name ] );
			$resolved[ $name ] = true;
			$order[]           = $name;
		};

		foreach ( $names as $name ) {
			$visit( $name );
		}

		return $order;
	}
}
