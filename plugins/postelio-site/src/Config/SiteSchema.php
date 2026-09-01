<?php
/**
 * Schéma de configuration du SITE public Postelio. SOURCE DE VÉRITÉ de la structure éditable :
 * pages → sections → champs (+ valeurs par défaut). Données PURES (aucun appel WordPress) →
 * testable et réutilisable par l'éditeur admin, l'aperçu et l'API REST publique.
 *
 * Chaque page a un `type` :
 *   - « sections » : composée de sections activables/réordonnables (ex. Accueil) ;
 *   - « single »   : un seul groupe de champs (ex. Navigation, Footer, Apparence).
 *
 * Types de champ : text · textarea · toggle · media · color · select · number · repeater.
 *
 * @package Postelio\Site\Config
 */

namespace Postelio\Site\Config;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_SITE_TESTING' ) ) {
		exit;
	}
}

final class SiteSchema {

	/** Version du schéma de configuration (permet des migrations futures). */
	public const VERSION = 1;

	/** Pages complètes en Phase 1 ; les autres sont préparées (structure) mais partielles. */
	public const COMPLETE_PAGES = array( 'home', 'navigation', 'footer', 'appearance' );

	/** @return array<string,mixed> Schéma complet { page => définition }. */
	public static function all(): array {
		return array(
			'home'       => self::home(),
			'navigation' => self::navigation(),
			'footer'     => self::footer(),
			'appearance' => self::appearance(),
			'jobs'       => self::jobs(),
			'companies'  => self::companies(),
			'skills'     => self::skills(),
			'blog'       => self::blog(),
			'contact'    => self::contact(),
			'seo'        => self::seo(),
		);
	}

	/** @return string[] Slugs de pages connus. */
	public static function pages(): array {
		return array_keys( self::all() );
	}

	public static function has_page( string $page ): bool {
		return in_array( $page, self::pages(), true );
	}

	/** @return array<string,mixed>|null */
	public static function page( string $page ): ?array {
		$all = self::all();
		return $all[ $page ] ?? null;
	}

	/**
	 * Valeurs par défaut d'une page (dérivées du schéma).
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults( string $page ): array {
		$def = self::page( $page );
		if ( null === $def ) {
			return array();
		}
		if ( 'sections' === ( $def['type'] ?? '' ) ) {
			$out = array( '_order' => array_keys( $def['sections'] ) );
			foreach ( (array) $def['sections'] as $skey => $section ) {
				$out[ $skey ] = self::field_defaults( (array) ( $section['fields'] ?? array() ) );
				$out[ $skey ]['_enabled'] = (bool) ( $section['enabled_default'] ?? true );
			}
			return $out;
		}
		return self::field_defaults( (array) ( $def['fields'] ?? array() ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $fields
	 * @return array<string,mixed>
	 */
	private static function field_defaults( array $fields ): array {
		$out = array();
		foreach ( $fields as $key => $f ) {
			$out[ $key ] = $f['default'] ?? self::empty_for( (string) ( $f['type'] ?? 'text' ) );
		}
		return $out;
	}

	private static function empty_for( string $type ) {
		switch ( $type ) {
			case 'toggle': return false;
			case 'number': return 0;
			case 'repeater': return array();
			default: return '';
		}
	}

	// ----------------------------------------------------------------------- ACCUEIL

