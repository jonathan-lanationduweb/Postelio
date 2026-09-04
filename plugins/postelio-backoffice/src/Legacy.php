<?php
/**
 * Pont de compatibilité avec le plugin legacy « Postelio Admin » (postelio-admin).
 *
 * Stratégie Phase 1 (aucun fichier legacy supprimé) :
 *  - le back-office devient l'UNIQUE propriétaire du menu « Postelio » (filtre
 *    `postelio/admin/legacy_menu` → false, déclaré à l'inclusion du plugin) ;
 *  - les écrans NON migrés sont rendus par les classes de pages legacy, avec leurs assets legacy
 *    (filtre `postelio/admin/legacy_assets` laissé à true pour ces slugs) ;
 *  - les écrans migrés sont rendus par les Screens du back-office ; les assets legacy y sont coupés ;
 *  - les actions admin-post legacy (Actions) restent enregistrées : les écrans legacy en dépendent ;
 *  - si Postelio Admin est désactivé, les écrans non migrés affichent un état « en migration »
 *    au lieu de casser.
 *
 * @package Postelio\Backoffice
 */

namespace Postelio\Backoffice;

use Postelio\Backoffice\Ui\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Legacy {

	public function register(): void {
		// Ordre du sous-menu WordPress : garanti par notre propre construction (Menu). Rien d'autre à
		// faire ici en Phase 1 ; la classe centralise la connaissance du legacy pour la suite.
	}

	/** Le plugin legacy est-il présent (classes chargées) ? */
	public static function available(): bool {
		return class_exists( '\\Postelio\\Admin\\Plugin' );
	}

	/**
	 * Rend un écran legacy pour un slug donné. Retourne false si aucun rendu legacy n'existe.
	 */
	public static function render( string $slug ): bool {
		if ( ! self::available() ) {
			return false;
		}
		$page = self::page_for_slug( $slug );
		if ( null === $page ) {
			return false;
		}
		$page->render();
		return true;
	}

	/** Écran « en migration » quand ni le back-office ni le legacy ne savent rendre le slug. */
	public static function render_missing( string $label ): void {
		echo '<div class="pst-bo">' . Ui::page_header( $label, 'Écran en cours de migration vers le nouveau back-office.' ) // phpcs:ignore WordPress.Security.EscapeOutput
			. Ui::empty_state( 'Écran indisponible', 'Le plugin Postelio Admin (legacy) est désactivé et cet écran n\'est pas encore migré.' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Instance de page legacy (objet exposant render()) pour un slug wp-admin, ou null. */
	private static function page_for_slug( string $slug ): ?object {
		// Éditeurs Site Builder legacy (toutes les pages de site sauf celles migrées).
		if ( class_exists( '\\Postelio\\Admin\\Site\\SiteMenu' ) ) {
			$site_page = \Postelio\Admin\Site\SiteMenu::page_for_slug( $slug );
			if ( null !== $site_page && class_exists( '\\Postelio\\Admin\\Site\\SiteEditorPage' ) ) {
				return new \Postelio\Admin\Site\SiteEditorPage( $site_page );
			}
		}
		$map = array(
			'postelio-site-pages'    => '\\Postelio\\Admin\\Site\\PagesHubPage',
			'postelio-admin'         => '\\Postelio\\Admin\\Pages\\DashboardPage',
			'postelio-users'         => '\\Postelio\\Admin\\Pages\\UsersPage',
			'postelio-companies'     => '\\Postelio\\Admin\\Pages\\CompaniesPage',
			'postelio-jobs'          => '\\Postelio\\Admin\\Pages\\JobsPage',
			'postelio-applications'  => '\\Postelio\\Admin\\Pages\\ApplicationsPage',
			'postelio-messaging'     => '\\Postelio\\Admin\\Pages\\MessagingPage',
			'postelio-interviews'    => '\\Postelio\\Admin\\Pages\\InterviewsPage',
			'postelio-skills'        => '\\Postelio\\Admin\\Pages\\SkillsPage',
			'postelio-moderation'    => '\\Postelio\\Admin\\Pages\\ModerationPage',
			'postelio-billing'       => '\\Postelio\\Admin\\Pages\\BillingPage',
			'postelio-sources'       => '\\Postelio\\Admin\\Pages\\SourcesPage',
			'postelio-settings'      => '\\Postelio\\Admin\\Pages\\SettingsPage',
			'postelio-notifications' => '\\Postelio\\Admin\\Pages\\NotificationsPage',
			'postelio-files'         => '\\Postelio\\Admin\\Pages\\FilesPage',
			'postelio-health'        => '\\Postelio\\Admin\\Pages\\HealthPage',
			'postelio-alerts'        => '\\Postelio\\Admin\\Pages\\AlertsPage',
		);
		$fqcn = $map[ $slug ] ?? null;
		if ( null === $fqcn || ! class_exists( $fqcn ) ) {
			return null;
		}
		return new $fqcn();
	}
}
