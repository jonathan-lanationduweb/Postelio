<?php
/**
 * Menu WordPress « Postelio » : UNE seule entrée, propriété du back-office unifié.
 *
 * Slugs CONSERVÉS à l'identique du legacy (les favoris wp-admin ne cassent pas). Groupes
 * matérialisés par des libellés CSS (::before ancrés par slug, robustes, sans JS). Les écrans
 * secondaires (Notifications, Fichiers, Santé, Alertes, éditeurs de pages de site) restent
 * ROUTABLES mais masqués du menu — on n'utilise pas remove_submenu_page() (casserait la capability).
 *
 * Routage : slug migré → Screen du back-office ; sinon → rendu legacy (Legacy::render).
 *
 * @package Postelio\Backoffice
 */

namespace Postelio\Backoffice;

use Postelio\Backoffice\Screens\DashboardScreen;
use Postelio\Backoffice\Screens\Site\SiteEditorScreen;
use Postelio\Backoffice\Screens\Site\SiteHubScreen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Menu {

	public const PARENT     = 'postelio-admin';
	public const CAP_VIEW   = 'pst_view_moderation_queue'; // admin + modérateur
	public const CAP_ADMIN  = 'pst_manage_platform';       // admin uniquement
	public const CAP_SITE   = 'pst_manage_site';
	public const CAP_BILLING = 'pst_manage_billing';

	/** Slug de l'écran d'entrée du site (Accueil) et préfixe des éditeurs. */
	public const SITE_PARENT = 'postelio-site';

	/** Pages du Site Builder : clé de schéma => libellé. */
	public const SITE_PAGES = array(
		'home'       => 'Accueil',
		'navigation' => 'Navigation',
		'footer'     => 'Footer',
		'appearance' => 'Apparence',
		'seo'        => 'SEO',
		'jobs'       => 'Offres',
		'companies'  => 'Entreprises',
		'skills'     => 'Savoir-faire',
		'advice'     => 'Conseils',
		'contact'    => 'Contact',
	);

	/** Pages publiques listées dans la Vue d'ensemble de « Mon site ». */
	public const SITE_HUB_PAGES = array( 'home', 'jobs', 'companies', 'skills', 'advice', 'contact' );

	/**
	 * ÉCRANS MIGRÉS (Phase 1). Tout autre slug est délégué au legacy. Étendre cette liste = migrer
	 * un écran (le rendu ET les assets basculent ensemble).
	 */
	public const MIGRATED = array( 'postelio-admin', 'postelio-site-pages', 'postelio-site' );

	public static function is_migrated( string $slug ): bool {
		return in_array( $slug, self::MIGRATED, true );
	}

	public static function site_slug( string $page ): string {
		return 'home' === $page ? self::SITE_PARENT : self::SITE_PARENT . '-' . $page;
	}

	/** Clé de page Site Builder pour un slug, ou null. */
	public static function site_page_for_slug( string $slug ): ?string {
		if ( self::SITE_PARENT === $slug ) {
			return 'home';
		}
		$prefix = self::SITE_PARENT . '-';
		if ( 0 === strpos( $slug, $prefix ) ) {
			$key = substr( $slug, strlen( $prefix ) );
			return isset( self::SITE_PAGES[ $key ] ) ? $key : null;
		}
		return null;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'build' ) );
		add_action( 'admin_head', array( $this, 'menu_styles' ) );
		add_action( 'admin_menu', array( $this, 'simplify_wp_menu' ), 999 );
	}

	/** @return array<int, array{0:string,1:string,2:string}> Entrées visibles (libellé, slug, cap) dans l'ordre. */
	public static function visible_items(): array {
		return array(
			array( 'Tableau de bord', self::PARENT, self::CAP_VIEW ),
			array( 'Mon site', 'postelio-site-pages', self::CAP_SITE ),
			// — Activité —
			array( 'Utilisateurs', 'postelio-users', self::CAP_ADMIN ),
			array( 'Entreprises', 'postelio-companies', self::CAP_ADMIN ),
			array( 'Offres', 'postelio-jobs', self::CAP_ADMIN ),
			array( 'Candidatures', 'postelio-applications', self::CAP_ADMIN ),
			array( 'Messagerie', 'postelio-messaging', self::CAP_ADMIN ),
			array( 'Entretiens', 'postelio-interviews', self::CAP_ADMIN ),
			array( 'Savoir-faire', 'postelio-skills', self::CAP_ADMIN ),
			// — Gestion —
			array( 'Modération', 'postelio-moderation', self::CAP_VIEW ),
			array( 'Facturation', 'postelio-billing', self::CAP_BILLING ),
			array( 'Sources d\'offres', 'postelio-sources', self::CAP_ADMIN ),
			// — Réglages —
			array( 'Réglages', 'postelio-settings', self::CAP_ADMIN ),
		);
	}

	/** @return array<int, array{0:string,1:string,2:string}> Entrées routables mais masquées du menu. */
	public static function hidden_items(): array {
		$items = array(
			array( 'Notifications', 'postelio-notifications', self::CAP_ADMIN ),
			array( 'CV & fichiers', 'postelio-files', self::CAP_ADMIN ),
			array( 'Santé du système', 'postelio-health', self::CAP_ADMIN ),
			array( 'Favoris & Alertes', 'postelio-alerts', self::CAP_ADMIN ),
		);
		foreach ( self::SITE_PAGES as $page => $label ) {
			$items[] = array( $label, self::site_slug( $page ), self::CAP_SITE );
		}
		return $items;
	}

	public function build(): void {
		add_menu_page( 'Postelio', 'Postelio', self::CAP_VIEW, self::PARENT, array( $this, 'route_parent' ), self::icon(), 3 );

		foreach ( array_merge( self::visible_items(), self::hidden_items() ) as $it ) {
			list( $label, $slug, $cap ) = $it;
			// Le sous-menu « Tableau de bord » partage le slug du parent : même hook WordPress, donc
			// MÊME callable (dédupliqué par add_action) — sinon l'écran serait rendu deux fois.
			$callback = ( self::PARENT === $slug )
				? array( $this, 'route_parent' )
				: function () use ( $slug, $label ) { $this->route( $slug, $label ); };
			add_submenu_page( self::PARENT, 'Postelio — ' . $label, $label, $cap, $slug, $callback );
		}
	}

	public function route_parent(): void {
		$this->route( self::PARENT, 'Tableau de bord' );
	}

	/** Point d'entrée unique de rendu : écran migré → Screen ; sinon legacy ; sinon état « en migration ». */
	public function route( string $slug, string $label ): void {
		if ( self::is_migrated( $slug ) ) {
			$this->screen_for( $slug )->render();
			return;
		}
		if ( ! Legacy::render( $slug ) ) {
			Legacy::render_missing( $label );
		}
	}

	private function screen_for( string $slug ): Screens\Screen {
		if ( 'postelio-site-pages' === $slug ) {
			return new SiteHubScreen();
		}
		$site_page = self::site_page_for_slug( $slug );
		if ( null !== $site_page ) {
			return new SiteEditorScreen( $site_page );
		}
		return new DashboardScreen();
	}

	/** Libellés de groupe + masquage des écrans secondaires (CSS ancré par slug — exact via href$=). */
	public function menu_styles(): void {
		$groups = array(
			'postelio-users'      => 'Activité',
			'postelio-moderation' => 'Gestion',
			'postelio-settings'   => 'Réglages',
		);
		$css = '';
		foreach ( $groups as $slug => $label ) {
			$css .= '#toplevel_page_' . self::PARENT . ' .wp-submenu a[href$="page=' . $slug . '"]::before{'
				. 'content:"' . esc_attr( $label ) . '";display:block;margin:8px 0 2px;padding-top:8px;'
				. 'border-top:1px solid rgba(255,255,255,.12);font-size:10px;font-weight:700;'
				. 'letter-spacing:.09em;text-transform:uppercase;color:#8f98ad;pointer-events:none;}';
		}
		foreach ( self::hidden_items() as $it ) {
			$css .= '#toplevel_page_' . self::PARENT . ' .wp-submenu a[href$="page=' . $it[1] . '"]{display:none;}';
		}
		echo '<style id="pst-bo-menu">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- CSS statique, libellés échappés
	}

	/**
	 * Masque les menus WordPress techniques superflus pour le personnel Postelio NON technique.
	 * Ne fait rien pour un administrateur `manage_options` ni pour un non-staff.
	 */
	public function simplify_wp_menu(): void {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP_ADMIN ) && ! current_user_can( self::CAP_VIEW ) ) {
			return;
		}
		foreach ( array( 'edit-comments.php', 'tools.php', 'themes.php', 'edit.php?post_type=page' ) as $slug ) {
			remove_menu_page( $slug );
		}
	}

	/** Icône du menu : pastille arrondie + « P » (déclinaison monochrome du favicon Postelio). */
	private static function icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="1.5" y="1.5" width="17" height="17" rx="4.5" fill="#a7aaad"/>'
			. '<text x="10" y="14.6" text-anchor="middle" font-family="Georgia,Times New Roman,serif" font-size="12.5" font-weight="700" fill="#1d2327">P</text></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