	private static function home(): array {
		return array(
			'label' => 'Accueil',
			'icon'  => '🏠',
			'type'  => 'sections',
			'sections' => array(
				'hero' => array(
					'label'       => 'Hero',
					'reorderable' => false,
					'fields'      => array(
						'title'           => array( 'type' => 'text', 'label' => 'Titre', 'default' => 'Trouvez le poste administratif qui vous ressemble' ),
						'subtitle'        => array( 'type' => 'text', 'label' => 'Sous-titre', 'default' => 'La plateforme emploi dédiée aux métiers du secrétariat et de l\'assistanat' ),
						'text'            => array( 'type' => 'textarea', 'label' => 'Texte', 'default' => '' ),
						'search_placeholder' => array( 'type' => 'text', 'label' => 'Placeholder recherche', 'default' => 'Métier, mot-clé…' ),
						'cta_primary_label'  => array( 'type' => 'text', 'label' => 'CTA principal — libellé', 'default' => 'Voir les offres' ),
						'cta_primary_url'    => array( 'type' => 'text', 'label' => 'CTA principal — lien', 'default' => '/offres' ),
						'cta_secondary_label'=> array( 'type' => 'text', 'label' => 'CTA secondaire — libellé', 'default' => 'Déposer mon profil' ),
						'cta_secondary_url'  => array( 'type' => 'text', 'label' => 'CTA secondaire — lien', 'default' => '/inscription' ),
						'background'      => array( 'type' => 'media', 'label' => 'Image / vidéo de fond', 'default' => '' ),
						'video_intro'     => array( 'type' => 'toggle', 'label' => 'Activer l\'intro vidéo', 'default' => false, 'help' => 'Le comportement cinématique est géré par le front ; ici on active seulement le paramètre.' ),
						'poster'          => array( 'type' => 'media', 'label' => 'Poster vidéo', 'default' => '' ),
						'overlay'         => array( 'type' => 'toggle', 'label' => 'Overlay sombre', 'default' => true ),
						'align'           => array( 'type' => 'select', 'label' => 'Alignement', 'default' => 'left', 'options' => array( 'left' => 'Gauche', 'center' => 'Centré' ) ),
						'height'          => array( 'type' => 'select', 'label' => 'Hauteur', 'default' => 'large', 'options' => array( 'medium' => 'Moyenne', 'large' => 'Grande', 'full' => 'Plein écran' ) ),
						'text_light'      => array( 'type' => 'toggle', 'label' => 'Texte clair', 'default' => true ),
					),
				),
				'search' => array(
					'label'  => 'Recherche d\'emploi',
					'fields' => array(
						'title'            => array( 'type' => 'text', 'label' => 'Titre', 'default' => 'Que recherchez-vous ?' ),
						'help_text'        => array( 'type' => 'text', 'label' => 'Texte d\'aide', 'default' => '' ),
						'placeholder_role' => array( 'type' => 'text', 'label' => 'Placeholder métier', 'default' => 'Assistant(e), secrétaire…' ),
						'placeholder_city' => array( 'type' => 'text', 'label' => 'Placeholder ville', 'default' => 'Ville ou code postal' ),
						'button_label'     => array( 'type' => 'text', 'label' => 'Bouton', 'default' => 'Rechercher' ),
						'show_guided'      => array( 'type' => 'toggle', 'label' => 'Recherche guidée', 'default' => false ),
						'show_suggestions' => array( 'type' => 'toggle', 'label' => 'Suggestions', 'default' => true ),
						'show_categories'  => array( 'type' => 'toggle', 'label' => 'Catégories populaires', 'default' => true ),
					),
				),
				'categories' => self::collection_section( 'Catégories', 'Explorez par métier', 8, '/offres', 'Voir les catégories' ),
				'jobs'       => self::collection_section( 'Offres à la une', 'Les dernières opportunités', 6, '/offres', 'Voir toutes les offres' ),
				'companies'  => self::collection_section( 'Entreprises qui recrutent', 'Elles nous font confiance', 8, '/entreprises', 'Voir toutes les entreprises' ),
				'skills'     => self::collection_section( 'Savoir-faire', 'Conseils et ressources métier', 3, '/savoir-faire', 'Découvrir' ),
				'arguments'  => array(
					'label'  => 'Arguments Postelio',
					'fields' => array(
						'title'    => array( 'type' => 'text', 'label' => 'Titre', 'default' => 'Pourquoi Postelio ?' ),
						'subtitle' => array( 'type' => 'text', 'label' => 'Sous-titre', 'default' => '' ),
						'items'    => array(
							'type'   => 'repeater',
							'label'  => 'Arguments',
							'fields' => array(
								'icon'  => array( 'type' => 'text', 'label' => 'Icône (emoji)' ),
								'title' => array( 'type' => 'text', 'label' => 'Titre' ),
								'text'  => array( 'type' => 'textarea', 'label' => 'Texte' ),
							),
							'default' => array(
								array( 'icon' => '🎯', 'title' => 'Spécialisé', 'text' => 'Uniquement des métiers administratifs.' ),
								array( 'icon' => '⚡', 'title' => 'Rapide', 'text' => 'Candidatez en quelques clics.' ),
								array( 'icon' => '🔒', 'title' => 'Fiable', 'text' => 'Entreprises vérifiées.' ),
							),
						),
					),
				),
				'articles'   => self::collection_section( 'Conseils & actualités', 'Le blog Postelio', 3, '/blog', 'Lire le blog' ),
				'cta'        => array(
					'label'  => 'Appel à l\'action final',
					'fields' => array(
						'title'        => array( 'type' => 'text', 'label' => 'Titre', 'default' => 'Prêt à trouver votre prochain poste ?' ),
						'text'         => array( 'type' => 'textarea', 'label' => 'Texte', 'default' => 'Rejoignez des milliers de candidats et d\'entreprises.' ),
						'button_label' => array( 'type' => 'text', 'label' => 'Bouton', 'default' => 'Créer mon compte' ),
						'button_url'   => array( 'type' => 'text', 'label' => 'Lien', 'default' => '/inscription' ),
					),
				),
			),
		);
	}

