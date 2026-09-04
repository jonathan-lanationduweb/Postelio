<?php
/**
 * Assets du back-office : chargés UNIQUEMENT sur les écrans migrés (jamais globalement dans
 * wp-admin). Version/cache-buster centralisé = POSTELIO_BACKOFFICE_VERSION. Les écrans du Site
 * Builder reçoivent en plus le moteur d'édition (site-builder.css/js), la médiathèque WordPress et la
 * configuration injectée (`window.PST_BO_SITE`), lue via le contrat postelio-site.
 *
 * @package Postelio\Backoffice
 */

namespace Postelio\Backoffice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Assets {

	private const SITE_DIR = '\\Postelio\\Site\\Api\\SiteConfigDirectory';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	public static function current_slug(): string {
		return isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function body_class( string $classes ): string {
		return Menu::is_migrated( self::current_slug() ) ? $classes . ' postelio-backoffice' : $classes;
	}

	public function enqueue(): void {
		$slug = self::current_slug();
		if ( ! Menu::is_migrated( $slug ) ) {
			return;
		}
		$v = POSTELIO_BACKOFFICE_VERSION;
		wp_enqueue_style( 'postelio-backoffice', POSTELIO_BACKOFFICE_URL . 'assets/css/backoffice.css', array(), $v );
		wp_enqueue_script( 'postelio-backoffice', POSTELIO_BACKOFFICE_URL . 'assets/js/backoffice.js', array(), $v, true );

		$site_page = Menu::site_page_for_slug( $slug );
		if ( null === $site_page ) {
			return;
		}
		wp_enqueue_style( 'postelio-backoffice-site', POSTELIO_BACKOFFICE_URL . 'assets/css/site-builder.css', array( 'postelio-backoffice' ), $v );
		wp_enqueue_media();
		wp_enqueue_script( 'postelio-backoffice-site', POSTELIO_BACKOFFICE_URL . 'assets/js/site-builder.js', array(), $v, true );

		if ( ! class_exists( self::SITE_DIR ) ) {
			return;
		}
		wp_localize_script(
			'postelio-backoffice-site',
			'PST_BO_SITE',
			array(
				'page'       => $site_page,
				'schema'     => call_user_func( array( self::SITE_DIR, 'schema' ), $site_page ),
				'values'     => call_user_func( array( self::SITE_DIR, 'config' ), $site_page ),
				'appearance' => call_user_func( array( self::SITE_DIR, 'config' ), 'appearance' ),
				'saveUrl'    => esc_url_raw( rest_url( 'postelio/v1/site/admin/' . $site_page ) ),
				'configUrl'  => esc_url_raw( rest_url( 'postelio/v1/site/config' ) ),
				'searchUrl'  => esc_url_raw( rest_url( 'postelio/v1/site/admin/search' ) ),
				'resolveUrl' => esc_url_raw( rest_url( 'postelio/v1/site/admin/resolve' ) ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'frontUrl'   => esc_url_raw( home_url( '/' ) ),
				'adminBase'  => esc_url_raw( admin_url( 'admin.php' ) ),
				'version'    => $v,
			)
		);
	}
}
