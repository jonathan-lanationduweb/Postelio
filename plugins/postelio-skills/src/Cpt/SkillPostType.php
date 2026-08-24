<?php
/**
 * CPT `postelio_skill` (savoir-faire) + taxonomies catégorie/tags. Comme les offres :
 * `public=false`, `publicly_queryable=false` → AUCUN rendu WP public ; l'exposition passe
 * uniquement par l'API Postelio (SEO livré en contrat, pas rendu ici). Le `post_status` WP
 * reste `publish` en interne ; le statut MÉTIER (draft/published/archived) vit en meta.
 *
 * @package Postelio\Skills\Cpt
 */

namespace Postelio\Skills\Cpt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SkillPostType {

	public const TYPE     = 'postelio_skill';
	public const TAX_CAT  = 'postelio_skill_category';
	public const TAX_TAG  = 'postelio_skill_tag';

	public function register(): void {
		add_action( 'init', array( $this, 'register_type' ), 5 );
	}

	public function register_type(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Savoir-faire', 'postelio-skills' ),
					'singular_name' => __( 'Savoir-faire', 'postelio-skills' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'editor', 'author', 'thumbnail', 'revisions' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);

		register_taxonomy(
			self::TAX_CAT,
			self::TYPE,
			array(
				'labels'            => array( 'name' => __( 'Catégories de savoir-faire', 'postelio-skills' ) ),
				'public'            => false,
				'publicly_queryable' => false,
				'show_ui'           => false,
				'show_in_rest'      => false,
				'hierarchical'      => true, // catégorie éditable en base
			)
		);

		register_taxonomy(
			self::TAX_TAG,
			self::TYPE,
			array(
				'labels'            => array( 'name' => __( 'Tags de savoir-faire', 'postelio-skills' ) ),
				'public'            => false,
				'publicly_queryable' => false,
				'show_ui'           => false,
				'show_in_rest'      => false,
				'hierarchical'      => false, // tags libres
			)
		);
	}
}