	/** Section « collection » réutilisable (offres / entreprises / savoir-faire / articles / catégories). */
	private static function collection_section( string $label, string $subtitle, int $count, string $cta_url, string $cta_label ): array {
		return array(
			'label'  => $label,
			'fields' => array(
				'title'        => array( 'type' => 'text', 'label' => 'Titre', 'default' => $label ),
				'subtitle'     => array( 'type' => 'text', 'label' => 'Sous-titre', 'default' => $subtitle ),
				'count'        => array( 'type' => 'number', 'label' => 'Nombre d\'éléments', 'default' => $count, 'min' => 1, 'max' => 24 ),
				'mode'         => array( 'type' => 'select', 'label' => 'Mode', 'default' => 'auto', 'options' => array( 'auto' => 'Automatique', 'manual' => 'Sélection manuelle' ) ),
				'manual_uuids' => array( 'type' => 'textarea', 'label' => 'UUIDs sélectionnés (mode manuel)', 'default' => '', 'help' => 'Un UUID par ligne. Utilisé uniquement en mode manuel.' ),
				'cta_label'    => array( 'type' => 'text', 'label' => 'Bouton', 'default' => $cta_label ),
				'cta_url'      => array( 'type' => 'text', 'label' => 'Lien du bouton', 'default' => $cta_url ),
			),
		);
	}

	// ----------------------------------------------------------------------- NAVIGATION

	private static function navigation(): array {
		return array(
			'label' => 'Navigation',
			'icon'  => '🧭',
			'type'  => 'single',
			'fields' => array(
				'logo'        => array( 'type' => 'media', 'label' => 'Logo', 'default' => '' ),
				'brand_text'  => array( 'type' => 'text', 'label' => 'Nom de marque', 'default' => 'Postelio' ),
				'items'       => array(
					'type'   => 'repeater',
					'label'  => 'Liens du menu',
					'fields' => array(
						'label'      => array( 'type' => 'text', 'label' => 'Libellé' ),
						'url'        => array( 'type' => 'text', 'label' => 'Lien' ),
						'visibility' => array( 'type' => 'select', 'label' => 'Visibilité', 'options' => array( 'all' => 'Tout le monde', 'guest' => 'Déconnecté', 'auth' => 'Connecté' ) ),
					),
					'default' => array(
						array( 'label' => 'Offres', 'url' => '/offres', 'visibility' => 'all' ),
						array( 'label' => 'Entreprises', 'url' => '/entreprises', 'visibility' => 'all' ),
						array( 'label' => 'Savoir-faire', 'url' => '/savoir-faire', 'visibility' => 'all' ),
						array( 'label' => 'Blog', 'url' => '/blog', 'visibility' => 'all' ),
					),
				),
				'show_login'  => array( 'type' => 'toggle', 'label' => 'Bouton Connexion', 'default' => true ),
				'login_label' => array( 'type' => 'text', 'label' => 'Libellé Connexion', 'default' => 'Connexion' ),
				'login_url'   => array( 'type' => 'text', 'label' => 'Lien Connexion', 'default' => '/connexion' ),
				'show_signup' => array( 'type' => 'toggle', 'label' => 'Bouton Inscription', 'default' => true ),
				'signup_label'=> array( 'type' => 'text', 'label' => 'Libellé Inscription', 'default' => 'Inscription' ),
				'signup_url'  => array( 'type' => 'text', 'label' => 'Lien Inscription', 'default' => '/inscription' ),
			),
		);
	}

