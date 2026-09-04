<?php
/**
 * Éditeur visuel d'une page du site (Site Builder) — coque serveur. Gauche : sections/champs pilotés
 * par le schéma (moteur JS site-builder.js). Droite : le VRAI front en iframe (`?postelio_preview=1`,
 * postMessage, preview-ready, Desktop/Tablette/Mobile ou appareil imposé par le schéma). Save bar.
 * La configuration (schéma, valeurs, endpoints) est injectée par Assets via `window.PST_BO_SITE`.
 *
 * Orchestration pure : lecture/écriture via le contrat et le REST de postelio-site.
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

final class SiteEditorScreen extends Screen {

	private const DIR = '\\Postelio\\Site\\Api\\SiteConfigDirectory';

	/** @var array<string,string> Sous-titres par page. */
	private const SUBTITLES = array(
		'home'       => 'Composez la page d\'accueil, section par section. L\'aperçu est le vrai site.',
		'navigation' => 'Logo, liens du menu et boutons d\'action.',
		'footer'     => 'Marque, colonnes de liens, réseaux et mentions — aperçu mobile centré sur le vrai footer.',
		'appearance' => 'Identité (nom, logo, favicon), couleurs, typographie et boutons.',
		'jobs'       => 'Configuration de la page publique des offres.',
		'companies'  => 'Configuration de la page publique des entreprises.',
		'skills'     => 'Configuration de la page Savoir-faire.',
		'advice'     => 'Configuration de la page Conseils.',
		'contact'    => 'Configuration de la page Contact.',
		'seo'        => 'Réglages SEO globaux et par page.',
	);

	private string $page;

	public function __construct( string $page ) {
		$this->page = $page;
	}

	protected function capability(): string {
		return Menu::CAP_SITE;
	}

	protected function wrapper_class(): string {
		return 'pst-bo--editor';
	}

	protected function body(): string {
		$label = SiteNav::label( $this->page );
		$front = $this->front_origin() . '/';

		if ( ! class_exists( self::DIR ) ) {
			return Ui::page_header( $label, '', '', 'Postelio · Mon site' ) . Ui::empty_state( 'Module Site indisponible', 'Activez le plugin Postelio Site pour éditer le site.' );
		}

		$actions = Ui::button( 'Voir le site', $front, '', false, true )
			. '<button type="button" id="pst-bo-save" class="bo-btn bo-btn--primary">Enregistrer</button>';

		$out  = Ui::page_header( $label, self::SUBTITLES[ $this->page ] ?? '', $actions, 'Postelio · Mon site' );
		$out .= SiteNav::render( Menu::site_slug( $this->page ) );

		$out .= '<div class="sb-workspace" id="pst-bo-workspace">'
			. '<div class="sb-panel" id="pst-bo-panel" aria-live="polite"></div>'
			. '<div class="sb-preview">'
			. '<div class="sb-preview__bar">'
			. '<span class="sb-preview__label" id="pst-bo-pvlabel">Aperçu</span>'
			. '<div class="sb-devices" id="pst-bo-devices" role="group" aria-label="Appareil">'
			. '<button type="button" data-device="desktop" class="is-active">Desktop</button>'
			. '<button type="button" data-device="tablet">Tablette</button>'
			. '<button type="button" data-device="mobile">Mobile</button>'
			. '</div>'
			. '<a class="sb-preview__open" id="pst-bo-pvopen" target="_blank" rel="noopener" href="' . esc_url( $front ) . '">Ouvrir ↗</a>'
			. '</div>'
			. '<div class="sb-canvas" id="pst-bo-canvas"></div>'
			. '<p class="sb-preview__hint">Le vrai site — reflète vos modifications non enregistrées.</p>'
			. '</div></div>';

		$out .= '<div class="sb-savebar" id="pst-bo-savebar" role="status">'
			. '<span class="sb-savebar__msg">Modifications non enregistrées</span>'
			. '<div class="sb-savebar__actions">'
			. '<button type="button" id="pst-bo-cancel" class="bo-btn bo-btn--onsolid">Annuler</button>'
			. '<button type="button" id="pst-bo-savebar-save" class="bo-btn bo-btn--accent">Enregistrer</button>'
			. '</div></div>';
		return $out;
	}

	private function front_origin(): string {
		return method_exists( self::DIR, 'front_origin' ) ? (string) call_user_func( array( self::DIR, 'front_origin' ) ) : untrailingslashit( home_url() );
	}
}
