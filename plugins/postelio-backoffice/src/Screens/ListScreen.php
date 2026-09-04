<?php
/**
 * Base des écrans de type LISTE : impose le même squelette partout (toolbar → indicateurs
 * éventuels → onglets/filtres compacts → table → pagination → état vide compact). Les écrans
 * concrets fournissent le contenu ; ils ne réinventent pas la structure.
 *
 * @package Postelio\Backoffice\Screens
 */

namespace Postelio\Backoffice\Screens;

use Postelio\Backoffice\Ui\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class ListScreen extends Screen {

	protected const PER_PAGE = 20;

	/** Slug wp-admin de l'écran (pour les URL d'onglets / pagination / détail). */
	abstract protected function slug(): string;

	/** Écran de liste (aucun paramètre `view`). */
	abstract protected function index(): string;

	/** Écran de détail d'une ressource (paramètre `view`). Par défaut : non disponible. */
	protected function detail( string $uuid ): string {
		unset( $uuid );
		return Ui::empty_state( 'Détail indisponible', 'Cet écran ne propose pas de vue détaillée.' );
	}

	protected function body(): string {
		$view = $this->current( 'view' );
		return '' !== $view ? $this->detail( $view ) : $this->index();
	}

	/**
	 * Barre d'onglets par statut. $items = [ clé => libellé ], $counts = [ clé => n ].
	 * La clé `all` est ajoutée en tête automatiquement.
	 *
	 * @param array<string,string> $items
	 * @param array<string,mixed>  $counts
	 * @param array<string,string|int> $keep Paramètres de filtre à conserver dans les liens.
	 */
	protected function status_tabs( array $items, array $counts, string $current, string $all_label = 'Tous', array $keep = array() ): string {
		$tabs = array( array(
			'label'  => $all_label,
			'url'    => $this->url( $this->slug(), array_merge( array( 'tab' => 'all' ), $keep ) ),
			'count'  => isset( $counts['total'] ) ? (int) $counts['total'] : null,
			'active' => 'all' === $current,
		) );
		foreach ( $items as $key => $label ) {
			$tabs[] = array(
				'label'  => $label,
				'url'    => $this->url( $this->slug(), array_merge( array( 'tab' => $key ), $keep ) ),
				'count'  => isset( $counts[ $key ] ) ? (int) $counts[ $key ] : null,
				'active' => $key === $current,
			);
		}
		return Ui::tabs( $tabs, 'Filtrer par statut' );
	}

	/** Pagination pour l'écran courant (conserve l'onglet et les filtres). @param array<string,string|int> $keep */
	protected function pagination( int $total, array $keep = array() ): string {
		return Ui::pager( $this->url( $this->slug(), $keep ), $this->paged(), static::PER_PAGE, $total );
	}

	/** Lien « Voir » vers le détail d'une ressource. */
	protected function view_link( string $uuid, string $label = 'Voir' ): string {
		return Ui::button( $label, $this->url( $this->slug(), array( 'view' => $uuid ) ), '', true );
	}

	/** Bouton de retour à la liste (en-tête de détail). */
	protected function back_link( string $label = '← Liste' ): string {
		return Ui::button( $label, $this->url( $this->slug() ), 'ghost', true );
	}

	/** État « module absent » homogène. */
	protected function module_missing( string $title, string $module_label ): string {
		return Ui::page_header( $title ) . Ui::empty_state( 'Module indisponible', 'Le module ' . $module_label . ' n\'est pas actif. Cet écran redeviendra disponible dès sa réactivation.' );
	}

	/** État « ressource introuvable » homogène. */
	protected function not_found( string $title, string $what ): string {
		return Ui::page_header( $title, '', $this->back_link() ) . Ui::empty_state( 'Introuvable', $what );
	}
}
