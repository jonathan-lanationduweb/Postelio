<?php
/**
 * Navigation interne de « Mon site » : Vue d'ensemble · Accueil · Navigation · Footer · Apparence ·
 * SEO. Les éditeurs de pages de contenu (Offres, Entreprises…) sont rattachés à la Vue d'ensemble.
 *
 * @package Postelio\Backoffice\Screens\Site
 */

namespace Postelio\Backoffice\Screens\Site;

use Postelio\Backoffice\Menu;
use Postelio\Backoffice\Ui\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteNav {

	/** @var array<string,string> slug => libellé (ordre d'affichage). */
	private const ITEMS = array(
		'postelio-site-pages'      => 'Vue d\'ensemble',
		'postelio-site'            => 'Accueil',
		'postelio-site-navigation' => 'Navigation',
		'postelio-site-footer'     => 'Footer',
		'postelio-site-appearance' => 'Apparence',
		'postelio-site-seo'        => 'SEO',
	);

	public static function render( string $active_slug ): string {
		if ( ! isset( self::ITEMS[ $active_slug ] ) ) {
			$active_slug = 'postelio-site-pages'; // éditeurs de contenu → Vue d'ensemble
		}
		$tabs = array();
		foreach ( self::ITEMS as $slug => $label ) {
			$tabs[] = array( 'label' => $label, 'url' => add_query_arg( array( 'page' => $slug ), admin_url( 'admin.php' ) ), 'active' => $slug === $active_slug );
		}
		return Ui::tabs( $tabs, 'Mon site' );
	}

	public static function label( string $page ): string {
		return Menu::SITE_PAGES[ $page ] ?? ucfirst( $page );
	}
}
