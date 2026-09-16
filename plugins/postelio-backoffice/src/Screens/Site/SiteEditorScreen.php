<?php
/**
 * Éditeur visuel d'une page du site (Site Builder) — coque serveur. Gauche : accordéons de section /
 * champs pilotés par le schéma (moteur JS site-builder.js). Droite : le VRAI front en iframe
 * (`?postelio_preview=1`, postMessage, preview-ready, Desktop / Tablette / Mobile ou appareil imposé
 * par le schéma), sticky. En-tête compact (surtitre Mon site, titre, description, Voir le site,
 * Enregistrer), statut de sauvegarde discret, barre d'action en bas. La configuration (schéma,
 * valeurs, endpoints) est injectée par Assets via `window.PST_BO_SITE`.
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

	/** @var array<string,string> Descriptions courtes par page (langage utilisateur). */
	private const SUBTITLES = array(
		'home'       => 'Personnalisez le contenu de votre page d\'accueil, section par section.',
		'navigation' => 'Logo, liens du menu et boutons de connexion et d\'inscription.',
		'footer'     => 'Marque, colonnes de liens, réseaux sociaux et mentions du pied de page.',
		'appearance' => 'Identité (nom, logo, favicon), couleurs, typographie et boutons.',
		'jobs'       => 'Contenu de la page publique des offres.',
		'companies'  => 'Contenu de la page publique des entreprises.',
		'skills'     => 'Contenu de la page Savoir-faire.',
		'advice'     => 'Contenu de la page Conseils.',
		'contact'    => 'Contenu de la page Contact.',
		'seo'        => 'Titres, descriptions et images de partage, page par page.',
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

	protected function eyebrow(): string {
		return 'Postelio · Mon site';
	}

	protected function body(): string {
		$label = SiteNav::label( $this->page );
		$front = $this->front_origin() . '/';

		if ( ! class_exists( self::DIR ) ) {
			return $this->header( $label ) . Ui::empty_state( 'Module Site indisponible', 'Activez le plugin Postelio Site pour éditer le site.', '', 'build', true );
		}

		$actions = Ui::button( 'Voir le site', $front, '', false, true )
			. '<button type="button" id="pst-bo-save" class="bo-btn bo-btn--primary" disabled>Enregistrer</button>';

		$out  = $this->header( $label, self::SUBTITLES[ $this->page ] ?? '', $actions );
		$out .= SiteNav::render( Menu::site_slug( $this->page ) );
		$out .= '<p class="sb-status sb-status--clean" id="pst-bo-status" role="status" aria-live="polite">' . Ui::icon( 'check' ) . '<span>Enregistré</span></p>';

		$out .= '<div class="sb-workspace" id="pst-bo-workspace">'
			. '<div class="sb-panel" id="pst-bo-panel" aria-live="polite"></div>'
			. '<div class="sb-preview">'
			. '<div class="sb-preview__bar">'
			. '<span class="sb-preview__label" id="pst-bo-pvlabel">Aperçu</span>'
			. '<div class="sb-devices" id="pst-bo-devices" role="group" aria-label="Appareil">'
			. '<button type="button" data-device="desktop" class="is-active" aria-pressed="true">Desktop</button>'
			. '<button type="button" data-device="tablet" aria-pressed="false">Tablette</button>'
			. '<button type="button" data-device="mobile" aria-pressed="false">Mobile</button>'
			. '</div>'
			. '<span class="sb-preview__spacer"></span>'
			. '<button type="button" id="pst-bo-refresh" class="bo-btn bo-btn--sm bo-btn--ghost">Actualiser</button>'
			. '<a class="bo-btn bo-btn--sm bo-btn--ghost" id="pst-bo-pvopen" target="_blank" rel="noopener" href="' . esc_url( $front ) . '">Ouvrir le site <span class="bo-btn__ext" aria-hidden="true">↗</span></a>'
			. '</div>'
			. '<div class="sb-canvas" id="pst-bo-canvas"></div>'
			. '<p class="sb-preview__hint">Le vrai site, avec vos modifications non enregistrées.</p>'
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
