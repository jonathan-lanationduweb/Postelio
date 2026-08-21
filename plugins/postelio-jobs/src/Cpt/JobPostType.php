<?php
/**
 * Type de contenu `postelio_job` (data-model.md : Job = CPT).
 * Non public : accès via les endpoints du plugin uniquement.
 *
 * @package Postelio\Jobs\Cpt
 */

namespace Postelio\Jobs\Cpt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobPostType {

	public const TYPE = 'postelio_job';

	public function register(): void {
		add_action( 'init', array( $this, 'register_type' ), 5 );
	}

	public function register_type(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Offres', 'postelio-jobs' ),
					'singular_name' => __( 'Offre', 'postelio-jobs' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'editor', 'author' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}
}
