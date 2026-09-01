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
		// Nettoyage des menus WordPress superflus — UNIQUEMENT pour le personnel Postelio non
		// technique (jamais pour un administrateur `manage_options`). Priorité tardive.
		add_action( 'admin_menu', array( $this, 'simplify_wp_menu' ), 999 );
	}

	public function build(): void {
		add_menu_page( 'Postelio', 'Postelio', self::CAP_VIEW, self::PARENT, array( new DashboardPage(), 'render' ), self::icon(), 3 );

		$site = SiteMenu::CAP;

		// MENU COURT (visible). Groupes matérialisés par libellés CSS (group_styles()).
		$visible = array(
			array( 'Tableau de bord', self::PARENT, self::CAP_VIEW, new DashboardPage() ),

			// — Mon site — UNE seule entrée (le hub) ; Accueil/Navigation/Footer/Apparence/SEO sont
			// accessibles via la navigation secondaire interne (SiteNav), à la manière de la référence.
			array( 'Mon site', 'postelio-site-pages', $site, new PagesHubPage() ),

			// — Activité —
			array( 'Utilisateurs', 'postelio-users', self::CAP_ADMIN, new UsersPage() ),
			array( 'Entreprises', 'postelio-companies', self::CAP_ADMIN, new CompaniesPage() ),
			array( 'Offres', 'postelio-jobs', self::CAP_ADMIN, new JobsPage() ),
			array( 'Candidatures', 'postelio-applications', self::CAP_ADMIN, new ApplicationsPage() ),
			array( 'Messagerie', 'postelio-messaging', self::CAP_ADMIN, new MessagingPage() ),
			array( 'Entretiens', 'postelio-interviews', self::CAP_ADMIN, new InterviewsPage() ),
			array( 'Savoir-faire', 'postelio-skills', self::CAP_ADMIN, new SkillsPage() ),

			// — Gestion —
			array( $this->moderation_label(), 'postelio-moderation', self::CAP_VIEW, new ModerationPage() ),
			array( 'Facturation', 'postelio-billing', 'pst_manage_billing', new BillingPage() ),
			array( 'Sources d\'offres', 'postelio-sources', self::CAP_ADMIN, new SourcesPage() ),

			// — Réglages (hub des écrans techniques) —
			array( 'Réglages', 'postelio-settings', self::CAP_ADMIN, new SettingsPage() ),
		);

		// ROUTABLES mais MASQUÉS du menu (accès via les hubs Pages & contenus / Réglages). On NE fait
		// PAS remove_submenu_page() (qui casserait la vérification de capability de wp-admin).
		$hidden = array(
			array( 'Accueil', SiteMenu::slug_for( 'home' ), $site, new SiteEditorPage( 'home' ) ),
			array( 'Navigation', SiteMenu::slug_for( 'navigation' ), $site, new SiteEditorPage( 'navigation' ) ),
			array( 'Footer', SiteMenu::slug_for( 'footer' ), $site, new SiteEditorPage( 'footer' ) ),
			array( 'Apparence', SiteMenu::slug_for( 'appearance' ), $site, new SiteEditorPage( 'appearance' ) ),
			array( 'SEO', SiteMenu::slug_for( 'seo' ), $site, new SiteEditorPage( 'seo' ) ),
			array( 'Notifications', 'postelio-notifications', self::CAP_ADMIN, new NotificationsPage() ),
			array( 'CV & fichiers', 'postelio-files', self::CAP_ADMIN, new FilesPage() ),
			array( 'Santé du système', 'postelio-health', self::CAP_ADMIN, new HealthPage() ),
			array( 'Favoris & Alertes', 'postelio-alerts', self::CAP_ADMIN, new PlaceholderPage( 'Favoris & Alertes', 'Préparé pour le Lot 14. Non implémenté.', self::CAP_ADMIN ) ),
		);
		foreach ( self::hidden_editor_pages() as $page ) {
			$hidden[] = array( SiteEditorPage::label( $page ), SiteMenu::slug_for( $page ), $site, new SiteEditorPage( $page ) );
		}

		foreach ( array_merge( $visible, $hidden ) as $it ) {
			list( $label, $slug, $cap, $page ) = $it;
			add_submenu_page( self::PARENT, 'Postelio — ' . wp_strip_all_tags( $label ), $label, $cap, $slug, array( $page, 'render' ) );
		}
	}

	/** @return string[] Slugs des pages de contenu accessibles via le hub (donc masquées du menu). */
	private static function hidden_editor_pages(): array {
		return array_values( array_filter( SiteMenu::HUB_PAGES, static function ( $p ) {
			return 'home' !== $p; // « Accueil » reste visible sous Mon site.
		} ) );
	}

	/** @return string[] Tous les slugs à MASQUER du menu (mais routables). */
	private static function hidden_menu_slugs(): array {
		$slugs = array(
			SiteMenu::slug_for( 'home' ), SiteMenu::slug_for( 'navigation' ), SiteMenu::slug_for( 'footer' ),
			SiteMenu::slug_for( 'appearance' ), SiteMenu::slug_for( 'seo' ),
			'postelio-notifications', 'postelio-files', 'postelio-health', 'postelio-alerts',
		);
		foreach ( self::hidden_editor_pages() as $page ) {
			$slugs[] = SiteMenu::slug_for( $page );
		}
		return $slugs;
	}

	/** Libellés de groupe du sous-menu (CSS ::before, ancrés par slug — robustes, sans JS). */
	public function group_styles(): void {
		$groups = array(
			'postelio-users'      => 'Activité',
			'postelio-moderation' => 'Gestion',
			'postelio-settings'   => 'Réglages',
		);
		$css = '';
		foreach ( $groups as $slug => $label ) {
			// href$= pour un match exact (évite que postelio-site attrape postelio-site-xxx).
			$css .= '#toplevel_page_' . self::PARENT . ' .wp-submenu a[href$="page=' . $slug . '"]::before{'
				. 'content:"' . esc_attr( $label ) . '";display:block;margin:6px 0 2px;padding-top:8px;'
				. 'border-top:1px solid rgba(255,255,255,.14);font-size:10px;font-weight:700;'
				. 'letter-spacing:.09em;text-transform:uppercase;color:#8f98ad;pointer-events:none;}';
		}
		// Masque du menu les pages routables mais reléguées aux hubs.
		foreach ( self::hidden_menu_slugs() as $slug ) {
			$css .= '#toplevel_page_' . self::PARENT . ' .wp-submenu a[href$="page=' . $slug . '"]{display:none;}';
		}
		echo '<style id="pst-admin-group-styles">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- CSS statique, libellés échappés
	}

	/**
	 * Masque les menus WordPress techniques SUPERFLUS pour le personnel Postelio NON technique
	 * (ex. futur rôle « Postelio Manager »). Ne fait RIEN pour un administrateur `manage_options`
	 * (menu WordPress complet conservé) ni pour quelqu'un qui n'est pas staff Postelio.
	 */
	public function simplify_wp_menu(): void {
		if ( current_user_can( 'manage_options' ) ) {
			return; // administrateur technique : menu complet intact.
		}
		if ( ! current_user_can( self::CAP_ADMIN ) && ! current_user_can( self::CAP_VIEW ) ) {
			return; // pas de personnel Postelio → ne rien toucher.
		}
		foreach ( array( 'edit-comments.php', 'tools.php', 'themes.php', 'edit.php?post_type=page' ) as $slug ) {
			remove_menu_page( $slug );
		}
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
