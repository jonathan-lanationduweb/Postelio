<?php
/**
 * Menu WordPress « Postelio » : un centre de contrôle unique (pas 15 menus séparés). Chaque
 * sous-page déclare sa propre capability (WordPress masque celles auxquelles l'utilisateur n'a
 * pas droit) ; la page vérifie AUSSI la capability côté serveur (défense en profondeur).
 *
 * Phase 1 : pages complètes = Tableau de bord, Utilisateurs, Entreprises, Offres, Modération,
 * Santé. Les autres entrées existent (architecture/menu) mais renvoient un écran « à venir ».
 *
 * @package Postelio\Admin
 */

namespace Postelio\Admin;

use Postelio\Admin\Pages\CompaniesPage;
use Postelio\Admin\Pages\DashboardPage;
use Postelio\Admin\Pages\HealthPage;
use Postelio\Admin\Pages\JobsPage;
use Postelio\Admin\Pages\ModerationPage;
use Postelio\Admin\Pages\PlaceholderPage;
use Postelio\Admin\Pages\UsersPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Menu {

	public const PARENT    = 'postelio-admin';
	public const CAP_VIEW  = 'pst_view_moderation_queue'; // admin + modérateur
	public const CAP_ADMIN = 'pst_manage_platform';       // admin uniquement

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'build' ) );
	}

	public function build(): void {
		add_menu_page(
			'Postelio',
			'Postelio',
			self::CAP_VIEW,
			self::PARENT,
			array( new DashboardPage(), 'render' ),
			self::icon(),
			3
		);

		// Ordre voulu (libellé, slug, cap, page).
		$items = array(
			array( 'Tableau de bord', self::PARENT, self::CAP_VIEW, new DashboardPage() ),
			array( 'Utilisateurs', 'postelio-users', self::CAP_ADMIN, new UsersPage() ),
			array( 'Entreprises', 'postelio-companies', self::CAP_ADMIN, new CompaniesPage() ),
			array( 'Offres', 'postelio-jobs', self::CAP_ADMIN, new JobsPage() ),
			array( 'Candidatures', 'postelio-applications', self::CAP_ADMIN, new PlaceholderPage( 'Candidatures', 'Suivi des candidatures par statut, offre et entreprise.', self::CAP_ADMIN ) ),
			array( 'CV & fichiers', 'postelio-files', self::CAP_ADMIN, new PlaceholderPage( 'CV & fichiers', 'État du stockage privé, quarantaine et scanner. (Accès aux fichiers restreint.)', self::CAP_ADMIN ) ),
			array( 'Messagerie', 'postelio-messaging', self::CAP_ADMIN, new PlaceholderPage( 'Messagerie', 'Métriques et statuts de conversations. L\'accès au contenu reste réservé à la modération.', self::CAP_ADMIN ) ),
			array( 'Entretiens', 'postelio-interviews', self::CAP_ADMIN, new PlaceholderPage( 'Entretiens', 'Liste et statuts des entretiens (sans exposer téléphone/lien visio en tableau).', self::CAP_ADMIN ) ),
			array( 'Notifications', 'postelio-notifications', self::CAP_ADMIN, new PlaceholderPage( 'Notifications', 'Observabilité des envois : en attente, envoyés, échoués, prochain retry.', self::CAP_ADMIN ) ),
			array( 'Sources d\'offres', 'postelio-sources', self::CAP_ADMIN, new PlaceholderPage( 'Sources d\'offres', 'État des connecteurs (France Travail…) : configuré, dernier sync, erreurs.', self::CAP_ADMIN ) ),
			array( 'Modération', 'postelio-moderation', self::CAP_VIEW, new ModerationPage() ),
			array( 'Facturation', 'postelio-billing', 'pst_manage_billing', new PlaceholderPage( 'Facturation', 'Commandes, paiements, fulfillment et santé Stripe. Aucun secret exposé.', 'pst_manage_billing' ) ),
			array( 'Savoir-faire', 'postelio-skills', self::CAP_ADMIN, new PlaceholderPage( 'Savoir-faire', 'Contenus par statut/auteur/catégorie + modération (via le contrat existant).', self::CAP_ADMIN ) ),
			array( 'Favoris & Alertes', 'postelio-alerts', self::CAP_ADMIN, new PlaceholderPage( 'Favoris & Alertes', 'Préparé pour le Lot 14 (favoris, recherches sauvegardées, alertes). Non implémenté.', self::CAP_ADMIN ) ),
			array( 'Réglages', 'postelio-settings', self::CAP_ADMIN, new PlaceholderPage( 'Réglages', 'Réglages Postelio sûrs et modifiables. Les secrets restent en environnement (jamais ici).', self::CAP_ADMIN ) ),
			array( 'Santé du système', 'postelio-health', self::CAP_ADMIN, new HealthPage() ),
		);

		foreach ( $items as $it ) {
			list( $label, $slug, $cap, $page ) = $it;
			add_submenu_page( self::PARENT, 'Postelio — ' . $label, $label, $cap, $slug, array( $page, 'render' ) );
		}
	}

	/** Icône du menu (SVG data-URI, bleu nuit). */
	private static function icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M10 1.6 2 5.2v6.1c0 4 3.4 6.4 8 7.1 4.6-.7 8-3.1 8-7.1V5.2L10 1.6Zm0 3.2 4.9 2.2v3.9c0 2.5-2 4.1-4.9 4.7-2.9-.6-4.9-2.2-4.9-4.7V7l4.9-2.2Z"/><circle fill="#a7aaad" cx="10" cy="9.4" r="2.1"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
