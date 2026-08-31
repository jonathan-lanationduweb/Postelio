<?php
/**
 * Base des pages du back-office : garde de capability serveur, enveloppe `.pst-admin`, notices
 * flash (après action), helpers d'URL/onglets/pagination. Chaque page concrète implémente body().
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Page {

	public const SLUG = 'postelio-admin';

	/** Capability requise (défense en profondeur, en plus de la garde du menu). */
	abstract protected function capability(): string;

	/** Corps HTML de la page (déjà échappé via Ui). */
	abstract protected function body(): string;

	/** Point d'entrée appelé par WordPress. */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'postelio-admin' ), 403 );
		}
		echo '<div class="pst-admin">';
		echo $this->flash(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML de notice construit et échappé en interne
		echo $this->body();   // phpcs:ignore WordPress.Security.EscapeOutput -- composé via Ui (échappement interne)
		echo '</div>';
	}

	/** URL d'une page admin Postelio. @param array<string,string|int> $args */
	protected function url( string $slug, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	protected function current( string $key, string $default = '' ): string {
		// Lecture d'un paramètre de navigation (GET) non sensible ; nettoyé.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	protected function paged(): int {
		return max( 1, (int) $this->current( 'paged', '1' ) );
	}

	/** Notice flash après une action admin-post (?pst_msg= / ?pst_err=). */
	private function flash(): string {
		$msg = $this->current( 'pst_msg' );
		$err = $this->current( 'pst_err' );
		if ( '' !== $err ) {
			return \Postelio\Admin\Support\Ui::alert( $this->flash_text( $err ), 'error' );
		}
		if ( '' !== $msg ) {
			return \Postelio\Admin\Support\Ui::alert( $this->flash_text( $msg ), 'success' );
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
		);
		return $map[ $code ] ?? 'Action traitée.';
	}
}
