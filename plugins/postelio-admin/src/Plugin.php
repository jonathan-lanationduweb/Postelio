<?php
/**
 * Amorçage du back-office Postelio. COUCHE D'ADMINISTRATION uniquement : menu, design system,
 * pages, actions. Aucune logique métier, aucune table, aucune écriture directe des tables des
 * autres plugins — tout passe par leurs contrats/services publics. Ne fatal jamais si un module
 * est désactivé (détection via Registry/class_exists).
 *
 * @package Postelio\Admin
 */

namespace Postelio\Admin;

use Postelio\Admin\Actions\Actions;
use Postelio\Admin\Site\SiteMenu;
use Postelio\Admin\Support\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;
	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted || ! is_admin() ) {
			return;
		}
		$this->booted = true;

		/*
		 * Compatibilité avec le back-office unifié (postelio-backoffice) : quand il est actif, il
		 * devient propriétaire du menu « Postelio » (`postelio/admin/legacy_menu` → false) et rend
		 * lui-même les écrans migrés ; les pages legacy restent rendues par ce plugin pour les écrans
		 * non migrés, avec leurs assets (`postelio/admin/legacy_assets`). Les actions admin-post
		 * restent toujours enregistrées (les écrans legacy en dépendent).
		 */
		if ( apply_filters( 'postelio/admin/legacy_menu', true ) ) {
			( new Menu() )->register();
		}
		( new Actions() )->register();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook ): void {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Écran repris par le back-office unifié → aucun asset legacy (évite le double design system).
		if ( ! apply_filters( 'postelio/admin/legacy_assets', true, $page ) ) {
			return;
		}

		// Écrans « Site Builder » : assets dédiés + configuration injectée.
		$site_page = SiteMenu::page_for_slug( $page );
		if ( null !== $site_page ) {
			$this->enqueue_site_editor( $site_page );
			return;
		}

		if ( false === strpos( $hook, 'postelio' ) && 0 !== strpos( $page, 'postelio-' ) ) {
			return;
		}
		wp_enqueue_style( 'postelio-admin', POSTELIO_ADMIN_URL . 'assets/admin.css', array(), POSTELIO_ADMIN_VERSION );
		wp_enqueue_script( 'postelio-admin', POSTELIO_ADMIN_URL . 'assets/admin.js', array(), POSTELIO_ADMIN_VERSION, true );
	}

	/** Charge l'éditeur visuel du site et injecte le schéma + les valeurs de la page courante. */
	private function enqueue_site_editor( string $site_page ): void {
		$dir = '\\Postelio\\Site\\Api\\SiteConfigDirectory';
		wp_enqueue_style( 'postelio-site-editor', POSTELIO_ADMIN_URL . 'assets/site-editor.css', array(), POSTELIO_ADMIN_VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'postelio-site-editor', POSTELIO_ADMIN_URL . 'assets/site-editor.js', array(), POSTELIO_ADMIN_VERSION, true );

		if ( ! Contracts::has( $dir ) ) {
			return;
		}
		wp_localize_script(
			'postelio-site-editor',
			'PST_SITE',
			array(
				'page'       => $site_page,
				'schema'     => call_user_func( array( $dir, 'schema' ), $site_page ),
				'values'     => call_user_func( array( $dir, 'config' ), $site_page ),
				'appearance' => call_user_func( array( $dir, 'config' ), 'appearance' ),
				'saveUrl'    => esc_url_raw( rest_url( 'postelio/v1/site/admin/' . $site_page ) ),
				'configUrl'  => esc_url_raw( rest_url( 'postelio/v1/site/config' ) ),
				'searchUrl'  => esc_url_raw( rest_url( 'postelio/v1/site/admin/search' ) ),
				'resolveUrl' => esc_url_raw( rest_url( 'postelio/v1/site/admin/resolve' ) ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'frontUrl'   => esc_url_raw( home_url( '/' ) ),
				'version'    => POSTELIO_ADMIN_VERSION,
			)
		);
	}
}
