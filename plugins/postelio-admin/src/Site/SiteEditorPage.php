<?php
/**
 * Écran de l'éditeur visuel du site (« Site Builder »). Rend la coque : en-tête (Voir le site /
 * Enregistrer), éditeur à gauche, aperçu responsive à droite, save bar collante. Le contenu
 * dynamique (schéma + valeurs + endpoints) est injecté par `Plugin::enqueue` via `window.PST_SITE`.
 *
 * Cet écran est de l'ORCHESTRATION d'admin : il lit/écrit via les contrats du plugin postelio-site
 * (SiteConfigDirectory / REST). Aucune écriture directe, aucune logique métier.
 *
 * @package Postelio\Admin\Site
 */

namespace Postelio\Admin\Site;

use Postelio\Admin\Support\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteEditorPage {

	public const CAP = 'pst_manage_site';
	private const DIR = '\\Postelio\\Site\\Api\\SiteConfigDirectory';

	/** @var array<string,string> Sous-titres par page. */
	private const SUBTITLES = array(
		'home'       => 'Construisez votre page d\'accueil, section par section.',
		'navigation' => 'Logo, liens du menu et boutons d\'action.',
		'footer'     => 'Colonnes de liens, réseaux et mentions.',
		'appearance' => 'Couleurs, typographie et style des boutons.',
		'jobs'       => 'Configuration de la page publique des offres.',
		'companies'  => 'Configuration de la page publique des entreprises.',
		'skills'     => 'Configuration de la page Savoir-faire.',
		'blog'       => 'Configuration de la page Conseils / Blog.',
		'contact'    => 'Configuration de la page Contact.',
		'seo'        => 'Réglages SEO globaux et par page.',
	);

	private string $page;

	public function __construct( string $page ) {
		$this->page = $page;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'postelio-admin' ), 403 );
		}

		echo '<div class="pst-editor">';

		if ( ! Contracts::has( self::DIR ) ) {
			echo '<div class="pst-ed-empty"><h2>Module Site indisponible</h2><p>Activez le plugin <strong>Postelio Site</strong> pour éditer le site.</p></div></div>';
			return;
		}

		$label    = self::label( $this->page );
		$subtitle = self::SUBTITLES[ $this->page ] ?? '';
		$front    = home_url( '/' );

		echo '<div class="pst-ed-head"><div>'
			. '<span class="pst-ed-head__eyebrow">Postelio · Site</span>'
			. '<h1>' . esc_html( $label ) . '</h1>'
			. '<p>' . esc_html( $subtitle ) . '</p>'
			. '</div><div class="pst-ed-head__actions">'
			. '<a class="pst-ed-btn" href="' . esc_url( $front ) . '" target="_blank" rel="noopener">Voir le site ↗</a>'
			. '<button type="button" id="pst-ed-save" class="pst-ed-btn pst-ed-btn--primary">Enregistrer</button>'
			. '</div></div>';

		echo '<div class="pst-ed-body">'
			. '<div class="pst-ed-panel" id="pst-ed-panel"></div>'
			. '<div class="pst-ed-preview-wrap">'
			. '<div class="pst-ed-devices">'
			. '<button type="button" data-device="desktop" class="is-active">Desktop</button>'
			. '<button type="button" data-device="tablet">Tablette</button>'
			. '<button type="button" data-device="mobile">Mobile</button>'
			. '</div>'
			. '<div class="pst-ed-canvas" id="pst-ed-canvas"></div>'
			. '<p class="pst-ed-preview-hint">Aperçu fidèle — reflète vos modifications non enregistrées.</p>'
			. '</div></div>';

		echo '<div class="pst-ed-savebar" id="pst-ed-savebar">'
			. '<span class="pst-ed-savebar__msg">Modifications non enregistrées</span>'
			. '<div style="display:flex;gap:8px">'
			. '<button type="button" id="pst-ed-savebar-cancel" class="pst-ed-btn pst-ed-btn--ghost" style="color:#fff;border-color:rgba(255,255,255,.45)">Annuler</button>'
			. '<button type="button" id="pst-ed-savebar-save" class="pst-ed-btn">Enregistrer</button>'
			. '</div></div>';

		echo '</div>';
	}

	public static function label( string $page ): string {
		$labels = array(
			'home' => 'Accueil', 'navigation' => 'Navigation', 'footer' => 'Footer', 'appearance' => 'Apparence',
			'jobs' => 'Offres', 'companies' => 'Entreprises', 'skills' => 'Savoir-faire', 'blog' => 'Conseils / Blog',
			'contact' => 'Contact', 'seo' => 'SEO',
		);
		return $labels[ $page ] ?? ucfirst( $page );
	}
}
