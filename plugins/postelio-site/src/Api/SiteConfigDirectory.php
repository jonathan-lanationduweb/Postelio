<?php
/**
 * Contrat de LECTURE de la configuration du site (consommé par l'éditeur admin, l'aperçu et l'API
 * REST publique — et, plus tard, par le front public). Lecture seule. Ne renvoie que de la
 * configuration de présentation ; aucune donnée admin interne.
 *
 * @package Postelio\Site\Api
 */

namespace Postelio\Site\Api;

use Postelio\Site\Config\SiteConfigRepository;
use Postelio\Site\Config\SiteSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteConfigDirectory {

	public static function version(): int {
		return SiteSchema::VERSION;
	}

	/** @return string[] */
	public static function pages(): array {
		return SiteSchema::pages();
	}

	public static function has_page( string $page ): bool {
		return SiteSchema::has_page( $page );
	}

	/** @return array<string,mixed>|null Définition de schéma d'une page. */
	public static function schema( string $page ): ?array {
		return SiteSchema::page( $page );
	}

	/** @return array<string,mixed> Valeurs (fusionnées sur défauts) d'une page. */
	public static function config( string $page ): array {
		return ( new SiteConfigRepository() )->get( $page );
	}

	/** @return array<string,array<string,mixed>> Toutes les pages { page => valeurs }. */
	public static function all(): array {
		return ( new SiteConfigRepository() )->all();
	}

	/**
	 * Charge utile publique pour le front : version + valeurs de toutes les pages.
	 * (Tout est de la présentation ; rien de sensible.)
	 *
	 * @return array<string,mixed>
	 */
	public static function public_config(): array {
		return array(
			'version' => SiteSchema::VERSION,
			'pages'   => self::all(),
		);
	}
}