	// ----------------------------------------------------------------------- FOOTER

	private static function footer(): array {
		return array(
			'label' => 'Footer',
			'icon'  => '👣',
			'type'  => 'single',
			'fields' => array(
				'logo'        => array( 'type' => 'media', 'label' => 'Logo', 'default' => '' ),
				'brand_text'  => array( 'type' => 'text', 'label' => 'Nom de marque', 'default' => 'Postelio' ),
				'description' => array( 'type' => 'textarea', 'label' => 'Description', 'default' => 'La plateforme emploi des métiers administratifs.' ),
				'columns'     => array(
					'type'   => 'repeater',
					'label'  => 'Colonnes de liens',
					'fields' => array(
						'title' => array( 'type' => 'text', 'label' => 'Titre de colonne' ),
						'links' => array( 'type' => 'textarea', 'label' => 'Liens (un par ligne : Libellé|/url)' ),
					),
					'default' => array(
						array( 'title' => 'Candidats', 'links' => "Offres|/offres\nDéposer mon CV|/inscription" ),
						array( 'title' => 'Entreprises', 'links' => "Publier une offre|/entreprise\nTarifs|/tarifs" ),
						array( 'title' => 'Postelio', 'links' => "À propos|/a-propos\nContact|/contact" ),
					),
				),
				'socials'     => array(
					'type'   => 'repeater',
					'label'  => 'Réseaux sociaux',
					'fields' => array(
						'network' => array( 'type' => 'text', 'label' => 'Réseau' ),
						'url'     => array( 'type' => 'text', 'label' => 'Lien' ),
					),
					'default' => array(
						array( 'network' => 'LinkedIn', 'url' => 'https://linkedin.com' ),
					),
				),
				'legal_links' => array( 'type' => 'textarea', 'label' => 'Liens légaux (un par ligne : Libellé|/url)', 'default' => "Mentions légales|/mentions-legales\nConfidentialité|/confidentialite" ),
				'copyright'   => array( 'type' => 'text', 'label' => 'Copyright', 'default' => '© Postelio. Tous droits réservés.' ),
			),
		);
	}

	// ----------------------------------------------------------------------- APPARENCE

	private static function appearance(): array {
		return array(
			'label' => 'Apparence',
			'icon'  => '🎨',
			'type'  => 'single',
			'fields' => array(
				'color_primary' => array( 'type' => 'color', 'label' => 'Couleur primaire', 'default' => '#17324D' ),
				'color_accent'  => array( 'type' => 'color', 'label' => 'Couleur accent', 'default' => '#FF6B6B' ),
				'color_bg'      => array( 'type' => 'color', 'label' => 'Couleur de fond', 'default' => '#FAF7F1' ),
				'color_text'    => array( 'type' => 'color', 'label' => 'Couleur du texte', 'default' => '#566575' ),
				'font_headings' => array( 'type' => 'select', 'label' => 'Police des titres', 'default' => 'sans', 'options' => array( 'sans' => 'Sans-serif (défaut)', 'serif' => 'Serif', 'display' => 'Display' ) ),
				'font_body'     => array( 'type' => 'select', 'label' => 'Police du texte', 'default' => 'sans', 'options' => array( 'sans' => 'Sans-serif (défaut)', 'serif' => 'Serif' ) ),
				'base_size'     => array( 'type' => 'select', 'label' => 'Taille de base', 'default' => 'md', 'options' => array( 'sm' => 'Compact', 'md' => 'Standard', 'lg' => 'Confortable' ) ),
				'button_radius' => array( 'type' => 'select', 'label' => 'Arrondi des boutons', 'default' => 'pill', 'options' => array( 'sm' => 'Léger', 'md' => 'Moyen', 'pill' => 'Arrondi' ) ),
				'button_style'  => array( 'type' => 'select', 'label' => 'Style des boutons', 'default' => 'solid', 'options' => array( 'solid' => 'Plein', 'outline' => 'Contour' ) ),
			),
		);
	}

