<?php
/**
 * Navigation secondaire de « Mon site » (comme les onglets internes de la référence vidéo LNDW).
 * Le menu WordPress n'expose qu'UNE entrée « Mon site » ; cette barre permet de passer entre
 * Vue d'ensemble / Accueil / Navigation / Footer / Apparence / SEO sans multiplier les entrées.
 *
 * @package Postelio\Admin\Site
 */

namespace Postelio\Admin\Site;

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

	/** Éditeurs de pages de contenu → rattachés à « Vue d'ensemble ». */
	private const CONTENT = array(
		'postelio-site-jobs', 'postelio-site-companies', 'postelio-site-skills',
		'postelio-site-advice', 'postelio-site-contact',
	);

	/** Barre de navigation « Mon site » (HTML échappé), avec l'onglet actif surligné. */
	public static function render( string $active_slug ): string {
		if ( in_array( $active_slug, self::CONTENT, true ) ) {
			$active_slug = 'postelio-site-pages';
		}
		$h = '<nav class="pst-sitenav" aria-label="Mon site">';
		foreach ( self::ITEMS as $slug => $label ) {
			$cls = 'pst-sitenav__link' . ( $slug === $active_slug ? ' is-active' : '' );
			$h  .= '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}
		return $h . '</nav>';
	}
}
