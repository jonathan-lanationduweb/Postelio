<?php
/**
 * Résolution des références de contenu pour les sélecteurs du Site Builder (offres, entreprises,
 * savoir-faire, articles). Lecture SEULE via les FAÇADES propriétaires (JobAdminDirectory,
 * CompanyAdminDirectory, SkillAdminDirectory) et les Posts WordPress pour les articles. Aucune
 * requête SQL cross-domaine, aucune duplication de données métier : on ne renvoie qu'un libellé et
 * un état d'affichage. Dégrade proprement si un module est absent.
 *
 * @package Postelio\Site\Config
 */

namespace Postelio\Site\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ContentReferences {

	/** Types de référence supportés. */
	public const TYPES = array( 'job', 'company', 'skill', 'article' );

	/**
	 * Recherche par mot-clé.
	 *
	 * @return array<int,array{id:string,label:string,sub:string,state:string}>
	 */
	public static function search( string $type, string $q, int $limit = 20 ): array {
		switch ( $type ) {
			case 'job':     return self::search_facade( '\\Postelio\\Jobs\\Api\\JobAdminDirectory', $q, $limit, 'title', 'company' );
			case 'company': return self::search_facade( '\\Postelio\\Companies\\Api\\CompanyAdminDirectory', $q, $limit, 'nom', 'ville' );
			case 'skill':   return self::search_facade( '\\Postelio\\Skills\\Api\\SkillAdminDirectory', $q, $limit, 'title', 'author_name' );
			case 'article': return self::search_articles( $q, $limit );
		}
		return array();
	}

	/**
	 * Résout des références stockées.
	 *
	 * @param string[] $ids
	 * @return array<int,array{id:string,label:string,sub:string,state:string,missing:bool}>
	 */
	public static function resolve( string $type, array $ids ): array {
		$out = array();
		foreach ( array_slice( $ids, 0, 50 ) as $id ) {
			$id = (string) $id;
			$row = self::resolve_one( $type, $id );
			$out[] = null === $row
				? array( 'id' => $id, 'label' => '', 'sub' => '', 'state' => '', 'missing' => true )
				: array_merge( $row, array( 'id' => $id, 'missing' => false ) );
		}
		return $out;
	}

	// ------------------------------------------------------------------ interne

	/**
	 * @return array<int,array{id:string,label:string,sub:string,state:string}>
	 */
	private static function search_facade( string $fqcn, string $q, int $limit, string $label_key, string $sub_key ): array {
		if ( ! class_exists( $fqcn ) || ! method_exists( $fqcn, 'list' ) ) {
			return array();
		}
		$res   = (array) call_user_func( array( $fqcn, 'list' ), array( 'q' => $q ), 1, $limit );
		$items = (array) ( $res['items'] ?? array() );
		$out   = array();
		foreach ( $items as $it ) {
			$it  = (array) $it;
			$sub = $it[ $sub_key ] ?? '';
			if ( is_array( $sub ) ) {
				$sub = (string) ( $sub['nom'] ?? '' );
			}
			$out[] = array(
				'id'    => (string) ( $it['uuid'] ?? '' ),
				'label' => (string) ( $it[ $label_key ] ?? '—' ),
				'sub'   => (string) $sub,
				'state' => (string) ( $it['status'] ?? '' ),
			);
		}
		return $out;
	}

	/** @return array<int,array{id:string,label:string,sub:string,state:string}> */
	private static function search_articles( string $q, int $limit ): array {
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
			's'              => $q,
			'posts_per_page' => $limit,
			'suppress_filters' => true,
		) );
		$out = array();
		foreach ( (array) $posts as $p ) {
			$out[] = array(
				'id'    => (string) $p->ID,
				'label' => (string) ( '' !== $p->post_title ? $p->post_title : '(sans titre)' ),
				'sub'   => (string) get_the_date( 'd/m/Y', $p ),
				'state' => (string) $p->post_status,
			);
		}
		return $out;
	}

	/** @return array{label:string,sub:string,state:string}|null */
	private static function resolve_one( string $type, string $id ) {
		if ( 'article' === $type ) {
			$p = get_post( (int) $id );
			if ( ! $p || 'post' !== $p->post_type ) {
				return null;
			}
			return array( 'label' => (string) ( '' !== $p->post_title ? $p->post_title : '(sans titre)' ), 'sub' => (string) get_the_date( 'd/m/Y', $p ), 'state' => (string) $p->post_status );
		}

		$map = array(
			'job'     => array( '\\Postelio\\Jobs\\Api\\JobAdminDirectory', 'titre', 'company' ),
			'company' => array( '\\Postelio\\Companies\\Api\\CompanyAdminDirectory', 'nom', 'ville_siege' ),
			'skill'   => array( '\\Postelio\\Skills\\Api\\SkillAdminDirectory', 'title', 'author_name' ),
		);
		if ( ! isset( $map[ $type ] ) ) {
			return null;
		}
		list( $fqcn, $label_key, $sub_key ) = $map[ $type ];
		if ( ! class_exists( $fqcn ) || ! method_exists( $fqcn, 'detail' ) ) {
			return null;
		}
		$d = call_user_func( array( $fqcn, 'detail' ), $id );
		if ( ! is_array( $d ) ) {
			return null;
		}
		// L'état peut être imbriqué (entreprise → verification.status).
		$state = (string) ( $d['status'] ?? ( isset( $d['verification']['status'] ) ? $d['verification']['status'] : '' ) );
		$sub   = $d[ $sub_key ] ?? '';
		if ( is_array( $sub ) ) {
			$sub = (string) ( $sub['nom'] ?? '' );
		}
		return array( 'label' => (string) ( $d[ $label_key ] ?? '—' ), 'sub' => (string) $sub, 'state' => $state );
	}
}
