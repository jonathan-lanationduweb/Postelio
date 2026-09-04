<?php
/**
 * Base des écrans du back-office : garde de capability serveur (défense en profondeur), enveloppe
 * `.pst-bo`, notices flash (après une action admin-post), helpers d'URL. Chaque écran implémente
 * body() et compose UNIQUEMENT via Ui (jamais de HTML brut non échappé, jamais de style inline).
 *
 * @package Postelio\Backoffice\Screens
 */

namespace Postelio\Backoffice\Screens;

use Postelio\Backoffice\Ui\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Screen {

	abstract protected function capability(): string;

	abstract protected function body(): string;

	/** Classe additionnelle sur l'enveloppe (ex. écran pleine largeur du Site Builder). */
	protected function wrapper_class(): string {
		return '';
	}

	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'postelio-backoffice' ), 403 );
		}
		$cls = 'pst-bo' . ( '' !== $this->wrapper_class() ? ' ' . $this->wrapper_class() : '' );
		echo '<div class="' . esc_attr( $cls ) . '">';
		echo $this->flash(); // phpcs:ignore WordPress.Security.EscapeOutput -- composé via Ui (échappé)
		echo $this->body();  // phpcs:ignore WordPress.Security.EscapeOutput -- composé via Ui (échappé)
		echo '</div>';
	}

	/** @param array<string,string|int> $args */
	protected function url( string $slug, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	protected function current( string $key, string $default = '' ): string {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** Page courante d'une liste paginée (au minimum 1). */
	protected function paged(): int {
		return max( 1, (int) $this->current( 'paged', '1' ) );
	}

	/** Notice flash (?pst_msg= / ?pst_err=) — mêmes codes que les actions legacy. */
	private function flash(): string {
		$err = $this->current( 'pst_err' );
		$msg = $this->current( 'pst_msg' );
		if ( '' !== $err ) {
			return Ui::alert( $this->flash_text( $err ), 'error' );
		}
		if ( '' !== $msg ) {
			return Ui::alert( $this->flash_text( $msg ), 'success' );
		}
		return '';
	}

	private function flash_text( string $code ): string {
		$map = array(
			'done'          => 'Action effectuée.',
			'suspended'     => 'Compte/ressource suspendu(e).',
			'reactivated'   => 'Réactivé(e) avec succès.',
			'verified'      => 'Entreprise vérifiée.',
			'rejected'      => 'Entreprise rejetée.',
			'moderated'     => 'Décision de modération appliquée.',
			'assigned'      => 'Dossier assigné.',
			'retried'       => 'Relance du traitement demandée.',
			'forbidden'     => 'Action non autorisée pour votre profil.',
			'failed'        => 'L\'action a échoué. Réessayez.',
			'invalid'       => 'Requête invalide (jeton de sécurité).',
			'module_absent' => 'Le module concerné est indisponible.',
			'email_test_ok'     => 'E-mail de test remis au transport. Vérifiez votre boîte de réception.',
			'email_test_failed' => 'L\'e-mail de test n\'a pas pu être envoyé.',
		);
		return $map[ $code ] ?? 'Action traitée.';
	}
}
