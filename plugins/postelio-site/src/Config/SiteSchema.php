<?php
/**
 * Schéma de configuration du SITE public Postelio. SOURCE DE VÉRITÉ de la structure éditable :
 * pages → sections → champs (+ valeurs par défaut). Données PURES (aucun appel WordPress) →
 * testable et réutilisable par l'éditeur admin, l'aperçu et l'API REST publique.
 *
 * Types de page :
 *   - « sections » : sections activables/réordonnables (Accueil, Offres, Entreprises…) ;
 *   - « single »   : un groupe de champs, éventuellement réparti en `groups` (Navigation, Footer,
 *                    Apparence).
 *
 * Types de champ : text · textarea · toggle · media · color · select · number · repeater ·
 *                  collection (référence vers du contenu métier, résolue via les façades).
 *
 * VERSION = 2 (Phase 2) : ajout des pages publiques complètes + SEO structuré + section Identité.
 * Les pages Phase 1 (home/navigation/footer/appearance) restent rétro-compatibles (ajouts avec
 * défauts). Les pages « préparées » de la Phase 1 (jobs/companies/skills/blog/contact), jamais
 * finalisées, changent de forme → d'où la montée de version.
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

	/** Version du schéma de configuration. */
	public const VERSION = 2;

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
			'advice'     => self::advice(),
			'contact'    => self::contact(),
			'seo'        => self::seo(),
		);
	}

	/** @return string[] */
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

	/** @return array<string,mixed> */
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
			case 'repeater':
			case 'collection': return array();
			default: return '';
		}
	}

	// ====================================================================== BRIQUES RÉUTILISABLES

	/** Champs d'un Hero (avec ou sans barre de recherche). */
	private static function hero_fields( string $title, string $subtitle, bool $with_search = false ): array {
		$f = array(
			'title'      => array( 'type' => 'text', 'label' => 'Titre', 'default' => $title ),
			'subtitle'   => array( 'type' => 'text', 'label' => 'Sous-titre', 'default' => $subtitle ),
			'text'       => array( 'type' => 'textarea', 'label' => 'Texte', 'default' => '' ),
			'background'  => array( 'type' => 'media', 'label' => 'Image de fond', 'default' => '' ),
			'overlay'     => array( 'type' => 'toggle', 'label' => 'Overlay sombre', 'default' => true ),
			'align'       => array( 'type' => 'select', 'label' => 'Alignement', 'default' => 'left', 'options' => array( 'left' => 'Gauche', 'center' => 'Centré' ) ),
			'height'      => array( 'type' => 'select', 'label' => 'Hauteur', 'default' => 'medium', 'options' => array( 'medium' => 'Moyenne', 'large' => 'Grande', 'full' => 'Plein écran' ) ),
			'text_light'  => array( 'type' => 'toggle', 'label' => 'Texte clair', 'default' => true ),
		);
		if ( $with_search ) {
			$f['search_placeholder'] = array( 'type' => 'text', 'label' => 'Placeholder recherche', 'default' => 'Rechercher…' );
		}
		return $f;
	}

	/** Section de recherche (présentation uniquement). */
	private static function search_section( string $role_ph, string $city_ph ): array {
		return array(
			'label'  => 'Recherche',
			'fields' => array(
				'title'            => array( 'type' => 'text', 'label' => 'Titre', 'default' => '' ),
				'placeholder_role' => array( 'type' => 'text', 'label' => 'Placeholder principal', 'default' => $role_ph ),
				'placeholder_city' => array( 'type' => 'text', 'label' => 'Placeholder localisation', 'default' => $city_ph ),
				'button_label'     => array( 'type' => 'text', 'label' => 'Bouton', 'default' => 'Rechercher' ),
				'show_guided'      => array( 'type' => 'toggle', 'label' => 'Recherche guidée', 'default' => false, 'help' => 'Activé seulement si le front l\'implémente.' ),
			),
		);
	}

	/**
	 * Section de filtres : présentation uniquement (l'admin déclare les filtres à AFFICHER + leur
	 * ordre ; on n'invente aucun filtre côté moteur métier).
	 */
	private static function filters_section( array $default_filters ): array {
		return array(
			'label'  => 'Filtres',
			'fields' => array(
				'title'   => array( 'type' => 'text', 'label' => 'Titre', 'default' => 'Filtrer' ),
				'filters' => array(
					'type'   => 'repeater',
					'label'  => 'Filtres visibles (ordre = affichage)',
					'fields' => array(
						'label'   => array( 'type' => 'text', 'label' => 'Libellé' ),
						'visible' => array( 'type' => 'toggle', 'label' => 'Visible' ),
					),
					'default' => $default_filters,
				),
			),
		);
	}

	/** Section « collection » (résultats / mise en avant) avec mode auto/manuel + sélecteur. */
	private static function collection_section( string $label, string $subtitle, string $ref_type, int $count, string $cta_url, string $cta_label ): array {
		return array(
			'label'  => $label,
			'fields' => array(
				'title'      => array( 'type' => 'text', 'label' => 'Titre', 'default' => $label ),
				'subtitle'   => array( 'type' => 'text', 'label' => 'Sous-titre', 'default' => $subtitle ),
				'mode'       => array( 'type' => 'select', 'label' => 'Contenu', 'default' => 'auto', 'options' => array( 'auto' => 'Automatique', 'manual' => 'Sélection manuelle' ) ),
				'count'      => array( 'type' => 'number', 'label' => 'Nombre (mode auto)', 'default' => $count, 'min' => 1, 'max' => 24 ),
				'items'      => array( 'type' => 'collection', 'ref_type' => $ref_type, 'label' => 'Sélection (mode manuel)', 'default' => array(), 'help' => 'Recherchez et ajoutez du contenu ; le stockage se fait par référence stable.' ),
				'empty_text' => array( 'type' => 'text', 'label' => 'Texte si vide', 'default' => 'Aucun résultat pour le moment.' ),
				'cta_label'  => array( 'type' => 'text', 'label' => 'Bouton', 'default' => $cta_label ),
				'cta_url'    => array( 'type' => 'text', 'label' => 'Lien du bouton', 'default' => $cta_url ),
			),
		);
	}

	private static function cta_section( string $title, string $text, string $label, string $url ): array {
		return array(
			'label'  => 'Appel à l\'action',
			'fields' => array(
				'title'        => array( 'type' => 'text', 'label' => 'Titre', 'default' => $title ),
				'text'         => array( 'type' => 'textarea', 'label' => 'Texte', 'default' => $text ),
				'button_label' => array( 'type' => 'text', 'label' => 'Bouton', 'default' => $label ),
				'button_url'   => array( 'type' => 'text', 'label' => 'Lien', 'default' => $url ),
			),
		);
	}

	private static function arguments_section(): array {
		return array(
			'label'  => 'Arguments',
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
		);
	}

	// ====================================================================== ACCUEIL

	private static function home(): array {
		return array(
			'label' => 'Accueil', 'icon' => '🏠', 'type' => 'sections',
			'sections' => array(
				'hero' => array( 'label' => 'Hero', 'reorderable' => false, 'fields' => array_merge(
					self::hero_fields( 'Trouvez le poste administratif qui vous ressemble', 'La plateforme emploi dédiée aux métiers du secrétariat et de l\'assistanat', true ),
					array(
						'cta_primary_label'   => array( 'type' => 'text', 'label' => 'CTA principal — libellé', 'default' => 'Voir les offres' ),
						'cta_primary_url'     => array( 'type' => 'text', 'label' => 'CTA principal — lien', 'default' => '/offres' ),
						'cta_secondary_label' => array( 'type' => 'text', 'label' => 'CTA secondaire — libellé', 'default' => 'Déposer mon profil' ),
						'cta_secondary_url'   => array( 'type' => 'text', 'label' => 'CTA secondaire — lien', 'default' => '/inscription' ),
						'video_intro'         => array( 'type' => 'toggle', 'label' => 'Activer l\'intro vidéo', 'default' => false, 'help' => 'Le comportement cinématique est géré par le front ; on n\'active ici que le paramètre.' ),
						'poster'              => array( 'type' => 'media', 'label' => 'Poster vidéo', 'default' => '' ),
					)
				) ),
				'search'     => self::search_section( 'Assistant(e), secrétaire…', 'Ville ou code postal' ),
				'categories' => self::collection_section( 'Catégories', 'Explorez par métier', 'skill', 8, '/offres', 'Voir les catégories' ),
				'jobs'       => self::collection_section( 'Offres à la une', 'Les dernières opportunités', 'job', 6, '/offres', 'Voir toutes les offres' ),
				'companies'  => self::collection_section( 'Entreprises qui recrutent', 'Elles nous font confiance', 'company', 8, '/entreprises', 'Voir toutes les entreprises' ),
				'skills'     => self::collection_section( 'Savoir-faire', 'Conseils et ressources métier', 'skill', 3, '/savoir-faire', 'Découvrir' ),
				'arguments'  => self::arguments_section(),
				'articles'   => self::collection_section( 'Conseils & actualités', 'Le blog Postelio', 'article', 3, '/conseils', 'Lire le blog' ),
				'cta'        => self::cta_section( 'Prêt à trouver votre prochain poste ?', 'Rejoignez des milliers de candidats et d\'entreprises.', 'Créer mon compte', '/inscription' ),
			),
		);
	}

	// ====================================================================== NAVIGATION

	private static function navigation(): array {
		return array(
			'label' => 'Navigation', 'icon' => '🧭', 'type' => 'single',
			'fields' => array(
				'use_identity_logo' => array( 'type' => 'toggle', 'label' => 'Utiliser le logo global (Apparence → Identité)', 'default' => true ),
				'logo'        => array( 'type' => 'media', 'label' => 'Logo (override)', 'default' => '' ),
				'brand_text'  => array( 'type' => 'text', 'label' => 'Nom de marque', 'default' => 'Postelio' ),
				'items'       => array(
					'type'   => 'repeater', 'label' => 'Liens du menu',
					'fields' => array(
						'label'      => array( 'type' => 'text', 'label' => 'Libellé' ),
						'url'        => array( 'type' => 'text', 'label' => 'Lien' ),
						'visibility' => array( 'type' => 'select', 'label' => 'Visibilité', 'options' => array( 'all' => 'Tout le monde', 'guest' => 'Déconnecté', 'auth' => 'Connecté' ) ),
					),
					'default' => array(
						array( 'label' => 'Offres', 'url' => '/offres', 'visibility' => 'all' ),
						array( 'label' => 'Entreprises', 'url' => '/entreprises', 'visibility' => 'all' ),
						array( 'label' => 'Savoir-faire', 'url' => '/savoir-faire', 'visibility' => 'all' ),
						array( 'label' => 'Conseils', 'url' => '/conseils', 'visibility' => 'all' ),
					),
				),
				'show_login'  => array( 'type' => 'toggle', 'label' => 'Bouton Connexion', 'default' => true ),
				'login_label' => array( 'type' => 'text', 'label' => 'Libellé Connexion', 'default' => 'Connexion' ),
				'login_url'   => array( 'type' => 'text', 'label' => 'Lien Connexion', 'default' => '/connexion' ),
				'show_signup' => array( 'type' => 'toggle', 'label' => 'Bouton Inscription', 'default' => true ),
				'signup_label'=> array( 'type' => 'text', 'label' => 'Libellé Inscription', 'default' => 'Inscription' ),
				'signup_url'  => array( 'type' => 'text', 'label' => 'Lien Inscription', 'default' => '/inscription' ),
			),
			'groups' => array(
				array( 'label' => 'Marque', 'fields' => array( 'use_identity_logo', 'logo', 'brand_text' ) ),
				array( 'label' => 'Liens', 'fields' => array( 'items' ) ),
				array( 'label' => 'Boutons', 'fields' => array( 'show_login', 'login_label', 'login_url', 'show_signup', 'signup_label', 'signup_url' ) ),
			),
		);
	}

	// ====================================================================== FOOTER

	private static function footer(): array {
		return array(
			'label' => 'Footer', 'icon' => '👣', 'type' => 'single',
			'fields' => array(
				'use_identity_logo' => array( 'type' => 'toggle', 'label' => 'Utiliser le logo global (Apparence → Identité)', 'default' => true ),
				'logo'        => array( 'type' => 'media', 'label' => 'Logo (override)', 'default' => '' ),
				'brand_text'  => array( 'type' => 'text', 'label' => 'Nom de marque', 'default' => 'Postelio' ),
				'description' => array( 'type' => 'textarea', 'label' => 'Description', 'default' => 'La plateforme emploi des métiers administratifs.' ),
				'columns'     => array(
					'type'   => 'repeater', 'label' => 'Colonnes de liens',
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
					'type'   => 'repeater', 'label' => 'Réseaux sociaux',
					'fields' => array(
						'network' => array( 'type' => 'text', 'label' => 'Réseau' ),
						'url'     => array( 'type' => 'text', 'label' => 'Lien' ),
					),
					'default' => array( array( 'network' => 'LinkedIn', 'url' => 'https://linkedin.com' ) ),
				),
				'legal_links' => array( 'type' => 'textarea', 'label' => 'Liens légaux (un par ligne : Libellé|/url)', 'default' => "Mentions légales|/mentions-legales\nConfidentialité|/confidentialite" ),
				'copyright'   => array( 'type' => 'text', 'label' => 'Copyright', 'default' => '© Postelio. Tous droits réservés.' ),
			),
			'groups' => array(
				array( 'label' => 'Marque', 'fields' => array( 'use_identity_logo', 'logo', 'brand_text', 'description' ) ),
				array( 'label' => 'Colonnes de liens', 'fields' => array( 'columns' ) ),
				array( 'label' => 'Réseaux & mentions', 'fields' => array( 'socials', 'legal_links', 'copyright' ) ),
			),
		);
	}

	// ====================================================================== APPARENCE

	private static function appearance(): array {
		return array(
			'label' => 'Apparence', 'icon' => '🎨', 'type' => 'single', 'resettable' => true,
			'fields' => array(
				// Identité (ressources globales).
				'logo'          => array( 'type' => 'media', 'label' => 'Logo principal', 'default' => '' ),
				'logo_light'    => array( 'type' => 'media', 'label' => 'Logo clair (fonds sombres)', 'default' => '' ),
				'favicon'       => array( 'type' => 'media', 'label' => 'Favicon', 'default' => '' ),
				'social_image'  => array( 'type' => 'media', 'label' => 'Image sociale par défaut', 'default' => '' ),
				// Couleurs.
				'color_primary' => array( 'type' => 'color', 'label' => 'Couleur primaire', 'default' => '#17324D' ),
				'color_accent'  => array( 'type' => 'color', 'label' => 'Couleur accent', 'default' => '#FF6B6B' ),
				'color_bg'      => array( 'type' => 'color', 'label' => 'Couleur de fond', 'default' => '#FAF7F1' ),
				'color_text'    => array( 'type' => 'color', 'label' => 'Couleur du texte', 'default' => '#566575' ),
				// Typographie.
				'font_headings' => array( 'type' => 'select', 'label' => 'Police des titres', 'default' => 'sans', 'options' => array( 'sans' => 'Sans-serif (défaut)', 'serif' => 'Serif', 'display' => 'Display' ) ),
				'font_body'     => array( 'type' => 'select', 'label' => 'Police du texte', 'default' => 'sans', 'options' => array( 'sans' => 'Sans-serif (défaut)', 'serif' => 'Serif' ) ),
				'base_size'     => array( 'type' => 'select', 'label' => 'Taille de base', 'default' => 'md', 'options' => array( 'sm' => 'Compact', 'md' => 'Standard', 'lg' => 'Confortable' ) ),
				// Boutons.
				'button_radius' => array( 'type' => 'select', 'label' => 'Arrondi des boutons', 'default' => 'pill', 'options' => array( 'sm' => 'Léger', 'md' => 'Moyen', 'pill' => 'Arrondi' ) ),
				'button_style'  => array( 'type' => 'select', 'label' => 'Style des boutons', 'default' => 'solid', 'options' => array( 'solid' => 'Plein', 'outline' => 'Contour' ) ),
			),
			'groups' => array(
				array( 'label' => 'Identité', 'fields' => array( 'logo', 'logo_light', 'favicon', 'social_image' ) ),
				array( 'label' => 'Couleurs', 'fields' => array( 'color_primary', 'color_accent', 'color_bg', 'color_text' ) ),
				array( 'label' => 'Typographie', 'fields' => array( 'font_headings', 'font_body', 'base_size' ) ),
				array( 'label' => 'Boutons', 'fields' => array( 'button_radius', 'button_style' ) ),
			),
		);
	}

	// ====================================================================== OFFRES (page publique)

	private static function jobs(): array {
		return array(
			'label' => 'Offres', 'icon' => '📄', 'type' => 'sections', 'front_path' => '/offres',
			'sections' => array(
				'hero'    => array( 'label' => 'Hero', 'reorderable' => false, 'fields' => self::hero_fields( 'Toutes les offres', 'Trouvez le poste administratif fait pour vous', true ) ),
				'search'  => self::search_section( 'Métier, mot-clé…', 'Ville ou code postal' ),
				'filters' => self::filters_section( array(
					array( 'label' => 'Type de contrat', 'visible' => true ),
					array( 'label' => 'Localisation', 'visible' => true ),
					array( 'label' => 'Télétravail', 'visible' => true ),
					array( 'label' => 'Catégorie', 'visible' => true ),
				) ),
				'results' => array(
					'label'  => 'Résultats',
					'fields' => array(
						'title'      => array( 'type' => 'text', 'label' => 'Titre', 'default' => 'Offres disponibles' ),
						'text'       => array( 'type' => 'text', 'label' => 'Texte d\'introduction', 'default' => '' ),
						'per_page'   => array( 'type' => 'number', 'label' => 'Nombre par page', 'default' => 12, 'min' => 4, 'max' => 48, 'help' => 'Appliqué par le front s\'il le supporte.' ),
						'empty_text' => array( 'type' => 'text', 'label' => 'Texte « aucune offre »', 'default' => 'Aucune offre ne correspond à ces critères.' ),
					),
				),
				'arguments' => self::arguments_section(),
				'cta'       => self::cta_section( 'Recruteur ?', 'Publiez votre offre et touchez des candidats qualifiés.', 'Publier une offre', '/entreprise' ),
			),
		);
	}

	// ====================================================================== ENTREPRISES (annuaire)

	private static function companies(): array {
		return array(
			'label' => 'Entreprises', 'icon' => '🏢', 'type' => 'sections', 'front_path' => '/entreprises',
			'sections' => array(
				'hero'     => array( 'label' => 'Hero', 'reorderable' => false, 'fields' => self::hero_fields( 'Les entreprises qui recrutent', 'Découvrez les employeurs de la plateforme', true ) ),
				'search'   => self::search_section( 'Nom d\'entreprise…', 'Ville' ),
				'filters'  => self::filters_section( array(
					array( 'label' => 'Secteur', 'visible' => true ),
					array( 'label' => 'Taille', 'visible' => true ),
					array( 'label' => 'Localisation', 'visible' => true ),
				) ),
				'featured' => self::collection_section( 'Entreprises mises en avant', 'Notre sélection', 'company', 6, '/entreprises', 'Voir toutes les entreprises' ),
				'arguments'=> self::arguments_section(),
				'cta'      => self::cta_section( 'Votre entreprise recrute ?', 'Rejoignez Postelio et publiez vos offres.', 'Créer un espace entreprise', '/entreprise' ),
			),
		);
	}

	// ====================================================================== SAVOIR-FAIRE

	private static function skills(): array {
		return array(
			'label' => 'Savoir-faire', 'icon' => '📝', 'type' => 'sections', 'front_path' => '/savoir-faire',
			'sections' => array(
				'hero'       => array( 'label' => 'Hero', 'reorderable' => false, 'fields' => self::hero_fields( 'Savoir-faire', 'Conseils, méthodes et ressources des métiers administratifs' ) ),
				'categories' => array(
					'label'  => 'Catégories',
					'fields' => array(
						'title'   => array( 'type' => 'text', 'label' => 'Titre', 'default' => 'Explorer par thème' ),
						'items'   => array( 'type' => 'repeater', 'label' => 'Catégories visibles', 'fields' => array( 'label' => array( 'type' => 'text', 'label' => 'Libellé' ) ), 'default' => array( array( 'label' => 'Bureautique' ), array( 'label' => 'Organisation' ), array( 'label' => 'Communication' ) ) ),
					),
				),
				'featured'   => self::collection_section( 'Contenus mis en avant', 'À ne pas manquer', 'skill', 6, '/savoir-faire', 'Tout voir' ),
				'feed'       => array(
					'label'  => 'Flux',
					'fields' => array(
						'title'      => array( 'type' => 'text', 'label' => 'Titre', 'default' => 'Derniers savoir-faire' ),
						'per_page'   => array( 'type' => 'number', 'label' => 'Nombre par page', 'default' => 9, 'min' => 3, 'max' => 30 ),
						'empty_text' => array( 'type' => 'text', 'label' => 'Texte si vide', 'default' => 'Aucun contenu publié pour le moment.' ),
					),
				),
				'cta'        => array(
					'label'  => 'CTA publication',
					'fields' => array(
						'title'        => array( 'type' => 'text', 'label' => 'Titre', 'default' => 'Partagez votre expertise' ),
						'text'         => array( 'type' => 'textarea', 'label' => 'Texte', 'default' => 'Publiez un savoir-faire et aidez la communauté.' ),
						'button_label' => array( 'type' => 'text', 'label' => 'Bouton', 'default' => 'Publier un savoir-faire' ),
						'button_url'   => array( 'type' => 'text', 'label' => 'Lien', 'default' => '/savoir-faire/nouveau' ),
					),
				),
			),
		);
	}

	// ====================================================================== CONSEILS (blog)

	private static function advice(): array {
		return array(
			'label' => 'Conseils', 'icon' => '✍️', 'type' => 'sections', 'front_path' => '/conseils',
			'source_note' => 'Source éditoriale : articles WordPress (Posts). La sélection stocke des références (ID), jamais le contenu.',
			'sections' => array(
				'hero'       => array( 'label' => 'Hero', 'reorderable' => false, 'fields' => self::hero_fields( 'Conseils & actualités', 'Le blog des métiers administratifs' ) ),
				'categories' => array(
					'label'  => 'Catégories',
					'fields' => array(
						'title' => array( 'type' => 'text', 'label' => 'Titre', 'default' => 'Thématiques' ),
						'items' => array( 'type' => 'repeater', 'label' => 'Catégories visibles', 'fields' => array( 'label' => array( 'type' => 'text', 'label' => 'Libellé' ) ), 'default' => array( array( 'label' => 'Carrière' ), array( 'label' => 'CV & candidature' ), array( 'label' => 'Actualités' ) ) ),
					),
				),
				'featured'   => self::collection_section( 'Articles mis en avant', 'À la une', 'article', 3, '/conseils', 'Tous les articles' ),
				'feed'       => array(
					'label'  => 'Flux',
					'fields' => array(
						'title'      => array( 'type' => 'text', 'label' => 'Titre', 'default' => 'Derniers articles' ),
						'per_page'   => array( 'type' => 'number', 'label' => 'Nombre par page', 'default' => 9, 'min' => 3, 'max' => 30 ),
						'empty_text' => array( 'type' => 'text', 'label' => 'Texte si vide', 'default' => 'Aucun article pour le moment.' ),
					),
				),
				'cta'        => self::cta_section( 'Restez informé', 'Recevez nos conseils par e-mail.', 'S\'abonner', '/inscription' ),
			),
		);
	}

	// ====================================================================== CONTACT

	private static function contact(): array {
		return array(
			'label' => 'Contact', 'icon' => '✉️', 'type' => 'sections', 'front_path' => '/contact',
			'backend_note' => 'Aucun backend d\'envoi de formulaire n\'existe encore : cet écran configure UNIQUEMENT l\'affichage. Le traitement des soumissions sera un chantier séparé.',
			'sections' => array(
				'hero'    => array( 'label' => 'Hero', 'reorderable' => false, 'fields' => self::hero_fields( 'Contactez-nous', 'Une question ? Notre équipe vous répond.' ) ),
				'intro'   => array(
					'label'  => 'Introduction',
					'fields' => array(
						'text' => array( 'type' => 'textarea', 'label' => 'Texte d\'introduction', 'default' => '' ),
					),
				),
				'coordinates' => array(
					'label'  => 'Coordonnées publiques',
					'fields' => array(
						'email'   => array( 'type' => 'text', 'label' => 'E-mail affiché', 'default' => '' ),
						'phone'   => array( 'type' => 'text', 'label' => 'Téléphone affiché', 'default' => '' ),
						'address' => array( 'type' => 'textarea', 'label' => 'Adresse affichée', 'default' => '' ),
					),
				),
				'form' => array(
					'label'  => 'Formulaire',
					'fields' => array(
						'name_label'      => array( 'type' => 'text', 'label' => 'Libellé « Nom »', 'default' => 'Votre nom' ),
						'email_label'     => array( 'type' => 'text', 'label' => 'Libellé « E-mail »', 'default' => 'Votre e-mail' ),
						'message_label'   => array( 'type' => 'text', 'label' => 'Libellé « Message »', 'default' => 'Votre message' ),
						'message_ph'      => array( 'type' => 'text', 'label' => 'Placeholder message', 'default' => 'Comment pouvons-nous vous aider ?' ),
						'subjects'        => array( 'type' => 'repeater', 'label' => 'Sujets proposés', 'fields' => array( 'label' => array( 'type' => 'text', 'label' => 'Sujet' ) ), 'default' => array( array( 'label' => 'Candidat' ), array( 'label' => 'Entreprise' ), array( 'label' => 'Autre' ) ) ),
						'button_label'    => array( 'type' => 'text', 'label' => 'Bouton d\'envoi', 'default' => 'Envoyer' ),
						'consent_text'    => array( 'type' => 'textarea', 'label' => 'Texte de consentement (RGPD)', 'default' => 'En envoyant ce formulaire, vous acceptez notre politique de confidentialité.' ),
						'confirm_text'    => array( 'type' => 'text', 'label' => 'Message de confirmation', 'default' => 'Merci, votre message a bien été envoyé.' ),
					),
				),
				'extra' => array(
					'label'  => 'Informations complémentaires',
					'enabled_default' => false,
					'fields' => array(
						'text' => array( 'type' => 'textarea', 'label' => 'Texte', 'default' => '' ),
					),
				),
			),
		);
	}

	// ====================================================================== SEO

	/** Sous-champs SEO communs à chaque page. */
	private static function seo_page_fields( bool $with_noindex = true ): array {
		$f = array(
			'seo_title'          => array( 'type' => 'text', 'label' => 'Titre SEO', 'counter' => 60, 'default' => '' ),
			'meta_description'   => array( 'type' => 'textarea', 'label' => 'Meta description', 'counter' => 155, 'default' => '' ),
			'social_title'       => array( 'type' => 'text', 'label' => 'Titre social (Open Graph)', 'default' => '' ),
			'social_description' => array( 'type' => 'text', 'label' => 'Description sociale', 'default' => '' ),
			'social_image'       => array( 'type' => 'media', 'label' => 'Image sociale', 'default' => '' ),
		);
		if ( $with_noindex ) {
			$f['noindex'] = array( 'type' => 'toggle', 'label' => 'Ne pas indexer (noindex)', 'default' => false );
		}
		return $f;
	}

	private static function seo(): array {
		$section = function ( string $label ) {
			return array( 'label' => $label, 'no_toggle' => true, 'reorderable' => false, 'fields' => self::seo_page_fields() );
		};
		return array(
			'label' => 'SEO', 'icon' => '🔎', 'type' => 'sections', 'seo' => true,
			'sections' => array(
				'global' => array(
					'label' => 'Global', 'no_toggle' => true, 'reorderable' => false,
					'fields' => array(
						'site_name'          => array( 'type' => 'text', 'label' => 'Nom du site', 'default' => 'Postelio' ),
						'title_template'     => array( 'type' => 'text', 'label' => 'Template de titre', 'default' => '%page% — Postelio', 'help' => 'Utilisez %page% pour insérer le titre de la page.' ),
						'default_description' => array( 'type' => 'textarea', 'label' => 'Meta description par défaut', 'counter' => 155, 'default' => '' ),
						'default_social_image'=> array( 'type' => 'media', 'label' => 'Image sociale par défaut', 'default' => '' ),
					),
				),
				'home'      => $section( 'Accueil' ),
				'jobs'      => $section( 'Offres' ),
				'companies' => $section( 'Entreprises' ),
				'skills'    => $section( 'Savoir-faire' ),
				'advice'    => $section( 'Conseils' ),
				'contact'   => $section( 'Contact' ),
			),
		);
	}
}
