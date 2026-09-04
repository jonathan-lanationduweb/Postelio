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
	 * IDENTITÉ GLOBALE RÉSOLUE (Apparence → Identité) : nom de marque, logo, favicon en URLs
	 * ABSOLUES. Unique source de vérité pour tout ce que WordPress rend lui-même (favicon de
	 * wp-admin / login via `get_site_icon_url`) et pour le futur branchement du front public.
	 * Le favicon retombe toujours sur le favicon Postelio validé (SiteSchema::DEFAULT_FAVICON).
	 *
	 * @return array{brand_name:string, logo_url:string, logo_light_url:string, favicon_url:string, favicon_is_default:bool}
	 */
	public static function identity(): array {
		$a       = self::config( 'appearance' );
		$favicon = trim( (string) ( $a['favicon'] ?? '' ) );
		if ( '' === $favicon ) {
			$favicon = SiteSchema::DEFAULT_FAVICON;
		}
		$brand = trim( (string) ( $a['brand_name'] ?? '' ) );
		return array(
			'brand_name'         => '' !== $brand ? $brand : 'Postelio',
			'logo_url'           => self::absolute_media( (string) ( $a['logo'] ?? '' ) ),
			'logo_light_url'     => self::absolute_media( (string) ( $a['logo_light'] ?? '' ) ),
			'favicon_url'        => self::absolute_media( $favicon ),
			'favicon_is_default' => SiteSchema::DEFAULT_FAVICON === $favicon,
		);
	}

	/**
	 * Origine du FRONT public (schéma + hôte [+ port]). Le front statique est servi à la racine de
	 * l'origine de WordPress (ex. http://postelio.local/ pour http://postelio.local/wordpress/).
	 * Filtrable (`postelio/site/front_origin`) pour un déploiement où le front vit ailleurs.
	 */
	public static function front_origin(): string {
		$p      = wp_parse_url( home_url( '/' ) );
		$origin = ( $p['scheme'] ?? 'http' ) . '://' . ( $p['host'] ?? 'localhost' ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
		return (string) apply_filters( 'postelio/site/front_origin', $origin );
	}

	/** Valeur de champ média (URL absolue, chemin racine ou id d'attachement) → URL absolue ou ''. */
	private static function absolute_media( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ctype_digit( $value ) ) {
			$url = wp_get_attachment_url( (int) $value );
			return is_string( $url ) ? $url : '';
		}
		if ( '/' === $value[0] ) {
			return self::front_origin() . $value;
		}
		return preg_match( '#^https?://#i', $value ) ? $value : '';
	}

	/**
	 * Charge utile publique pour le front : version + valeurs de toutes les pages.
	 * (Tout est de la présentation ; rien de sensible.)
	 *
	 * @return array<string,mixed>
	 */
	public static function public_config(): array {
		return array(
			'version'  => SiteSchema::VERSION,
			'identity' => self::identity(),
			'pages'    => self::all(),
		);
	}
}
