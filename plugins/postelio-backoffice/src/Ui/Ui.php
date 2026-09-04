<?php
/**
 * Design system du back-office — composants serveur (HTML échappé ICI, jamais dans les écrans).
 * Préfixe `bo-`. Aucun style inline : tout le rendu vient de assets/css/backoffice.css.
 *
 * Composants : page_header · tabs · section_title · card_open/card_close · stat · row · badge · alert
 * · empty_state · table · text · avatar/entity · button · action_button · kv · details.
 *
 * @package Postelio\Backoffice\Ui
 */

namespace Postelio\Backoffice\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ui {

	/** Variante nettoyée (a-z). */
	private static function variant( string $v ): string {
		return (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( $v ) );
	}

	/** En-tête de page (carte blanche) : surtitre + titre + description, actions à droite. */
	public static function page_header( string $title, string $subtitle = '', string $actions_html = '', string $eyebrow = 'Postelio' ): string {
		return '<header class="bo-page__head"><div class="bo-page__titles">'
			. ( '' !== $eyebrow ? '<span class="bo-eyebrow">' . esc_html( $eyebrow ) . '</span>' : '' )
			. '<h1 class="bo-page__title">' . esc_html( $title ) . '</h1>'
			. ( '' !== $subtitle ? '<p class="bo-page__sub">' . esc_html( $subtitle ) . '</p>' : '' )
			. '</div>' . ( '' !== $actions_html ? '<div class="bo-page__actions">' . $actions_html . '</div>' : '' ) . '</header>';
	}

	/**
	 * Onglets (navigation interne). $tabs = [ ['label'=>, 'url'=>, 'active'=>bool, 'count'=>?int] ].
	 *
	 * @param array<int,array<string,mixed>> $tabs
	 */
	public static function tabs( array $tabs, string $aria = 'Navigation' ): string {
		$h = '<nav class="bo-tabs" aria-label="' . esc_attr( $aria ) . '">';
		foreach ( $tabs as $t ) {
			$cls   = 'bo-tabs__link' . ( ! empty( $t['active'] ) ? ' is-active' : '' );
			$count = isset( $t['count'] ) && null !== $t['count'] ? ' <span class="bo-tabs__count">' . (int) $t['count'] . '</span>' : '';
			$h    .= '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( (string) $t['url'] ) . '"' . ( ! empty( $t['active'] ) ? ' aria-current="page"' : '' ) . '>' . esc_html( (string) $t['label'] ) . $count . '</a>';
		}
		return $h . '</nav>';
	}

	public static function section_title( string $title, string $actions_html = '' ): string {
		return '<div class="bo-section"><h2 class="bo-section__title">' . esc_html( $title ) . '</h2>' . ( '' !== $actions_html ? '<div class="bo-section__actions">' . $actions_html . '</div>' : '' ) . '</div>';
	}

	/** Carte : en-tête optionnel (titre + sous-titre + actions), corps libre. */
	public static function card_open( string $title = '', string $subtitle = '', string $actions_html = '', string $extra_class = '' ): string {
		$h = '<section class="bo-card' . ( '' !== $extra_class ? ' ' . esc_attr( $extra_class ) : '' ) . '">';
		if ( '' !== $title || '' !== $actions_html ) {
			$h .= '<div class="bo-card__head"><div class="bo-card__titles">'
				. ( '' !== $title ? '<h2 class="bo-card__title">' . esc_html( $title ) . '</h2>' : '' )
				. ( '' !== $subtitle ? '<p class="bo-card__sub">' . esc_html( $subtitle ) . '</p>' : '' )
				. '</div>' . ( '' !== $actions_html ? '<div class="bo-card__actions">' . $actions_html . '</div>' : '' ) . '</div>';
		}
		return $h . '<div class="bo-card__body">';
	}

	public static function card_close(): string {
		return '</div></section>';
	}

	/** Indicateur compact : valeur + libellé + contexte. `$value` null → « — » grisé. */
	public static function stat( string $label, $value, string $sub = '', bool $accent = false ): string {
		$muted = null === $value;
		$cls   = 'bo-stat' . ( $accent ? ' bo-stat--accent' : '' ) . ( $muted ? ' bo-stat--muted' : '' );
		return '<div class="' . esc_attr( $cls ) . '"><span class="bo-stat__value">' . esc_html( $muted ? '—' : (string) $value ) . '</span>'
			. '<span class="bo-stat__label">' . esc_html( $label ) . '</span>'
			. ( '' !== $sub ? '<span class="bo-stat__sub">' . esc_html( $sub ) . '</span>' : '' ) . '</div>';
	}

	/**
	 * Ligne de liste (titre + sous-titre à gauche, méta au centre, actions à droite). Utilisée pour
	 * « À traiter », les pages du site, la structure… $title_html / $meta_html / $actions_html déjà
	 * échappés par l'appelant (composés via Ui).
	 */
	public static function row( string $title_html, string $sub = '', string $meta_html = '', string $actions_html = '', string $variant = '' ): string {
		$cls = 'bo-row' . ( '' !== $variant ? ' bo-row--' . self::variant( $variant ) : '' );
		return '<div class="' . esc_attr( $cls ) . '"><div class="bo-row__main"><div class="bo-row__title">' . $title_html . '</div>'
			. ( '' !== $sub ? '<div class="bo-row__sub">' . esc_html( $sub ) . '</div>' : '' ) . '</div>'
			. ( '' !== $meta_html ? '<div class="bo-row__meta">' . $meta_html . '</div>' : '' )
			. ( '' !== $actions_html ? '<div class="bo-row__actions">' . $actions_html . '</div>' : '' ) . '</div>';
	}