	// ----------------------------------------------------------------------- PAGES PRÉPARÉES (stubs)

	private static function jobs(): array {
		return array(
			'label' => 'Offres', 'icon' => '📄', 'type' => 'single', 'prepared' => true,
			'fields' => array(
				'hero_title'  => array( 'type' => 'text', 'label' => 'Titre du hero', 'default' => 'Toutes les offres' ),
				'hero_intro'  => array( 'type' => 'textarea', 'label' => 'Introduction', 'default' => '' ),
				'show_filters'=> array( 'type' => 'toggle', 'label' => 'Afficher les filtres', 'default' => true ),
				'empty_text'  => array( 'type' => 'text', 'label' => 'Texte « aucune offre »', 'default' => 'Aucune offre pour ces critères.' ),
				'cta_label'   => array( 'type' => 'text', 'label' => 'CTA', 'default' => '' ),
			),
		);
	}

	private static function companies(): array {
		return array(
			'label' => 'Entreprises', 'icon' => '🏢', 'type' => 'single', 'prepared' => true,
			'fields' => array(
				'hero_title' => array( 'type' => 'text', 'label' => 'Titre du hero', 'default' => 'Les entreprises' ),
				'hero_subtitle' => array( 'type' => 'text', 'label' => 'Sous-titre', 'default' => '' ),
				'show_search' => array( 'type' => 'toggle', 'label' => 'Afficher la recherche', 'default' => true ),
				'per_page'   => array( 'type' => 'number', 'label' => 'Nombre par page', 'default' => 12 ),
			),
		);
	}

	private static function skills(): array {
		return array(
			'label' => 'Savoir-faire', 'icon' => '📝', 'type' => 'single', 'prepared' => true,
			'fields' => array(
				'hero_title' => array( 'type' => 'text', 'label' => 'Titre du hero', 'default' => 'Savoir-faire' ),
				'hero_intro' => array( 'type' => 'textarea', 'label' => 'Introduction', 'default' => '' ),
				'cta_publish'=> array( 'type' => 'text', 'label' => 'CTA publier', 'default' => 'Publier un savoir-faire' ),
			),
		);
	}

	private static function blog(): array {
		return array(
			'label' => 'Conseils / Blog', 'icon' => '✍️', 'type' => 'single', 'prepared' => true,
			'fields' => array(
				'hero_title' => array( 'type' => 'text', 'label' => 'Titre de page', 'default' => 'Conseils & actualités' ),
				'hero_intro' => array( 'type' => 'textarea', 'label' => 'Introduction', 'default' => '' ),
			),
		);
	}

	private static function contact(): array {
		return array(
			'label' => 'Contact', 'icon' => '✉️', 'type' => 'single', 'prepared' => true,
			'fields' => array(
				'title'    => array( 'type' => 'text', 'label' => 'Titre', 'default' => 'Contactez-nous' ),
				'text'     => array( 'type' => 'textarea', 'label' => 'Texte', 'default' => '' ),
				'email'    => array( 'type' => 'text', 'label' => 'E-mail de contact affiché', 'default' => '' ),
				'phone'    => array( 'type' => 'text', 'label' => 'Téléphone affiché', 'default' => '' ),
				'subjects' => array( 'type' => 'textarea', 'label' => 'Sujets du formulaire (un par ligne)', 'default' => "Candidat\nEntreprise\nAutre" ),
			),
		);
	}

	private static function seo(): array {
		return array(
			'label' => 'SEO', 'icon' => '🔎', 'type' => 'single', 'prepared' => true,
			'fields' => array(
				'site_name'      => array( 'type' => 'text', 'label' => 'Nom du site', 'default' => 'Postelio' ),
				'title_template' => array( 'type' => 'text', 'label' => 'Template de titre', 'default' => '%page% — Postelio' ),
				'description'    => array( 'type' => 'textarea', 'label' => 'Meta description par défaut', 'default' => '' ),
				'social_image'   => array( 'type' => 'media', 'label' => 'Image sociale par défaut', 'default' => '' ),
				'indexation'     => array( 'type' => 'toggle', 'label' => 'Autoriser l\'indexation', 'default' => true ),
			),
		);
	}
}
