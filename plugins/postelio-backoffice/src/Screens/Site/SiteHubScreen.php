<?php
/**
 * « Mon site » — Vue d'ensemble : page de pilotage du site public. Liste des pages avec une miniature
 * graphique du type de page (représentation abstraite de la mise en page : aucune capture inventée),
 * nom, résumé, état des sections, état SEO, Modifier / actions ; structure (Navigation, Footer) et
 * identité globale (nom, logo, favicon). Lecture via le contrat postelio-site ; aucune écriture.
 *
 * @package Postelio\Backoffice\Screens\Site
 */

namespace Postelio\Backoffice\Screens\Site;

use Postelio\Backoffice\Menu;
use Postelio\Backoffice\Screens\Screen;
use Postelio\Backoffice\Ui\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteHubScreen extends Screen {

	private const DIR = '\\Postelio\\Site\\Api\\SiteConfigDirectory';

	/** @var array<string,string> Résumés courts par page. */
	private const SUMMARIES = array(
		'home'      => 'Hero vidéo, recherche, sélections et appels à l\'action.',
		'jobs'      => 'Hero, recherche, filtres et liste des offres.',
		'companies' => 'Annuaire des entreprises : hero, recherche, mises en avant.',
		'skills'    => 'Savoir-faire : hero, catégories, contenus mis en avant.',
		'advice'    => 'Conseils : hero, catégories, articles mis en avant.',
		'contact'   => 'Coordonnées et formulaire de contact.',
	);

	protected function capability(): string {
		return Menu::CAP_SITE;
	}

	protected function eyebrow(): string {
		return 'Postelio · Mon site';
	}

	protected function body(): string {
		$out  = $this->header( 'Mon site', 'Pages, structure et identité du site public.', Ui::button( 'Voir le site', $this->front_origin() . '/', '', false, true ) );
		$out .= SiteNav::render( 'postelio-site-pages' );

		if ( ! class_exists( self::DIR ) ) {
			return $out . Ui::empty_state( 'Module Site indisponible', 'Activez le plugin Postelio Site pour éditer le site.', '', 'build', true );
		}

		$seo = (array) call_user_func( array( self::DIR, 'config' ), 'seo' );

		$out .= '<div class="bo-grid bo-grid--main">';
		$out .= '<div class="bo-col">';
		// --- Pages -----------------------------------------------------------
		$out .= Ui::card_open( 'Pages du site', 'Chaque page est composée de sections activables et réordonnables.', '', '', 'Contenu' ) . Ui::rows_open();
		foreach ( Menu::SITE_HUB_PAGES as $page ) {
			$out .= $this->page_row( $page, $seo );
		}
		$out .= Ui::rows_close() . Ui::card_close();
		$out .= '</div><div class="bo-col">';

		// --- Structure + identité ---------------------------------------------
		$out .= Ui::card_open( 'Structure', 'En-tête et pied de page, communs à toutes les pages.', '', '', 'Global' ) . Ui::rows_open();
		$out .= Ui::row( esc_html( 'Navigation' ), 'Logo, liens du menu, boutons Connexion / Inscription.', '', Ui::button( 'Modifier', $this->url( Menu::site_slug( 'navigation' ) ), '', true ), '', self::thumb( 'navigation' ) );
		$out .= Ui::row( esc_html( 'Footer' ), 'Marque, colonnes de liens, réseaux sociaux, mentions.', '', Ui::button( 'Modifier', $this->url( Menu::site_slug( 'footer' ) ), '', true ), '', self::thumb( 'footer' ) );
		$out .= Ui::rows_close() . Ui::card_close();
		$out .= $this->identity_card();
		$out .= '</div></div>';

		return $out;
	}

	/**
	 * Miniature graphique du type de page : représentation abstraite de la mise en page (barres et
	 * blocs), jamais une capture inventée. Purement décorative pour les lecteurs d'écran.
	 */
	public static function thumb( string $kind ): string {
		$kind = (string) preg_replace( '/[^a-z]/', '', $kind );
		return '<span class="bo-thumb bo-thumb--' . esc_attr( $kind ) . '" aria-hidden="true"><i></i><i></i><i></i><i></i></span>';
	}

	/** @param array<string,mixed> $seo */
	private function page_row( string $page, array $seo ): string {
		$schema = (array) call_user_func( array( self::DIR, 'schema' ), $page );
		$config = (array) call_user_func( array( self::DIR, 'config' ), $page );

		$active = 0;
		$total  = 0;
		if ( 'sections' === ( $schema['type'] ?? '' ) ) {
			foreach ( (array) ( $schema['sections'] ?? array() ) as $skey => $sdef ) {
				$total++;
				if ( ! empty( $config[ $skey ]['_enabled'] ) ) {
					$active++;
				}
			}
		}
		$state = $total > 0
			? Ui::badge( $active . '/' . $total . ' sections', $active > 0 ? 'success' : 'neutral', true )
			: Ui::badge( 'Configurée', 'success', true );

		$page_seo = is_array( $seo[ $page ] ?? null ) ? $seo[ $page ] : array();
		$has_seo  = '' !== trim( (string) ( $page_seo['seo_title'] ?? '' ) ) || '' !== trim( (string) ( $page_seo['meta_description'] ?? '' ) );
		$seo_b    = Ui::badge( $has_seo ? 'SEO renseigné' : 'SEO à compléter', $has_seo ? 'success' : 'warning' );

		$menu = array( Ui::button( 'Réglages SEO de la page', $this->url( Menu::site_slug( 'seo' ) ), '', true ) );
		$path = (string) ( $schema['front_path'] ?? ( 'home' === $page ? '/' : '' ) );
		if ( '' !== $path ) {
			$menu[] = Ui::button( 'Voir la page', $this->front_origin() . $this->front_path( $path ), '', true, true );
		}
		$actions = Ui::button( 'Modifier', $this->url( Menu::site_slug( $page ) ), 'primary', true ) . Ui::menu( $menu );
		return Ui::row( esc_html( SiteNav::label( $page ) ), self::SUMMARIES[ $page ] ?? '', $state . $seo_b, $actions, '', self::thumb( $page ) );
	}

	/** Le front statique est servi en `.html` à la racine de l'origine. */
	private function front_path( string $path ): string {
		$map = array( '/' => '/index.html', '/offres' => '/offres.html', '/entreprises' => '/entreprises.html', '/savoir-faire' => '/savoir-faire.html', '/conseils' => '/blog.html', '/contact' => '/contact.html' );
		return $map[ $path ] ?? $path;
	}

	private function front_origin(): string {
		return method_exists( self::DIR, 'front_origin' ) ? (string) call_user_func( array( self::DIR, 'front_origin' ) ) : untrailingslashit( home_url() );
	}

	private function identity_card(): string {
		$id      = method_exists( self::DIR, 'identity' ) ? (array) call_user_func( array( self::DIR, 'identity' ) ) : array();
		$logo    = (string) ( $id['logo_url'] ?? '' );
		$favicon = (string) ( $id['favicon_url'] ?? '' );
		$pairs   = array(
			'Nom de marque' => Ui::text( (string) ( $id['brand_name'] ?? 'Postelio' ), true ),
			'Logo'          => '' !== $logo ? Ui::avatar( 'logo', $logo, true ) . Ui::text( basename( (string) wp_parse_url( $logo, PHP_URL_PATH ) ), false, true ) : Ui::text( 'Pastille « P » par défaut', false, true ),
			'Favicon'       => '' !== $favicon ? Ui::avatar( 'favicon', $favicon, true ) . Ui::text( ! empty( $id['favicon_is_default'] ) ? 'Favicon Postelio par défaut' : basename( (string) wp_parse_url( $favicon, PHP_URL_PATH ) ), false, true ) : Ui::text( 'Favicon Postelio par défaut', false, true ),
		);
		return Ui::card_open( 'Identité', 'Nom, logo et favicon utilisés sur tout le site.', Ui::button( 'Modifier', $this->url( Menu::site_slug( 'appearance' ) ), '', true ), '', 'Global' )
			. Ui::kv( $pairs ) . Ui::card_close();
	}
}
