<?php
/**
 * Design system du back-office — composants serveur (HTML échappé ICI, jamais dans les écrans).
 * Préfixe `bo-`. Aucun style inline : tout le rendu vient de assets/css/backoffice.css.
 *
 * Composants : page_header · tabs · toolbar · kpis/kpi · card · section · table · text · meta · avatar
 * · entity · identity · badge · button · action_button · menu · icon · filters/search/select · pager
 * · timeline · note_form · cols · action_stack · help · protected_notice · excerpt · kv · details
 * · pipeline · tiles · rows/row · sidenav · split/inbox/pane · queue · integration · status/checks
 * · day/slot.
 *
 * @package Postelio\Backoffice\Ui
 */

namespace Postelio\Backoffice\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ui {

	/** Variante nettoyée (a-z0-9-). */
	private static function variant( string $v ): string {
		return (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( $v ) );
	}

	// ------------------------------------------------------------------ page

	/** En-tête compact (pattern LNDW) : surtitre · titre · description courte, actions à droite. */
	public static function page_header( string $title, string $subtitle = '', string $actions_html = '', string $eyebrow = 'Postelio' ): string {
		return '<header class="bo-page__head"><div class="bo-page__titles">'
			. ( '' !== $eyebrow ? '<span class="bo-eyebrow">' . esc_html( $eyebrow ) . '</span>' : '' )
			. '<h1 class="bo-page__title">' . esc_html( $title ) . '</h1>'
			. ( '' !== $subtitle ? '<p class="bo-page__sub">' . esc_html( $subtitle ) . '</p>' : '' )
			. '</div>' . ( '' !== $actions_html ? '<div class="bo-page__actions">' . $actions_html . '</div>' : '' ) . '</header>';
	}

	/**
	 * Onglets soulignés. $tabs = [ ['label'=>, 'url'=>, 'active'=>bool, 'count'=>?int] ].
	 *
	 * @param array<int,array<string,mixed>> $tabs
	 */
	public static function tabs( array $tabs, string $aria = 'Navigation' ): string {
		$h = '<nav class="bo-tabs" aria-label="' . esc_attr( $aria ) . '">';
		foreach ( $tabs as $t ) {
			$cls   = 'bo-tabs__link' . ( ! empty( $t['active'] ) ? ' is-active' : '' );
			$count = isset( $t['count'] ) && null !== $t['count'] ? '<span class="bo-tabs__count">' . (int) $t['count'] . '</span>' : '';
			$h    .= '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( (string) $t['url'] ) . '"' . ( ! empty( $t['active'] ) ? ' aria-current="page"' : '' ) . '>' . esc_html( (string) $t['label'] ) . $count . '</a>';
		}
		return $h . '</nav>';
	}

	/** Barre d'outils de liste : onglets (HTML de ::tabs) à gauche, contrôles à droite, une ligne. */
	public static function toolbar( string $tabs_html, string $right_html = '' ): string {
		return '<div class="bo-toolbar">' . $tabs_html . ( '' !== $right_html ? '<div class="bo-toolbar__right">' . $right_html . '</div>' : '' ) . '</div>';
	}

	// ------------------------------------------------------------------ indicateurs

	public static function kpis_open( int $columns = 6 ): string {
		return '<div class="bo-kpis" style="--bo-kpis:' . (int) $columns . '">';
	}

	public static function kpis_close(): string {
		return '</div>';
	}

	/** Indicateur compact : libellé (capitales) + valeur + contexte. `null` → « — ». URL optionnelle. */
	public static function kpi( string $label, $value, string $sub = '', bool $accent = false, string $url = '' ): string {
		$muted = null === $value;
		$cls   = 'bo-kpi' . ( $accent ? ' bo-kpi--accent' : '' ) . ( $muted ? ' bo-kpi--muted' : '' );
		$inner = '<span class="bo-kpi__label">' . esc_html( $label ) . '</span><span class="bo-kpi__value">' . esc_html( $muted ? '—' : (string) $value ) . '</span>'
			. ( '' !== $sub ? '<span class="bo-kpi__sub">' . esc_html( $sub ) . '</span>' : '' );
		return '' !== $url
			? '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . $inner . '</a>'
			: '<div class="' . esc_attr( $cls ) . '">' . $inner . '</div>';
	}

