<?php
/**
 * Registre des routes du Site Builder. Depuis la Phase 2, il N'Y A PLUS de second menu de premier
 * niveau : les écrans du site sont intégrés dans l'unique menu « Postelio » (voir Menu.php). Cette
 * classe ne fournit plus que la correspondance slug ↔ page et les listes de pages (helpers
 * statiques utilisés par le menu et par l'enqueue des assets).
 *
 * @package Postelio\Admin\Site
 */

namespace Postelio\Admin\Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteMenu {

	public const PARENT = 'postelio-site'; // slug de l'écran « Accueil » (page d'entrée du site)
	public const CAP    = 'pst_manage_site';

	/** Toutes les pages d'éditeur (clé => libellé). */
	public const PAGES = array(
		'home'       => 'Accueil',
		'navigation' => 'Navigation',
		'footer'     => 'Footer',
		'appearance' => 'Apparence',
		'seo'        => 'SEO',
		'jobs'       => 'Offres',
		'companies'  => 'Entreprises',
		'skills'     => 'Savoir-faire',
		'advice'     => 'Conseils',
		'contact'    => 'Contact',
	);

	/** Pages visibles directement sous « Mon site » dans le menu. */
	public const MENU_VISIBLE = array( 'home', 'navigation', 'footer', 'appearance', 'seo' );

	/** Pages de contenu accessibles via le hub « Pages & contenus » (routables mais masquées du menu). */
	public const HUB_PAGES = array( 'home', 'jobs', 'companies', 'skills', 'advice', 'contact' );

	/** Slug WordPress d'une page (Accueil = slug parent historique). */
	public static function slug_for( string $page ): string {
		return 'home' === $page ? self::PARENT : self::PARENT . '-' . $page;
	}

	/** Clé de page pour un slug donné (ou null si ce n'est pas un écran d'éditeur Site). */
	public static function page_for_slug( string $slug ): ?string {
		if ( self::PARENT === $slug ) {
			return 'home';
		}
		$prefix = self::PARENT . '-';
		if ( 0 === strpos( $slug, $prefix ) ) {
			$key = substr( $slug, strlen( $prefix ) );
			return isset( self::PAGES[ $key ] ) ? $key : null;
		}
		return null;
	}
}
