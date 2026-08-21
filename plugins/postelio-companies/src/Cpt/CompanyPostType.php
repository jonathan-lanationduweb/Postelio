<?php
/**
 * Type de contenu `postelio_company` (data-model.md : Company = CPT).
 *
 * Non public (pas d'UI front WP, pas d'exposition REST native) : l'accès passe
 * par les endpoints du plugin. Supporte titre (nom), éditeur (présentation),
 * auteur (recruteur créateur) et image à la une (logo).
 *
 * @package Postelio\Companies\Cpt
 */

namespace Postelio\Companies\Cpt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompanyPostType {

	public const TYPE = 'postelio_company';

	public function register(): void {
		add_action( 'init', array( $this, 'register_type' ), 5 );
	}

	public function register_type(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Entreprises', 'postelio-companies' ),
					'singular_name' => __( 'Entreprise', 'postelio-companies' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false, // exposé via nos propres endpoints uniquement
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'editor', 'author', 'thumbnail' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}
}