	/** Compatibilité : ancien indicateur → indicateur compact. */
	public static function stat( string $label, $value, string $sub = '', bool $accent = false ): string {
		return self::kpi( $label, $value, $sub, $accent );
	}

	// ------------------------------------------------------------------ cartes / sections

	/** Carte : en-tête optionnel (surtitre + titre + sous-titre + actions), corps libre. */
	public static function card_open( string $title = '', string $subtitle = '', string $actions_html = '', string $extra_class = '', string $eyebrow = '' ): string {
		$h = '<section class="bo-card' . ( '' !== $extra_class ? ' ' . esc_attr( $extra_class ) : '' ) . '">';
		if ( '' !== $title || '' !== $actions_html ) {
			$h .= '<div class="bo-card__head"><div class="bo-card__titles">'
				. ( '' !== $eyebrow ? '<span class="bo-card__eyebrow">' . esc_html( $eyebrow ) . '</span>' : '' )
				. ( '' !== $title ? '<h2 class="bo-card__title">' . esc_html( $title ) . '</h2>' : '' )
				. ( '' !== $subtitle ? '<p class="bo-card__sub">' . esc_html( $subtitle ) . '</p>' : '' )
				. '</div>' . ( '' !== $actions_html ? '<div class="bo-card__actions">' . $actions_html . '</div>' : '' ) . '</div>';
		}
		return $h . '<div class="bo-card__body">';
	}

	public static function card_close(): string {
		return '</div></section>';
	}

	/** Section dans le flux (sans carte) : titre + sous-titre + actions, puis contenu. */
	public static function section_open( string $title, string $subtitle = '', string $actions_html = '' ): string {
		return '<div class="bo-section"><div class="bo-section__head"><div><h2 class="bo-section__title">' . esc_html( $title ) . '</h2>'
			. ( '' !== $subtitle ? '<p class="bo-section__sub">' . esc_html( $subtitle ) . '</p>' : '' ) . '</div>'
			. ( '' !== $actions_html ? '<div class="bo-section__actions">' . $actions_html . '</div>' : '' ) . '</div>';
	}

	public static function section_close(): string {
		return '</div>';
	}

	/** Titre de section autonome. */
	public static function section_title( string $title, string $actions_html = '' ): string {
		return '<div class="bo-section__head"><h2 class="bo-section__title">' . esc_html( $title ) . '</h2>' . ( '' !== $actions_html ? '<div class="bo-section__actions">' . $actions_html . '</div>' : '' ) . '</div>';
	}

	// ------------------------------------------------------------------ listes