	public static function rows_open(): string {
		return '<div class="bo-rows">';
	}

	public static function rows_close(): string {
		return '</div>';
	}

	public static function badge( string $text, string $variant = 'neutral', bool $dot = false ): string {
		return '<span class="bo-badge bo-badge--' . esc_attr( self::variant( $variant ) ) . ( $dot ? ' bo-badge--dot' : '' ) . '">' . esc_html( $text ) . '</span>';
	}

	public static function alert( string $message, string $variant = 'info' ): string {
		return '<div class="bo-alert bo-alert--' . esc_attr( self::variant( $variant ) ) . '" role="status">' . esc_html( $message ) . '</div>';
	}

	public static function empty_state( string $title, string $message = '' ): string {
		return '<div class="bo-empty"><h3 class="bo-empty__title">' . esc_html( $title ) . '</h3>' . ( '' !== $message ? '<p class="bo-empty__text">' . esc_html( $message ) . '</p>' : '' ) . '</div>';
	}

	/**
	 * Tableau. $rows = lignes de cellules HTML déjà échappées (via ::text / ::badge…).
	 *
	 * @param string[] $columns
	 * @param array<int, array<int, string>> $rows
	 */
	public static function table( array $columns, array $rows, string $empty = 'Aucun élément.' ): string {
		if ( empty( $rows ) ) {
			return self::empty_state( $empty );
		}
		$h = '<div class="bo-table__wrap"><table class="bo-table"><thead><tr>';
		foreach ( $columns as $c ) {
			$h .= '<th>' . esc_html( $c ) . '</th>';
		}
		$h .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$h .= '<tr>';
			foreach ( $row as $cell ) {
				$h .= '<td>' . $cell . '</td>';
			}
			$h .= '</tr>';
		}
		return $h . '</tbody></table></div>';
	}

	/** Texte échappé, avec emphase ou atténuation. */
	public static function text( string $s, bool $strong = false, bool $muted = false ): string {
		if ( $strong ) {
			return '<strong class="bo-strong">' . esc_html( $s ) . '</strong>';
		}
		if ( $muted ) {
			return '<span class="bo-muted">' . esc_html( $s ) . '</span>';
		}
		return esc_html( $s );
	}

	/** Bouton-lien. Variantes : primary · accent · ghost · danger (défaut : neutre). */
	public static function button( string $label, string $url, string $variant = '', bool $small = false, bool $external = false ): string {
		$cls = 'bo-btn' . ( '' !== $variant ? ' bo-btn--' . self::variant( $variant ) : '' ) . ( $small ? ' bo-btn--sm' : '' );
		return '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '"' . ( $external ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( $label ) . ( $external ? ' <span class="bo-btn__ext" aria-hidden="true">↗</span>' : '' ) . '</a>';
	}

	/**
	 * Bouton-formulaire POST vers admin-post (nonce + capability vérifiés côté serveur par le
	 * gestionnaire d'actions). Les actions legacy (Postelio Admin) restent utilisables telles quelles.
	 *
	 * @param array<string,string|int> $fields
	 */
	public static function action_button( string $action, array $fields, string $label, string $variant = '', string $confirm = '' ): string {
		$cls = 'bo-btn bo-btn--sm' . ( '' !== $variant ? ' bo-btn--' . self::variant( $variant ) : '' );
		$h   = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bo-inline-form"'
			. ( '' !== $confirm ? ' data-bo-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		$h  .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		$h  .= wp_nonce_field( $action, '_pstnonce', true, false );
		foreach ( $fields as $k => $v ) {
			$h .= '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		return $h . '<button type="submit" class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Clé / valeur. $pairs = [ 'Libellé' => 'HTML déjà échappé' ].
	 *
	 * @param array<string,string> $pairs
	 */
	public static function kv( array $pairs ): string {
		$h = '<dl class="bo-kv">';
		foreach ( $pairs as $k => $v ) {
			$h .= '<dt>' . esc_html( (string) $k ) . '</dt><dd>' . $v . '</dd>';
		}
		return $h . '</dl>';
	}

	/** Avatar / logo : image réelle (URL http) sinon initiales. */
	public static function avatar( string $seed, string $img = '', bool $square = false ): string {
		$cls = 'bo-avatar' . ( $square ? ' bo-avatar--square' : '' );
		if ( '' !== $img && preg_match( '#^https?://#', $img ) ) {
			return '<span class="' . esc_attr( $cls . ' bo-avatar--img' ) . '"><img src="' . esc_url( $img ) . '" alt=""></span>';
		}
		return '<span class="' . esc_attr( $cls ) . '">' . esc_html( self::initials( $seed ) ) . '</span>';
	}

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

	/** Cellule entité : avatar + titre + sous-titre. */
	public static function entity( string $title, string $subtitle = '', string $img = '', bool $square = false ): string {
		return '<div class="bo-entity">' . self::avatar( '' !== $title ? $title : $subtitle, $img, $square ) . '<div class="bo-entity__text">'
			. '<span class="bo-entity__title">' . esc_html( '' !== $title ? $title : '—' ) . '</span>'
			. ( '' !== $subtitle ? '<span class="bo-entity__sub">' . esc_html( $subtitle ) . '</span>' : '' ) . '</div></div>';
	}

	/** Bloc repliable (détails techniques). $body_html déjà échappé. */
	public static function details( string $summary, string $body_html, bool $open = false ): string {
		return '<details class="bo-details"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html( $summary ) . '</summary><div class="bo-details__body">' . $body_html . '</div></details>';
	}
}
