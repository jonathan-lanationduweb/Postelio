<?php
/**
 * Menu WordPress « Postelio » : UN SEUL centre de contrôle (Phase 2 : plus de second menu « Site »).
 * Les écrans sont regroupés logiquement — Mon site / Activité / Contrôle / Système — via un ordre
 * explicite et des libellés de groupe rendus en CSS (::before, robustes, dégradant proprement).
 * Chaque page vérifie AUSSI sa capability côté serveur (défense en profondeur).
 *
 * @package Postelio\Admin
 */

namespace Postelio\Admin;

use Postelio\Admin\Pages\ApplicationsPage;
use Postelio\Admin\Pages\BillingPage;
use Postelio\Admin\Pages\CompaniesPage;
use Postelio\Admin\Pages\DashboardPage;
use Postelio\Admin\Pages\FilesPage;
use Postelio\Admin\Pages\HealthPage;
use Postelio\Admin\Pages\InterviewsPage;
use Postelio\Admin\Pages\JobsPage;
use Postelio\Admin\Pages\MessagingPage;
use Postelio\Admin\Pages\ModerationPage;
use Postelio\Admin\Pages\NotificationsPage;
use Postelio\Admin\Pages\PlaceholderPage;
use Postelio\Admin\Pages\SettingsPage;
use Postelio\Admin\Pages\SkillsPage;
use Postelio\Admin\Pages\SourcesPage;
use Postelio\Admin\Pages\UsersPage;
use Postelio\Admin\Site\PagesHubPage;
use Postelio\Admin\Site\SiteEditorPage;
use Postelio\Admin\Site\SiteMenu;
use Postelio\Admin\Support\Metrics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Menu {

	public const PARENT    = 'postelio-admin';
	public const CAP_VIEW  = 'pst_view_moderation_queue'; // admin + modérateur
	public const CAP_ADMIN = 'pst_manage_platform';       // admin uniquement

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'build' ) );
		add_action( 'admin_head', array( $this, 'group_styles' ) );
	}

	public function build(): void {
		add_menu_page( 'Postelio', 'Postelio', self::CAP_VIEW, self::PARENT, array( new DashboardPage(), 'render' ), self::icon(), 3 );

		$site = SiteMenu::CAP;

		// Ordre logique. Les groupes (Mon site / Activité / Contrôle / Système) sont matérialisés
		// par des libellés CSS ancrés sur le 1er élément de chaque groupe (voir group_styles()).
		$items = array(
			array( 'Tableau de bord', self::PARENT, self::CAP_VIEW, new DashboardPage() ),

			// — Mon site —
			array( 'Accueil', SiteMenu::slug_for( 'home' ), $site, new SiteEditorPage( 'home' ) ),
			array( 'Navigation', SiteMenu::slug_for( 'navigation' ), $site, new SiteEditorPage( 'navigation' ) ),
			array( 'Footer', SiteMenu::slug_for( 'footer' ), $site, new SiteEditorPage( 'footer' ) ),
			array( 'Pages & contenus', 'postelio-site-pages', $site, new PagesHubPage() ),
			array( 'Apparence', SiteMenu::slug_for( 'appearance' ), $site, new SiteEditorPage( 'appearance' ) ),
			array( 'SEO', SiteMenu::slug_for( 'seo' ), $site, new SiteEditorPage( 'seo' ) ),

			// — Activité —
			array( 'Utilisateurs', 'postelio-users', self::CAP_ADMIN, new UsersPage() ),
			array( 'Entreprises', 'postelio-companies', self::CAP_ADMIN, new CompaniesPage() ),
			array( 'Offres', 'postelio-jobs', self::CAP_ADMIN, new JobsPage() ),
			array( 'Candidatures', 'postelio-applications', self::CAP_ADMIN, new ApplicationsPage() ),
			array( 'Savoir-faire', 'postelio-skills', self::CAP_ADMIN, new SkillsPage() ),
			array( 'Messagerie', 'postelio-messaging', self::CAP_ADMIN, new MessagingPage() ),
			array( 'Entretiens', 'postelio-interviews', self::CAP_ADMIN, new InterviewsPage() ),

			// — Contrôle —
			array( $this->moderation_label(), 'postelio-moderation', self::CAP_VIEW, new ModerationPage() ),
			array( 'Facturation', 'postelio-billing', 'pst_manage_billing', new BillingPage() ),
			array( 'Sources d\'offres', 'postelio-sources', self::CAP_ADMIN, new SourcesPage() ),
			array( 'Notifications', 'postelio-notifications', self::CAP_ADMIN, new NotificationsPage() ),
			array( 'CV & fichiers', 'postelio-files', self::CAP_ADMIN, new FilesPage() ),

			// — Système —
			array( 'Réglages', 'postelio-settings', self::CAP_ADMIN, new SettingsPage() ),
			array( 'Santé du système', 'postelio-health', self::CAP_ADMIN, new HealthPage() ),
			array( 'Favoris & Alertes', 'postelio-alerts', self::CAP_ADMIN, new PlaceholderPage( 'Favoris & Alertes', 'Préparé pour le Lot 14 (favoris, recherches sauvegardées, alertes). Non implémenté.', self::CAP_ADMIN ) ),
		);

		foreach ( $items as $it ) {
			list( $label, $slug, $cap, $page ) = $it;
			add_submenu_page( self::PARENT, 'Postelio — ' . wp_strip_all_tags( $label ), $label, $cap, $slug, array( $page, 'render' ) );
		}

		// Éditeurs des pages de contenu : enregistrés (routables + permissions), mais MASQUÉS du menu
		// via CSS (accès par le hub « Pages & contenus »). On NE fait PAS remove_submenu_page() qui
		// casserait la vérification de capability de wp-admin sur ces écrans.
		foreach ( self::hidden_editor_pages() as $page ) {
			add_submenu_page( self::PARENT, 'Postelio Site — ' . SiteEditorPage::label( $page ), SiteEditorPage::label( $page ), $site, SiteMenu::slug_for( $page ), array( new SiteEditorPage( $page ), 'render' ) );
		}
	}

	/** @return string[] Pages de contenu accessibles via le hub (donc masquées du menu). */
	private static function hidden_editor_pages(): array {
		return array_values( array_filter( SiteMenu::HUB_PAGES, static function ( $p ) {
			return 'home' !== $p; // « Accueil » reste visible sous Mon site.
		} ) );
	}

	/** Libellés de groupe du sous-menu (CSS ::before, ancrés par slug — robustes, sans JS). */
	public function group_styles(): void {
		$groups = array(
			'postelio-site'       => 'Mon site',
			'postelio-users'      => 'Activité',
			'postelio-moderation' => 'Contrôle',
			'postelio-settings'   => 'Système',
		);
		$css = '';
		foreach ( $groups as $slug => $label ) {
			// href$= pour un match exact (évite que postelio-site attrape postelio-site-xxx).
			$css .= '#toplevel_page_' . self::PARENT . ' .wp-submenu a[href$="page=' . $slug . '"]::before{'
				. 'content:"' . esc_attr( $label ) . '";display:block;margin:6px 0 2px;padding-top:8px;'
				. 'border-top:1px solid rgba(255,255,255,.14);font-size:10px;font-weight:700;'
				. 'letter-spacing:.09em;text-transform:uppercase;color:#8f98ad;pointer-events:none;}';
		}
		// Masque du menu les éditeurs de pages de contenu (routables via le hub).
		foreach ( self::hidden_editor_pages() as $page ) {
			$css .= '#toplevel_page_' . self::PARENT . ' .wp-submenu a[href$="page=' . SiteMenu::slug_for( $page ) . '"]{display:none;}';
		}
		echo '<style id="pst-admin-group-styles">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- CSS statique, libellés échappés
	}

	/**
	 * Libellé « Modération » avec pastille du nombre de dossiers ouverts (convention WordPress
	 * `awaiting-mod`, rendue côté serveur — AUCUN polling JS). Silencieux si le module est absent.
	 */
	private function moderation_label(): string {
		$open = Metrics::moderation_open();
		if ( null === $open || $open <= 0 ) {
			return 'Modération';
		}
		return 'Modération <span class="awaiting-mod"><span class="pending-count">' . (int) $open . '</span></span>';
	}

	/** Icône du menu (SVG data-URI, bleu nuit). */
	private static function icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M10 1.6 2 5.2v6.1c0 4 3.4 6.4 8 7.1 4.6-.7 8-3.1 8-7.1V5.2L10 1.6Zm0 3.2 4.9 2.2v3.9c0 2.5-2 4.1-4.9 4.7-2.9-.6-4.9-2.2-4.9-4.7V7l4.9-2.2Z"/><circle fill="#a7aaad" cx="10" cy="9.4" r="2.1"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
