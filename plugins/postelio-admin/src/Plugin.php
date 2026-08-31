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

		( new Menu() )->register();
		( new Actions() )->register();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook ): void {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( false === strpos( $hook, 'postelio' ) && 0 !== strpos( $page, 'postelio-' ) ) {
			return;
		}
		wp_enqueue_style( 'postelio-admin', POSTELIO_ADMIN_URL . 'assets/admin.css', array(), POSTELIO_ADMIN_VERSION );
		wp_enqueue_script( 'postelio-admin', POSTELIO_ADMIN_URL . 'assets/admin.js', array(), POSTELIO_ADMIN_VERSION, true );
	}
}
