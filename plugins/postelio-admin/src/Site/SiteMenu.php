<?php
/**
 * Menu de premier niveau « Postelio Site » : l'éditeur visuel du site public, DISTINCT du menu de
 * gestion « Postelio » (métier). Chaque entrée ouvre un écran d'éditeur (SiteEditorPage) pour une
 * page de configuration. Réservé à la capacité `pst_manage_site` (admin).
 *
 * @package Postelio\Admin\Site
 */

namespace Postelio\Admin\Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteMenu {

	public const PARENT = 'postelio-site';
	public const CAP    = 'pst_manage_site';

	/**
	 * Pages du builder, dans l'ordre du menu. [ pageKey => libellé ]. Phase 1 : les 4 premières
	 * sont complètes ; les suivantes sont préparées.
	 *
	 * @var array<string,string>
	 */
	private const PAGES = array(
		'home'       => 'Accueil',
		'navigation' => 'Navigation',
		'footer'     => 'Footer',
		'appearance' => 'Apparence',
		'jobs'       => 'Offres',
		'companies'  => 'Entreprises',
		'skills'     => 'Savoir-faire',
		'blog'       => 'Conseils / Blog',
		'contact'    => 'Contact',
		'seo'        => 'SEO',
	);

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'build' ) );
	}

	/** Slug WordPress d'une page du builder (la page d'accueil = slug parent). */
	public static function slug_for( string $page ): string {
		return 'home' === $page ? self::PARENT : self::PARENT . '-' . $page;
	}

	/** Clé de page pour un slug WordPress donné (ou null si ce n'est pas un écran Site). */
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

	public function build(): void {
		add_menu_page(
			'Postelio Site',
			'Postelio Site',
			self::CAP,
			self::PARENT,
			array( new SiteEditorPage( 'home' ), 'render' ),
			self::icon(),
			4
		);

		foreach ( self::PAGES as $page => $label ) {
			add_submenu_page(
				self::PARENT,
				'Postelio Site — ' . $label,
				$label,
				self::CAP,
				self::slug_for( $page ),
				array( new SiteEditorPage( $page ), 'render' )
			);
		}
	}

	/** Icône du menu (SVG data-URI — pinceau / mise en page, gris admin). */
	private static function icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M3 3h14a1 1 0 0 1 1 1v3H2V4a1 1 0 0 1 1-1Zm-1 6h6v8H3a1 1 0 0 1-1-1V9Zm8 0h9v7a1 1 0 0 1-1 1h-8V9Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