	/**
	 * Ligne de liste : $lead_html (compteur / avatar) · titre + sous-titre · méta · actions.
	 * $title_html / $meta_html / $actions_html / $lead_html déjà échappés (composés via Ui).
	 */
	public static function row( string $title_html, string $sub = '', string $meta_html = '', string $actions_html = '', string $variant = '', string $lead_html = '' ): string {
		$cls = 'bo-row' . ( '' !== $variant ? ' bo-row--' . self::variant( $variant ) : '' );
		return '<div class="' . esc_attr( $cls ) . '">' . $lead_html . '<div class="bo-row__main"><div class="bo-row__title">' . $title_html . '</div>'
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

	/** Compteur mis en avant (« À traiter »). */
	public static function count_lead( int $n ): string {
		return '<span class="bo-row__count">' . (int) $n . '</span>';
	}

	/**
	 * Tableau commun. $rows = lignes de cellules HTML déjà échappées. La dernière colonne est
	 * alignée à droite (actions).
	 *
	 * @param string[] $columns
	 * @param array<int, array<int, string>> $rows
	 */
	public static function table( array $columns, array $rows, string $empty = 'Aucun élément.', string $empty_text = '' ): string {
		if ( empty( $rows ) ) {
			return self::empty_state( $empty, $empty_text );
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

	// ------------------------------------------------------------------ texte, badges

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

	/** Cellule à deux lignes (principale + secondaire), sans avatar. */
	public static function meta( string $main, string $sub = '' ): string {
		return '<div class="bo-meta"><span class="bo-meta__main">' . esc_html( '' !== $main ? $main : '—' ) . '</span>'
			. ( '' !== $sub ? '<span class="bo-meta__sub">' . esc_html( $sub ) . '</span>' : '' ) . '</div>';
	}

	public static function badge( string $text, string $variant = 'neutral', bool $dot = false ): string {
		return '<span class="bo-badge bo-badge--' . esc_attr( self::variant( $variant ) ) . ( $dot ? ' bo-badge--dot' : '' ) . '">' . esc_html( $text ) . '</span>';
	}

	public static function alert( string $message, string $variant = 'info' ): string {
		return '<div class="bo-alert bo-alert--' . esc_attr( self::variant( $variant ) ) . '" role="status">' . esc_html( $message ) . '</div>';
	}

	/** État vide compact : une phrase, une explication, une action optionnelle (HTML via ::button). */
	public static function empty_state( string $title, string $message = '', string $action_html = '' ): string {
		return '<div class="bo-empty"><p class="bo-empty__title">' . esc_html( $title ) . '</p>' . ( '' !== $message ? '<p class="bo-empty__text">' . esc_html( $message ) . '</p>' : '' )
			. ( '' !== $action_html ? '<div class="bo-empty__action">' . $action_html . '</div>' : '' ) . '</div>';
	}

	// ------------------------------------------------------------------ boutons, menus

	/** Bouton-lien. Variantes : primary · accent · ghost · danger (défaut : neutre bordé). */
	public static function button( string $label, string $url, string $variant = '', bool $small = false, bool $external = false ): string {
		$cls = 'bo-btn' . ( '' !== $variant ? ' bo-btn--' . self::variant( $variant ) : '' ) . ( $small ? ' bo-btn--sm' : '' );
		return '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '"' . ( $external ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( $label ) . ( $external ? ' <span class="bo-btn__ext" aria-hidden="true">↗</span>' : '' ) . '</a>';
	}

	/**
	 * Bouton-formulaire POST vers admin-post (nonce + capability vérifiés côté serveur).
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
	 * Menu contextuel ⋯ : $items = HTML de boutons (::button / ::action_button) ou '-' pour un
	 * séparateur. Sans framework (<details>), fermé au clic extérieur par backoffice.js.
	 *
	 * @param string[] $items
	 */
	public static function menu( array $items, string $label = 'Actions' ): string {
		$items = array_values( array_filter( $items, static fn( $i ) => '' !== $i ) );
		if ( empty( $items ) ) {
			return '';
		}
		$h = '<details class="bo-menu"><summary aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">' . self::icon( 'more' ) . '</summary><div class="bo-menu__panel">';
		foreach ( $items as $i ) {
			$h .= '-' === $i ? '<div class="bo-menu__sep"></div>' : $i;
		}
		return $h . '</div></details>';
	}

	/** Icône SVG monochrome (trait), jamais d'emoji. */
	public static function icon( string $name ): string {
		$paths = array(
			'more'   => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
			'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
			'arrow'  => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
			'back'   => '<path d="M19 12H5"/><path d="m11 18-6-6 6-6"/>',
			'check'  => '<path d="m5 12 5 5L20 7"/>',
			'ext'    => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M20 14v6H4V4h6"/>',
		);
		return '<svg class="bo-icon" viewBox="0 0 24 24" aria-hidden="true">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	// ------------------------------------------------------------------ filtres, formulaires

	/**
	 * Barre de filtres GET compacte. $hidden = paramètres conservés ; $fields_html = contrôles
	 * (::search_input / ::select). $block = variante encadrée hors toolbar.
	 *
	 * @param array<string,string> $hidden
	 */
	public static function filters( array $hidden, string $fields_html, string $submit = 'Filtrer', bool $block = false ): string {
		$h = '<form method="get" class="bo-filters' . ( $block ? ' bo-filters--block' : '' ) . '">';
		foreach ( $hidden as $k => $v ) {
			$h .= '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		return $h . $fields_html . '<button type="submit" class="bo-btn bo-btn--sm">' . esc_html( $submit ) . '</button></form>';
	}

	public static function search_input( string $name, string $value, string $placeholder ): string {
		return '<input class="bo-input bo-input--search" type="search" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" aria-label="' . esc_attr( $placeholder ) . '">';
	}

	/** @param array<string,string> $options valeur => libellé */
	public static function select( string $name, array $options, string $current ): string {
		$h = '<select class="bo-select" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $v => $label ) {
			$h .= '<option value="' . esc_attr( (string) $v ) . '"' . selected( $current, (string) $v, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $h . '</select>';
	}

	/** Pagination (base_url sans le paramètre `paged`). */
	public static function pager( string $base_url, int $page, int $per_page, int $total ): string {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages <= 1 ) {
			return '';
		}
		$sep = ( false === strpos( $base_url, '?' ) ) ? '?' : '&';
		$h   = '<nav class="bo-pager" aria-label="Pagination">';
		for ( $p = 1; $p <= min( $pages, 12 ); $p++ ) {
			$h .= $p === $page
				? '<span class="bo-pager__current" aria-current="page">' . (int) $p . '</span>'
				: '<a class="bo-pager__link" href="' . esc_url( $base_url . $sep . 'paged=' . $p ) . '">' . (int) $p . '</a>';
		}
		return $h . '</nav>';
	}

	/**
	 * Chronologie verticale. $items = [ ['label'=>, 'time'=>, 'done'=>bool], … ].
	 *
	 * @param array<int,array<string,mixed>> $items
	 */
	public static function timeline( array $items ): string {
		if ( empty( $items ) ) {
			return '<p class="bo-muted">Aucun événement.</p>';
		}
		$h = '<ol class="bo-timeline">';
		foreach ( $items as $it ) {
			$h .= '<li class="bo-timeline__item' . ( ! empty( $it['done'] ) ? ' is-done' : '' ) . '">'
				. '<span class="bo-timeline__label">' . esc_html( (string) $it['label'] ) . '</span>'
				. ( ! empty( $it['time'] ) ? '<span class="bo-timeline__time">' . esc_html( (string) $it['time'] ) . '</span>' : '' )
				. '</li>';
		}
		return $h . '</ol>';
	}

	/**
	 * Formulaire admin-post avec zone de texte (note interne).
	 *
	 * @param array<string,string|int> $fields
	 */
	public static function note_form( string $action, array $fields, string $textarea_name, string $placeholder, string $submit ): string {
		$h  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bo-noteform">';
		$h .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		$h .= wp_nonce_field( $action, '_pstnonce', true, false );
		foreach ( $fields as $k => $v ) {
			$h .= '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		$h .= '<textarea class="bo-textarea" name="' . esc_attr( $textarea_name ) . '" rows="2" placeholder="' . esc_attr( $placeholder ) . '"></textarea>';
		return $h . '<button type="submit" class="bo-btn bo-btn--sm">' . esc_html( $submit ) . '</button></form>';
	}

	// ------------------------------------------------------------------ mise en page

	/** Colonnes de détail : contenu principal à gauche (2/3), colonne latérale à droite (1/3). */
	public static function cols_open(): string {
		return '<div class="bo-cols">';
	}

	public static function col_open(): string {
		return '<div class="bo-col">';
	}

	public static function col_close(): string {
		return '</div>';
	}

	public static function cols_close(): string {
		return '</div>';
	}

	/** Grille de cartes équilibrées (2 ou 3 colonnes). */
	public static function grid_open( int $cols = 2 ): string {
		return '<div class="bo-grid bo-grid--' . (int) $cols . '">';
	}

	public static function grid_close(): string {
		return '</div>';
	}

	/** Groupe d'actions empilé (colonne latérale de détail). */
	public static function action_stack( string $inner_html ): string {
		return '<div class="bo-actions bo-actions--stack">' . $inner_html . '</div>';
	}

	public static function help( string $text ): string {
		return '<p class="bo-help">' . esc_html( $text ) . '</p>';
	}

	public static function protected_notice( string $text ): string {
		return '<p class="bo-protected">' . self::badge( 'Protégé', 'neutral', true ) . '<span>' . esc_html( $text ) . '</span></p>';
	}

	public static function excerpt( string $text ): string {
		return '<p class="bo-excerpt">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Clé / valeur. $pairs = [ 'Libellé' => 'HTML déjà échappé' ].
	 *
	 * @param array<string,string> $pairs
	 */
	public static function kv( array $pairs, bool $tight = false ): string {
		$h = '<dl class="bo-kv' . ( $tight ? ' bo-kv--tight' : '' ) . '">';
		foreach ( $pairs as $k => $v ) {
			$h .= '<dt>' . esc_html( (string) $k ) . '</dt><dd>' . $v . '</dd>';
		}
		return $h . '</dl>';
	}

	/** Bloc repliable (détails techniques). $body_html déjà échappé. */
	public static function details( string $summary, string $body_html, bool $open = false ): string {
		return '<details class="bo-details"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html( $summary ) . '</summary><div class="bo-details__body">' . $body_html . '</div></details>';
	}

	// ------------------------------------------------------------------ identité

	/** Avatar / logo : image réelle (URL http) sinon initiales sur fond doux. */
	public static function avatar( string $seed, string $img = '', bool $square = false, bool $large = false ): string {
		$cls = 'bo-avatar' . ( $square ? ' bo-avatar--square' : '' ) . ( $large ? ' bo-avatar--lg' : '' );
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

	/** Identité de fiche (détail) : grand avatar + nom + sous-titre + badges. */
	public static function identity( string $title, string $subtitle = '', string $img = '', bool $square = false, string $badges_html = '' ): string {
		return '<div class="bo-identity">' . self::avatar( $title, $img, $square, true ) . '<div class="bo-identity__text"><span class="bo-identity__title">' . esc_html( $title ) . '</span>'
			. ( '' !== $subtitle ? '<span class="bo-identity__sub">' . esc_html( $subtitle ) . '</span>' : '' )
			. ( '' !== $badges_html ? '<span class="bo-chips">' . $badges_html . '</span>' : '' ) . '</div></div>';
	}

	/** E-mail masqué pour l'affichage en liste (j***@exemple.fr). */
	public static function mask_email( string $email ): string {
		$at = strpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '' === $email ? '—' : '***';
		}
		return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	// ------------------------------------------------------------------ composants métier

	/**
	 * Pipeline d'étapes compact. $steps = [ ['label'=>, 'count'=>int, 'url'=>, 'active'=>bool] ].
	 *
	 * @param array<int,array<string,mixed>> $steps
	 */
	public static function pipeline( array $steps ): string {
		$h = '<nav class="bo-pipeline" aria-label="Étapes">';
		foreach ( $steps as $s ) {
			$h .= '<a class="bo-pipeline__step' . ( ! empty( $s['active'] ) ? ' is-active' : '' ) . '" href="' . esc_url( (string) $s['url'] ) . '">'
				. '<span class="bo-pipeline__n">' . (int) ( $s['count'] ?? 0 ) . '</span><span class="bo-pipeline__l">' . esc_html( (string) $s['label'] ) . '</span></a>';
		}
		return $h . '</nav>';
	}

	/**
	 * Grille de tuiles-liens (raccourcis). $tiles = [ ['title'=>, 'sub'=>, 'url'=>] ].
	 *
	 * @param array<int,array<string,string>> $tiles
	 */
	public static function tiles( array $tiles ): string {
		$h = '<div class="bo-tiles">';
		foreach ( $tiles as $t ) {
			$h .= '<a class="bo-tile" href="' . esc_url( (string) $t['url'] ) . '"><span class="bo-tile__title">' . esc_html( (string) $t['title'] ) . '</span>'
				. ( ! empty( $t['sub'] ) ? '<span class="bo-tile__sub">' . esc_html( (string) $t['sub'] ) . '</span>' : '' ) . '</a>';
		}
		return $h . '</div>';
	}

	/**
	 * Navigation latérale. $items = [ ['label'=>, 'url'=>, 'active'=>bool, 'group'=>?string, 'badge'=>?string] ].
	 *
	 * @param array<int,array<string,mixed>> $items
	 */
	public static function sidenav( array $items, string $aria = 'Sections' ): string {
		$h     = '<nav class="bo-sidenav" aria-label="' . esc_attr( $aria ) . '">';
		$group = null;
		foreach ( $items as $it ) {
			$g = (string) ( $it['group'] ?? '' );
			if ( '' !== $g && $g !== $group ) {
				$h    .= '<span class="bo-sidenav__group">' . esc_html( $g ) . '</span>';
				$group = $g;
			}
			$h .= '<a class="bo-sidenav__link' . ( ! empty( $it['active'] ) ? ' is-active' : '' ) . '" href="' . esc_url( (string) $it['url'] ) . '"' . ( ! empty( $it['active'] ) ? ' aria-current="page"' : '' ) . '>'
				. '<span>' . esc_html( (string) $it['label'] ) . '</span>' . ( ! empty( $it['badge'] ) ? '<span class="bo-sidenav__badge">' . esc_html( (string) $it['badge'] ) . '</span>' : '' ) . '</a>';
		}
		return $h . '</nav>';
	}

	public static function sidenav_layout_open(): string {
		return '<div class="bo-layout--sidenav">';
	}

	public static function sidenav_layout_close(): string {
		return '</div>';
	}

	/** Boîte de réception : liste à gauche + panneau à droite. */
	public static function split_open(): string {
		return '<div class="bo-split">';
	}

	public static function split_close(): string {
		return '</div>';
	}

	public static function inbox_open( string $head_left, string $head_right_html = '' ): string {
		return '<div class="bo-inbox"><div class="bo-inbox__head"><span>' . esc_html( $head_left ) . '</span>' . $head_right_html . '</div>';
	}

	/** Élément de boîte : avatar + titre + sous-titre, côté droit (date + badge). */
	public static function inbox_item( string $url, string $title, string $sub, string $avatar_seed, string $side_top, string $side_badge_html, bool $active ): string {
		return '<a class="bo-inbox__item' . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $active ? ' aria-current="true"' : '' ) . '>' . self::avatar( $avatar_seed )
			. '<span class="bo-inbox__main"><span class="bo-inbox__title">' . esc_html( $title ) . '</span><span class="bo-inbox__sub">' . esc_html( $sub ) . '</span></span>'
			. '<span class="bo-inbox__side"><span>' . esc_html( $side_top ) . '</span>' . $side_badge_html . '</span></a>';
	}

	public static function inbox_close( string $foot_html = '' ): string {
		return ( '' !== $foot_html ? '<div class="bo-inbox__foot">' . $foot_html . '</div>' : '' ) . '</div>';
	}

	public static function pane_open( string $title_html, string $actions_html = '' ): string {
		return '<div class="bo-pane"><div class="bo-pane__head"><div>' . $title_html . '</div>' . ( '' !== $actions_html ? '<div class="bo-actions">' . $actions_html . '</div>' : '' ) . '</div><div class="bo-pane__body">';
	}

	public static function pane_close(): string {
		return '</div></div>';
	}

	public static function pane_placeholder( string $text ): string {
		return '<div class="bo-pane"><div class="bo-pane__placeholder">' . esc_html( $text ) . '</div></div>';
	}

	/** File de traitement (modération). $level = critical|high|medium|low. */
	public static function queue_open(): string {
		return '<div class="bo-queue">';
	}

	public static function queue_item( string $level, string $level_label, string $title, string $sub, string $meta_html, string $actions_html ): string {
		return '<div class="bo-queue__item bo-queue__item--' . esc_attr( self::variant( $level ) ) . '"><span class="bo-queue__level">' . esc_html( $level_label ) . '</span>'
			. '<div class="bo-queue__main"><span class="bo-queue__title">' . esc_html( $title ) . '</span>' . ( '' !== $sub ? '<span class="bo-queue__sub">' . esc_html( $sub ) . '</span>' : '' ) . '</div>'
			. ( '' !== $meta_html ? '<div class="bo-queue__meta">' . $meta_html . '</div>' : '' )
			. ( '' !== $actions_html ? '<div class="bo-queue__actions">' . $actions_html . '</div>' : '' ) . '</div>';
	}

	public static function queue_close(): string {
		return '</div>';
	}

	/**
	 * Carte d'intégration (source d'offres). $meta = [ 'Libellé' => 'valeur' ] ; $value null → « — ».
	 *
	 * @param array<string,string> $meta
	 */
	public static function integration( string $name, string $type, string $badge_html, $value, string $unit, array $meta, string $foot_html ): string {
		$h = '<article class="bo-integration"><div class="bo-integration__head">' . self::avatar( $name, '', true )
			. '<div><div class="bo-integration__name">' . esc_html( $name ) . '</div><div class="bo-integration__type">' . esc_html( $type ) . '</div></div>'
			. '<div class="bo-card__actions">' . $badge_html . '</div></div>'
			. '<div class="bo-integration__figure"><span class="bo-integration__value">' . esc_html( null === $value ? '—' : (string) $value ) . '</span><span class="bo-integration__unit">' . esc_html( $unit ) . '</span></div>';
		if ( $meta ) {
			$h .= '<div class="bo-integration__meta">';
			foreach ( $meta as $k => $v ) {
				$h .= '<div>' . esc_html( (string) $k ) . '<b>' . esc_html( (string) $v ) . '</b></div>';
			}
			$h .= '</div>';
		}
		return $h . ( '' !== $foot_html ? '<div class="bo-integration__foot">' . $foot_html . '</div>' : '' ) . '</article>';
	}

	public static function integrations_open(): string {
		return '<div class="bo-integrations">';
	}

	public static function integrations_close(): string {
		return '</div>';
	}

	/** Bandeau d'état général (santé). $status = ok|degraded|unconfigured|error. */
	public static function status_banner( string $status, string $title, string $sub, string $actions_html = '' ): string {
		return '<div class="bo-status bo-status--' . esc_attr( self::variant( $status ) ) . '"><span class="bo-status__dot" aria-hidden="true"></span><div class="bo-status__main"><div class="bo-status__title">' . esc_html( $title ) . '</div>'
			. ( '' !== $sub ? '<div class="bo-status__sub">' . esc_html( $sub ) . '</div>' : '' ) . '</div>' . ( '' !== $actions_html ? '<div class="bo-actions">' . $actions_html . '</div>' : '' ) . '</div>';
	}

	/**
	 * Liste de contrôles : nom · état (HTML badge) · détail. $checks = [ [name, badge_html, detail] ].
	 *
	 * @param array<int,array{0:string,1:string,2:string}> $checks
	 */
	public static function checks( array $checks ): string {
		$h = '<div class="bo-checks">';
		foreach ( $checks as $c ) {
			$h .= '<div class="bo-check"><span class="bo-check__name">' . esc_html( $c[0] ) . '</span><span>' . $c[1] . '</span><span class="bo-check__detail">' . esc_html( $c[2] ) . '</span></div>';
		}
		return $h . '</div>';
	}

	/** Groupe daté (entretiens). */
	public static function day_open( string $title ): string {
		return '<section class="bo-day"><h2 class="bo-day__title">' . esc_html( $title ) . '</h2><div class="bo-slots">';
	}

	public static function day_close(): string {
		return '</div></section>';
	}

	/** Créneau compact : heure + date | titre + sous-titre + badges. */
	public static function slot( string $url, string $hour, string $date, string $title, string $sub, string $badges_html ): string {
		return '<a class="bo-slot" href="' . esc_url( $url ) . '"><span class="bo-slot__time"><span class="bo-slot__hour">' . esc_html( $hour ) . '</span><span class="bo-slot__date">' . esc_html( $date ) . '</span></span>'
			. '<span class="bo-slot__main"><span class="bo-slot__title">' . esc_html( $title ) . '</span><span class="bo-slot__sub">' . esc_html( $sub ) . '</span><span class="bo-slot__meta">' . $badges_html . '</span></span></a>';
	}
}
