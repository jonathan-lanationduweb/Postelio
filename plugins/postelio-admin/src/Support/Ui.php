<?php
/**
 * Helpers de rendu du back-office Postelio. Toutes les sorties sont échappées ici (esc_html /
 * esc_attr / esc_url / wp_kses). Les pages composent ces briques ; elles n'écrivent pas de HTML
 * brut non échappé.
 *
 * @package Postelio\Admin\Support
 */

namespace Postelio\Admin\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ui {

	/** En-tête de page : titre + description + actions (HTML déjà échappé) à droite. */
	public static function header( string $title, string $subtitle = '', string $actions_html = '' ): string {
		return '<div class="pst-admin-header"><div class="pst-admin-header__titles"><h1>' . esc_html( $title ) . '</h1>'
			. ( '' !== $subtitle ? '<p>' . esc_html( $subtitle ) . '</p>' : '' )
			. '</div><div class="pst-admin-header__actions">' . $actions_html . '</div></div>';
	}

	/** Carte statistique. $value string déjà prêt ; $muted grise la valeur (indisponible). */
	public static function stat( string $label, $value, string $sub = '', bool $accent = false, bool $muted = false ): string {
		$cls = 'pst-admin-card pst-admin-stat' . ( $accent ? ' pst-admin-stat--accent' : '' );
		$vcl = 'pst-admin-stat__value' . ( $muted ? ' pst-admin-stat__value--muted' : '' );
		return '<div class="' . esc_attr( $cls ) . '"><span class="' . esc_attr( $vcl ) . '">' . esc_html( (string) $value ) . '</span>'
			. '<span class="pst-admin-stat__label">' . esc_html( $label ) . '</span>'
			. ( '' !== $sub ? '<span class="pst-admin-stat__sub">' . esc_html( $sub ) . '</span>' : '' ) . '</div>';
	}

	public static function badge( string $text, string $variant = 'neutral', bool $dot = false ): string {
		$variant = preg_replace( '/[^a-z]/', '', strtolower( $variant ) );
		$cls     = 'pst-admin-badge pst-admin-badge--' . $variant . ( $dot ? ' pst-admin-badge--dot' : '' );
		return '<span class="' . esc_attr( $cls ) . '">' . esc_html( $text ) . '</span>';
	}

	public static function card_open( string $title = '', string $title_suffix = '' ): string {
		$h = '<div class="pst-admin-card">';
		if ( '' !== $title ) {
			$h .= '<h2 class="pst-admin-card__title">' . esc_html( $title ) . ( '' !== $title_suffix ? ' <small>' . esc_html( $title_suffix ) . '</small>' : '' ) . '</h2>';
		}
		return $h;
	}
	public static function card_close(): string {
		return '</div>';
	}

	public static function alert( string $message, string $variant = 'info' ): string {
		$variant = preg_replace( '/[^a-z]/', '', strtolower( $variant ) );
		return '<div class="pst-admin-alert pst-admin-alert--' . esc_attr( $variant ) . '">' . esc_html( $message ) . '</div>';
	}

	public static function empty_state( string $title, string $message = '', string $icon = '📭' ): string {
		return '<div class="pst-admin-empty"><div class="pst-admin-empty__icon">' . esc_html( $icon ) . '</div>'
			. '<h3>' . esc_html( $title ) . '</h3>' . ( '' !== $message ? '<p>' . esc_html( $message ) . '</p>' : '' ) . '</div>';
	}

	/**
	 * Onglets (liens). $tabs = [ ['label'=>, 'url'=>, 'count'=>?int, 'active'=>bool] ].
	 * @param array<int,array<string,mixed>> $tabs
	 */
	public static function tabs( array $tabs ): string {
		$h = '<div class="pst-admin-tabs">';
		foreach ( $tabs as $t ) {
			$active = ! empty( $t['active'] ) ? ' is-active' : '';
			$count  = isset( $t['count'] ) && null !== $t['count'] ? ' <span class="count">(' . (int) $t['count'] . ')</span>' : '';
			$h     .= '<a class="' . esc_attr( trim( 'tab' . $active ) ) . '" href="' . esc_url( (string) $t['url'] ) . '">' . esc_html( (string) $t['label'] ) . $count . '</a>';
		}
		return $h . '</div>';
	}

	/**
	 * Tableau. $columns = ['Col1','Col2',...] ; $rows = array de array de cellules HTML (déjà
	 * échappées par l'appelant via ::badge/::esc). Utiliser ::td_text pour du texte simple.
	 *
	 * @param string[] $columns
	 * @param array<int, array<int, string>> $rows
	 */
	public static function table( array $columns, array $rows, string $empty = 'Aucun élément.' ): string {
		if ( empty( $rows ) ) {
			return self::empty_state( $empty );
		}
		$h = '<div class="pst-admin-table-wrap"><table class="pst-admin-table"><thead><tr>';
		foreach ( $columns as $c ) {
			$h .= '<th>' . esc_html( $c ) . '</th>';
		}
		$h .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$h .= '<tr>';
			foreach ( $row as $cell ) {
				$h .= '<td>' . $cell . '</td>'; // cellule déjà échappée par l'appelant
			}
			$h .= '</tr>';
		}
		return $h . '</tbody></table></div>';
	}

	/** Cellule texte échappée (helper pour composer les lignes de table). */
	public static function text( string $s, bool $strong = false, bool $muted = false ): string {
		$cls = $strong ? 'pst-admin-table__strong' : ( $muted ? 'pst-admin-table__muted' : '' );
		return '' !== $cls ? '<span class="' . esc_attr( $cls ) . '">' . esc_html( $s ) . '</span>' : esc_html( $s );
	}

	/** Pagination (base_url sans le paramètre paged). */
	public static function pager( string $base_url, int $page, int $per_page, int $total ): string {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages <= 1 ) {
			return '';
		}
		$sep = ( false === strpos( $base_url, '?' ) ) ? '?' : '&';
		$h   = '<div class="pst-admin-pager">';
		for ( $p = 1; $p <= min( $pages, 12 ); $p++ ) {
			if ( $p === $page ) {
				$h .= '<span class="is-current">' . (int) $p . '</span>';
			} else {
				$h .= '<a href="' . esc_url( $base_url . $sep . 'paged=' . $p ) . '">' . (int) $p . '</a>';
			}
		}
		return $h . '</div>';
	}

	/** Bouton-formulaire POST vers admin-post (action sécurisée par nonce). */
	public static function action_button( string $action, array $fields, string $label, string $variant = '', string $confirm = '' ): string {
		$cls = 'pst-btn pst-btn--sm' . ( '' !== $variant ? ' pst-btn--' . preg_replace( '/[^a-z]/', '', $variant ) : '' );
		$h   = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"'
			. ( '' !== $confirm ? ' data-pst-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		$h  .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		$h  .= wp_nonce_field( $action, '_pstnonce', true, false );
		foreach ( $fields as $k => $v ) {
			$h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		$h  .= '<button type="submit" class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</button></form>';
		return $h;
	}
}
