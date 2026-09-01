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
	public static function header( string $title, string $subtitle = '', string $actions_html = '', string $eyebrow = 'Postelio' ): string {
		return '<div class="pst-admin-header"><div class="pst-admin-header__titles">'
			. ( '' !== $eyebrow ? '<span class="pst-eyebrow">' . esc_html( $eyebrow ) . '</span>' : '' )
			. '<h1>' . esc_html( $title ) . '</h1>'
			. ( '' !== $subtitle ? '<p>' . esc_html( $subtitle ) . '</p>' : '' )
			. '</div><div class="pst-admin-header__actions">' . $actions_html . '</div></div>';
	}

	/** Carte statistique. $value string déjà prêt ; $muted grise la valeur ; $icon = emoji léger. */
	public static function stat( string $label, $value, string $sub = '', bool $accent = false, bool $muted = false, string $icon = '' ): string {
		$cls = 'pst-admin-card pst-admin-stat' . ( $accent ? ' pst-admin-stat--accent' : '' );
		$vcl = 'pst-admin-stat__value' . ( $muted ? ' pst-admin-stat__value--muted' : '' );
		return '<div class="' . esc_attr( $cls ) . '">'
			. ( '' !== $icon ? '<span class="pst-admin-stat__icon">' . esc_html( $icon ) . '</span>' : '' )
			. '<span class="' . esc_attr( $vcl ) . '">' . esc_html( (string) $value ) . '</span>'
			. '<span class="pst-admin-stat__label">' . esc_html( $label ) . '</span>'
			. ( '' !== $sub ? '<span class="pst-admin-stat__sub">' . esc_html( $sub ) . '</span>' : '' ) . '</div>';
	}

	/**
	 * Timeline verticale. $items = [ ['label'=>, 'time'=>, 'done'=>bool], … ].
	 * @param array<int,array<string,mixed>> $items
	 */
	public static function timeline( array $items ): string {
		if ( empty( $items ) ) {
			return '<p class="pst-help">Aucun événement.</p>';
		}
		$h = '<ul class="pst-timeline">';
		foreach ( $items as $it ) {
			$done = ! empty( $it['done'] ) ? ' is-done' : '';
			$h   .= '<li class="' . esc_attr( trim( $done ) ) . '"><span class="pst-timeline__label">' . esc_html( (string) $it['label'] ) . '</span>'
				. ( ! empty( $it['time'] ) ? '<div class="pst-timeline__time">' . esc_html( (string) $it['time'] ) . '</div>' : '' ) . '</li>';
		}
		return $h . '</ul>';
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

	/**
	 * Avatar/logo réutilisable : image réelle si fournie (URL http), sinon initiales colorées,
	 * sinon point générique. `$square` pour les entités (entreprises/offres), rond pour les personnes.
	 */
	public static function avatar( string $seed, string $img = '', bool $square = false, string $variant = 'primary' ): string {
		$variant = preg_replace( '/[^a-z]/', '', strtolower( $variant ) );
		$cls     = 'pst-avatar' . ( $square ? ' pst-avatar--square' : '' ) . ( 'primary' !== $variant ? ' pst-avatar--' . $variant : '' );
		if ( '' !== $img && preg_match( '#^https?://#', $img ) ) {
			return '<span class="' . esc_attr( $cls ) . '" style="background-image:url(' . esc_url( $img ) . ')"></span>';
		}
		return '<span class="' . esc_attr( $cls ) . '">' . esc_html( self::initials( $seed ) ) . '</span>';
	}

	/** Deux lettres d'initiales à partir d'un nom/e-mail (repli sur « ? »). */
	public static function initials( string $s ): string {
		$s = trim( $s );
		if ( '' === $s ) {
			return '?';
		}
		$parts = preg_split( '/[\s._@-]+/', $s, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $parts ) {
			return strtoupper( mb_substr( $s, 0, 1 ) );
		}
		$a = mb_substr( $parts[0], 0, 1 );
		$b = count( $parts ) > 1 ? mb_substr( $parts[ count( $parts ) - 1 ], 0, 1 ) : '';
		return strtoupper( $a . $b );
	}

	/**
	 * Cellule « entité » : avatar/logo + titre + sous-titre. Standardise la 1re colonne des listes
	 * (Utilisateurs, Entreprises, Offres, Candidatures, Messagerie). Tout est échappé ici.
	 *
	 * @param array<string,mixed> $opts img?, square?bool, variant?, seed?
	 */
	public static function entity_cell( string $title, string $subtitle = '', array $opts = array() ): string {
		$seed = (string) ( $opts['seed'] ?? ( '' !== $title ? $title : $subtitle ) );
		$av   = self::avatar( $seed, (string) ( $opts['img'] ?? '' ), ! empty( $opts['square'] ), (string) ( $opts['variant'] ?? 'primary' ) );
		$h    = '<div class="pst-entity">' . $av . '<div class="pst-entity__text">';
		$h   .= '<span class="pst-entity__title">' . esc_html( '' !== $title ? $title : '—' ) . '</span>';
		if ( '' !== $subtitle ) {
			$h .= '<span class="pst-entity__sub">' . esc_html( $subtitle ) . '</span>';
		}
		return $h . '</div></div>';
	}

	/** En-tête de liste léger (toolbar) : titre + description + actions, sans grande carte hero. */
	public static function toolbar( string $title, string $subtitle = '', string $actions_html = '' ): string {
		return '<div class="pst-admin-toolbar"><div class="pst-admin-toolbar__t">'
			. '<h1>' . esc_html( $title ) . '</h1>'
			. ( '' !== $subtitle ? '<p>' . esc_html( $subtitle ) . '</p>' : '' )
			. '</div>' . ( '' !== $actions_html ? '<div class="pst-admin-toolbar__a">' . $actions_html . '</div>' : '' ) . '</div>';
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
