/**
 * Pont d'aperçu du Site Builder (postelio-site-preview).
 *
 * INERTE en navigation normale : ne s'active QUE si l'URL porte `?postelio_preview=1`
 * (contexte de l'aperçu chargé dans l'iframe du back-office). Aucune régression sur le site public.
 *
 * En mode aperçu, il reçoit par postMessage — depuis le wp-admin, MÊME ORIGINE uniquement — une
 * configuration de PRÉSENTATION (publique, jamais de secret) et l'applique au VRAI DOM du front :
 * couleurs (variables CSS), navigation, footer, titres de sections, placeholders, activation de
 * sections. On ne recrée aucun composant : c'est le vrai front qui est modifié.
 *
 * Sécurité : origine vérifiée (== location.origin), type de message vérifié, aucune donnée injectée
 * en HTML brut (textContent uniquement), URLs filtrées (jamais de javascript:), aucun eval.
 */
( function () {
	'use strict';

	var params = new URLSearchParams( window.location.search );
	if ( params.get( 'postelio_preview' ) !== '1' ) { return; } // navigation normale → inerte

	document.documentElement.setAttribute( 'data-postelio-preview', '1' );

	// ------------------------------------------------------------ utilitaires
	function txt( sel, val ) {
		if ( val == null || val === '' ) { return; }
		var el = document.querySelector( sel );
		if ( el ) { el.textContent = val; }
	}
	function safeHref( u ) {
		u = ( typeof u === 'string' ) ? u.trim() : '';
		return ( /^\//.test( u ) || /^https?:\/\//i.test( u ) || /^#/.test( u ) ) ? u : '#';
	}
	function sectionOf( sel ) {
		var el = document.querySelector( sel );
		return el ? el.closest( 'section' ) : null;
	}
	function toggle( sectionEl, enabled ) {
		if ( ! sectionEl ) { return; }
		sectionEl.style.display = ( enabled === false ) ? 'none' : '';
	}

	// ------------------------------------------------------------ apparence (couleurs)
	function applyAppearance( a ) {
		if ( ! a ) { return; }
		var root = document.documentElement.style;
		var map = { color_primary: '--c-primary', color_accent: '--c-accent', color_bg: '--c-bg', color_text: '--c-ink' };
		Object.keys( map ).forEach( function ( k ) {
			if ( /^#[0-9a-fA-F]{3,8}$/.test( a[ k ] || '' ) ) { root.setProperty( map[ k ], a[ k ] ); }
		} );
		// Dérivés simples pour rester cohérent.
		if ( /^#[0-9a-fA-F]{6}$/.test( a.color_primary || '' ) ) { root.setProperty( '--c-primary-dark', shade( a.color_primary, -18 ) ); }
		if ( /^#[0-9a-fA-F]{6}$/.test( a.color_accent || '' ) ) { root.setProperty( '--c-accent-dark', shade( a.color_accent, -22 ) ); }
	}
	function shade( hex, pct ) {
		var n = parseInt( hex.slice( 1 ), 16 ), r = ( n >> 16 ) & 255, g = ( n >> 8 ) & 255, b = n & 255;
		var f = ( 100 + pct ) / 100;
		r = Math.max( 0, Math.min( 255, Math.round( r * f ) ) );
		g = Math.max( 0, Math.min( 255, Math.round( g * f ) ) );
		b = Math.max( 0, Math.min( 255, Math.round( b * f ) ) );
		return '#' + ( ( 1 << 24 ) + ( r << 16 ) + ( g << 8 ) + b ).toString( 16 ).slice( 1 );
	}

	// ------------------------------------------------------------ navigation (header réel)
	function applyNavigation( n ) {
		if ( ! n ) { return; }
		document.querySelectorAll( '.site-header .logo-text' ).forEach( function ( el ) { if ( n.brand_text ) { el.textContent = n.brand_text; } } );

		var ul = document.querySelector( '.site-header .nav-links' );
		if ( ul && Array.isArray( n.items ) ) {
			ul.textContent = '';
			n.items.forEach( function ( it ) {
				if ( ! it || ! it.label ) { return; }
				var li = document.createElement( 'li' );
				var a = document.createElement( 'a' );
				a.textContent = it.label;
				a.setAttribute( 'href', safeHref( it.url ) );
				li.appendChild( a );
				ul.appendChild( li );
			} );
		}

		var actions = document.querySelector( '.site-header .nav-actions' );
		if ( actions ) {
			var btns = actions.querySelectorAll( 'a.btn' );
			var login = btns[ 0 ], signup = btns[ 1 ];
			if ( login ) {
				login.style.display = ( n.show_login === false ) ? 'none' : '';
				if ( n.login_label ) { login.textContent = n.login_label; }
				if ( n.login_url ) { login.setAttribute( 'href', safeHref( n.login_url ) ); }
			}
			if ( signup ) {
				signup.style.display = ( n.show_signup === false ) ? 'none' : '';
				if ( n.signup_label ) { signup.textContent = n.signup_label; }
				if ( n.signup_url ) { signup.setAttribute( 'href', safeHref( n.signup_url ) ); }
			}
		}
	}

	// ------------------------------------------------------------ footer réel
	function applyFooter( f ) {
		if ( ! f ) { return; }
		var brand = document.querySelector( '.site-footer .footer-brand .logo-text' );
		if ( brand && f.brand_text ) { brand.textContent = f.brand_text; }
		var desc = document.querySelector( '.site-footer .footer-brand > p' );
		if ( desc && f.description ) { desc.textContent = f.description; }

		if ( Array.isArray( f.columns ) ) {
			var grid = document.querySelector( '.site-footer .footer-grid' );
			if ( grid ) {
				grid.querySelectorAll( '.footer-col' ).forEach( function ( c ) { c.remove(); } );
				f.columns.forEach( function ( col ) {
					if ( ! col || ! col.title ) { return; }
					var nav = document.createElement( 'nav' );
					nav.className = 'footer-col';
					var h = document.createElement( 'h2' );
					h.textContent = col.title;
					nav.appendChild( h );
					var list = document.createElement( 'ul' );
					String( col.links || '' ).split( '\n' ).forEach( function ( line ) {
						var parts = line.split( '|' );
						var label = ( parts[ 0 ] || '' ).trim();
						if ( ! label ) { return; }
						var li = document.createElement( 'li' );
						var a = document.createElement( 'a' );
						a.textContent = label;
						a.setAttribute( 'href', safeHref( ( parts[ 1 ] || '#' ).trim() ) );
						li.appendChild( a );
						list.appendChild( li );
					} );
					nav.appendChild( list );
					grid.appendChild( nav );
				} );
			}
		}

		var social = document.querySelector( '.site-footer .footer-social' );
		if ( social && Array.isArray( f.socials ) ) {
			social.textContent = '';
			f.socials.forEach( function ( s ) {
				if ( ! s || ! s.network ) { return; }
				var li = document.createElement( 'li' );
				var a = document.createElement( 'a' );
				a.textContent = s.network;
				a.setAttribute( 'href', safeHref( s.url ) );
				li.appendChild( a );
				social.appendChild( li );
			} );
		}

		var copy = document.querySelector( '.site-footer .footer-bottom span' );
		if ( copy && f.copyright ) { copy.textContent = f.copyright; }
	}

	// ------------------------------------------------------------ contenu par page
	function safeMedia( u ) {
		u = ( typeof u === 'string' ) ? u.trim() : '';
		return ( /^\//.test( u ) || /^https?:\/\//i.test( u ) ) ? u : '';
	}
	function applyHome( h ) {
		if ( ! h ) { return; }
		if ( h.hero ) {
			txt( '.cine__line--final', h.hero.title );
			var kw = document.querySelector( '#search-keyword' );
			if ( kw && h.hero.search_placeholder ) { kw.setAttribute( 'placeholder', h.hero.search_placeholder ); }
			txt( '.cine__cta-link', h.hero.cta_primary_label );

			// Vidéo cinématique : on ne change QUE la source média, jamais la logique de défilement.
			var video = document.querySelector( '#cine-video' );
			if ( video ) {
				var vsrc = safeMedia( h.hero.hero_video );
				if ( vsrc ) {
					var source = video.querySelector( 'source' );
					if ( source ) { source.setAttribute( 'src', vsrc ); } else { video.setAttribute( 'src', vsrc ); }
					try { video.load(); } catch ( err ) {}
				}
				var poster = safeMedia( h.hero.poster );
				if ( poster ) { video.setAttribute( 'poster', poster ); }
			}
		}
		mapSection( '#recent-title', h.jobs );
		mapSection( '#companies-title', h.companies );
		mapSection( '#articles-title', h.articles );
		mapSection( '#categories-title', h.categories );
	}
	function mapSection( titleSel, sec ) {
		if ( ! sec ) { return; }
		txt( titleSel, sec.title );
		toggle( sectionOf( titleSel ), sec._enabled );
	}

	function applyGenericPage( cfg, page ) {
		// Titre de hero de page = 1er h1 du main.
		var sec = cfg[ page ];
		if ( ! sec ) { return; }
		var hero = sec.hero || {};
		var h1 = document.querySelector( 'main h1' );
		if ( h1 && hero.title ) { h1.textContent = hero.title; }
		// Placeholders de recherche si présents.
		if ( sec.search ) {
			var inputs = document.querySelectorAll( 'main input[type="search"], main input[type="text"]' );
			if ( inputs[ 0 ] && sec.search.placeholder_role ) { inputs[ 0 ].setAttribute( 'placeholder', sec.search.placeholder_role ); }
			if ( inputs[ 1 ] && sec.search.placeholder_city ) { inputs[ 1 ].setAttribute( 'placeholder', sec.search.placeholder_city ); }
		}
	}

	// ------------------------------------------------------------ application globale
	var CURRENT_PAGE = detectPage();
	function detectPage() {
		var p = window.location.pathname.toLowerCase();
		if ( p.indexOf( 'offres' ) >= 0 ) { return 'jobs'; }
		if ( p.indexOf( 'entreprises' ) >= 0 ) { return 'companies'; }
		if ( p.indexOf( 'savoir-faire' ) >= 0 ) { return 'skills'; }
		if ( p.indexOf( 'blog' ) >= 0 || p.indexOf( 'conseils' ) >= 0 ) { return 'advice'; }
		if ( p.indexOf( 'contact' ) >= 0 ) { return 'contact'; }
		return 'home';
	}

	function apply( cfg ) {
		if ( ! cfg || typeof cfg !== 'object' ) { return; }
		applyAppearance( cfg.appearance );
		applyNavigation( cfg.navigation );
		applyFooter( cfg.footer );
		if ( CURRENT_PAGE === 'home' ) { applyHome( cfg.home ); }
		else { applyGenericPage( cfg, CURRENT_PAGE ); }
	}

	// ------------------------------------------------------------ bridge postMessage
	window.addEventListener( 'message', function ( e ) {
		if ( e.origin !== window.location.origin || ! e.data ) { return; }
		if ( e.data.type !== 'postelio-site-preview' || typeof e.data.config !== 'object' ) { return; }
		apply( e.data.config );
	} );

	function ready() {
		try { window.parent.postMessage( { type: 'postelio-preview-ready', page: CURRENT_PAGE }, window.location.origin ); } catch ( err ) {}
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', ready ); } else { ready(); }
} )();
