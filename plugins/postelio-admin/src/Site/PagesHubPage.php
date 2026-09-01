<?php
/**
 * « Pages & contenus » : hub visuel des pages publiques du site (cartes). Chaque carte présente le
 * nom, une description, l'état de configuration, le statut SEO, et des boutons Modifier / Voir. Le
 * but est que l'utilisateur n'ait jamais à comprendre les CPT WordPress. Lecture via
 * SiteConfigDirectory (contrat postelio-site). Orchestration pure.
 *
 * @package Postelio\Admin\Site
 */

namespace Postelio\Admin\Site;

use Postelio\Admin\Pages\Page;
use Postelio\Admin\Support\Contracts;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PagesHubPage extends Page {

	private const DIR = '\\Postelio\\Site\\Api\\SiteConfigDirectory';

	/** @var array<string,string> Descriptions courtes par page. */
	private const DESCRIPTIONS = array(
		'home'      => 'Page d\'accueil : hero, recherche, sélections et appels à l\'action.',
		'jobs'      => 'Page publique des offres : hero, recherche, filtres, résultats.',
		'companies' => 'Annuaire des entreprises : hero, recherche, mises en avant.',
		'skills'    => 'Page Savoir-faire : hero, catégories, contenus mis en avant.',
		'advice'    => 'Page Conseils : hero, catégories, articles mis en avant.',
		'contact'   => 'Page Contact : coordonnées et formulaire (affichage).',
	);

	protected function capability(): string {
		return SiteMenu::CAP;
	}

	protected function body(): string {
		if ( ! Contracts::has( self::DIR ) ) {
			return Ui::header( 'Pages & contenus', 'Site Builder' )
				. Ui::empty_state( 'Module Site indisponible', 'Activez le plugin Postelio Site.', '🧩' );
		}

		$out  = SiteNav::render( 'postelio-site-pages' );
		$out .= Ui::toolbar( 'Mon site', 'Gérez les pages, la structure et l\'apparence de Postelio.' );
		$out .= '<div class="pst-hub-grid">';

		$seo = (array) call_user_func( array( self::DIR, 'config' ), 'seo' );

		foreach ( SiteMenu::HUB_PAGES as $page ) {
			$out .= $this->card( $page, $seo );
		}
		$out .= '</div>';

		// Structure du site (en-tête & pied) — reléguée ici pour alléger le menu principal.
		$out .= '<h2 class="pst-admin-section-title">Structure du site</h2>';
		$out .= '<div class="pst-hub-grid">';
		$out .= $this->structure_card( 'navigation', '🧭', 'En-tête & navigation', 'Logo, liens du menu, boutons Connexion / Inscription.' );
		$out .= $this->structure_card( 'footer', '👣', 'Pied de page', 'Colonnes de liens, réseaux sociaux, mentions.' );
		$out .= '</div>';
		return $out;
	}

	private function structure_card( string $page, string $icon, string $title, string $desc ): string {
		$edit = esc_url( $this->url( SiteMenu::slug_for( $page ) ) );
		$h  = '<div class="pst-hub-card">';
		$h .= '<div class="pst-hub-card__top"><span class="pst-hub-card__icon">' . esc_html( $icon ) . '</span><span class="pst-hub-card__name">' . esc_html( $title ) . '</span></div>';
		$h .= '<p class="pst-hub-card__desc">' . esc_html( $desc ) . '</p>';
		$h .= '<div class="pst-hub-card__actions"><a class="pst-btn pst-btn--sm pst-btn--primary" href="' . $edit . '">Modifier</a></div>';
		$h .= '</div>';
		return $h;
	}

	/** @param array<string,mixed> $seo */
	private function card( string $page, array $seo ): string {
		$schema = (array) call_user_func( array( self::DIR, 'schema' ), $page );
		$config = (array) call_user_func( array( self::DIR, 'config' ), $page );
		$label  = SiteEditorPage::label( $page );
		$icon   = (string) ( $schema['icon'] ?? '📄' );

		// État : nombre de sections actives.
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
		$state_badge = $total > 0
			? Ui::badge( $active . '/' . $total . ' sections actives', $active > 0 ? 'success' : 'neutral', true )
			: Ui::badge( 'Configuré', 'success', true );

		// SEO : titre/description renseignés pour cette page ?
		$page_seo = is_array( $seo[ $page ] ?? null ) ? $seo[ $page ] : array();
		$has_seo  = '' !== trim( (string) ( $page_seo['seo_title'] ?? '' ) ) || '' !== trim( (string) ( $page_seo['meta_description'] ?? '' ) );
		$seo_badge = Ui::badge( $has_seo ? 'SEO renseigné' : 'SEO à compléter', $has_seo ? 'success' : 'warning' );

		$edit_url = esc_url( $this->url( SiteMenu::slug_for( $page ) ) );
		$seo_url  = esc_url( $this->url( SiteMenu::slug_for( 'seo' ) ) );

		$view = '';
		$path = (string) ( $schema['front_path'] ?? '' );
		if ( '' !== $path ) {
			$view = '<a class="pst-btn pst-btn--sm" target="_blank" rel="noopener" href="' . esc_url( home_url( $path ) ) . '">Voir ↗</a>';
		}

		$h  = '<div class="pst-hub-card">';
		$h .= '<div class="pst-hub-card__top"><span class="pst-hub-card__icon">' . esc_html( $icon ) . '</span><span class="pst-hub-card__name">' . esc_html( $label ) . '</span></div>';
		$h .= '<p class="pst-hub-card__desc">' . esc_html( self::DESCRIPTIONS[ $page ] ?? '' ) . '</p>';
		$h .= '<div class="pst-hub-card__badges">' . $state_badge . ' ' . $seo_badge . '</div>';
		$h .= '<div class="pst-hub-card__actions">'
			. '<a class="pst-btn pst-btn--sm pst-btn--primary" href="' . $edit_url . '">Modifier</a>'
			. '<a class="pst-btn pst-btn--sm" href="' . $seo_url . '">SEO</a>'
			. $view
			. '</div>';
		$h .= '</div>';
		return $h;
	}
}
