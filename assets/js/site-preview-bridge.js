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
		applyTypographyAndButtons( a );
	}
	// Typographie + boutons (Apparence) : règles d'aperçu injectées dans UNE feuille dédiée, à partir
	// de valeurs FERMÉES (listes du schéma) — jamais de CSS libre venu du message.
	function applyTypographyAndButtons( a ) {
		var css = '';
		var fonts = { sans: 'var(--font-sans)', serif: 'var(--font-serif)', display: 'var(--font-serif)' };
		if ( fonts[ a.font_headings ] && a.font_headings !== 'sans' ) { css += 'h1,h2,h3,h4,.logo-text{font-family:' + fonts[ a.font_headings ] + '}'; }
		if ( fonts[ a.font_body ] && a.font_body !== 'sans' ) { css += 'body{font-family:' + fonts[ a.font_body ] + '}'; }
		var sizes = { sm: '15px', md: '', lg: '17px' };
		if ( sizes[ a.base_size ] ) { css += 'html{font-size:' + sizes[ a.base_size ] + '}'; }
		var radii = { sm: '4px', md: '10px', pill: '999px' };
		if ( radii[ a.button_radius ] && a.button_radius !== 'pill' ) { css += '.btn{border-radius:' + radii[ a.button_radius ] + '}'; }
		if ( a.button_style === 'outline' ) { css += '.btn-primary,.btn-accent{background:transparent!important;color:var(--c-primary-dark)!important;border:2px solid currentColor!important}'; }
		var s = document.getElementById( 'pst-preview-appearance' );
		if ( ! s ) { s = document.createElement( 'style' ); s.id = 'pst-preview-appearance'; document.head.appendChild( s ); }
		s.textContent = css;
	}
	function shade( hex, pct ) {
		var n = parseInt( hex.slice( 1 ), 16 ), r = ( n >> 16 ) & 255, g = ( n >> 8 ) & 255, b = n & 255;
		var f = ( 100 + pct ) / 100;
		r = Math.max( 0, Math.min( 255, Math.round( r * f ) ) );
		g = Math.max( 0, Math.min( 255, Math.round( g * f ) ) );
		b = Math.max( 0, Math.min( 255, Math.round( b * f ) ) );
		return '#' + ( ( 1 << 24 ) + ( r << 16 ) + ( g << 8 ) + b ).toString( 16 ).slice( 1 );
	}

	// ------------------------------------------------------------ identité globale (Apparence → Identité)
	// Logo / nom de marque / favicon : la config GLOBALE est la source de vérité ; Navigation et Footer
	// peuvent la surcharger (use_identity_logo=false + logo, brand_text non vide).
	var ORIGINAL = {}; // contenus d'origine du DOM pour restaurer quand la config revient à vide
	function safeMedia( u ) {
		u = ( typeof u === 'string' ) ? u.trim() : '';
		return ( /^\//.test( u ) || /^https?:\/\//i.test( u ) ) ? u : '';
	}
	function ensurePreviewStyle() {
		if ( document.getElementById( 'pst-preview-style' ) ) { return; }
		var s = document.createElement( 'style' );
		s.id = 'pst-preview-style';
		s.textContent = '.logo-mark.pst-has-img{background:transparent}.logo-mark.pst-has-img img{width:100%;height:100%;object-fit:contain;display:block}'
			+ '.footer-brand .pst-footer-logo{display:block;max-height:44px;max-width:180px;object-fit:contain;margin-bottom:.6rem}'
			+ '.footer-bottom .pst-footer-legal{display:flex;flex-wrap:wrap;gap:.25rem 1rem;list-style:none;margin:0;padding:0}';
		document.head.appendChild( s );
	}
	function brandName( cfg ) {
		var a = cfg && cfg.appearance;
		return ( a && typeof a.brand_name === 'string' && a.brand_name.trim() ) ? a.brand_name.trim() : 'Postelio';
	}
	// Logo effectif d'une zone (navigation | footer) : global sauf override explicite.
	function resolveLogo( zone, cfg ) {
		var a = ( cfg && cfg.appearance ) || {};
		if ( zone && zone.use_identity_logo === false ) { return safeMedia( zone.logo ); }
		return safeMedia( a.logo );
	}
	function applyHeaderLogo( url ) {
		var mark = document.querySelector( '.site-header .logo-mark' );
		if ( ! mark ) { return; }
		ensurePreviewStyle();
		if ( ORIGINAL.markText === undefined ) { ORIGINAL.markText = mark.textContent; }
		if ( url ) {
			mark.textContent = '';
			var img = document.createElement( 'img' );
			img.setAttribute( 'alt', '' );
			img.setAttribute( 'src', url );
			mark.appendChild( img );
			mark.classList.add( 'pst-has-img' );
		} else {
			mark.classList.remove( 'pst-has-img' );
			mark.textContent = ORIGINAL.markText;
		}
	}
	function applyFooterLogo( url ) {
		var brand = document.querySelector( '.site-footer .footer-brand' );
		if ( ! brand ) { return; }
		ensurePreviewStyle();
		var img = brand.querySelector( '.pst-footer-logo' );
		if ( url ) {
			if ( ! img ) {
				img = document.createElement( 'img' );
				img.className = 'pst-footer-logo';
				img.setAttribute( 'alt', '' );
				brand.insertBefore( img, brand.firstChild );
			}
			img.setAttribute( 'src', url );
		} else if ( img ) {
			img.remove();
		}
	}
	function applyFavicon( a ) {
		var url = safeMedia( a && a.favicon ) || '/assets/icons/favicon.svg'; // défaut = favicon Postelio validé
		var link = document.querySelector( 'link[rel="icon"]' );
		if ( ! link ) { link = document.createElement( 'link' ); link.setAttribute( 'rel', 'icon' ); document.head.appendChild( link ); }
		link.setAttribute( 'href', url );
		if ( /\.svg(\?|$)/i.test( url ) ) { link.setAttribute( 'type', 'image/svg+xml' ); } else { link.removeAttribute( 'type' ); }
	}
	function applyIdentity( cfg ) {
		applyHeaderLogo( resolveLogo( cfg.navigation, cfg ) );
		applyFooterLogo( resolveLogo( cfg.footer, cfg ) );
		applyFavicon( cfg.appearance );
	}

	// ------------------------------------------------------------ navigation (header réel)
	function applyNavigation( n, cfg ) {
		if ( ! n ) { return; }
		var brand = ( n.brand_text && n.brand_text.trim() ) ? n.brand_text.trim() : brandName( cfg );
		document.querySelectorAll( '.site-header .logo-text' ).forEach( function ( el ) { el.textContent = brand; } );

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
	function applyFooter( f, cfg ) {
		if ( ! f ) { return; }
		var brand = document.querySelector( '.site-footer .footer-brand .logo-text' );
		if ( brand ) { brand.textContent = ( f.brand_text && f.brand_text.trim() ) ? f.brand_text.trim() : brandName( cfg ); }
		var desc = document.querySelector( '.site-footer .footer-brand > p' );
		if ( desc && f.description ) { desc.textContent = f.description; }

		// Réglages d'affichage des blocs réels du footer.
		var news = document.querySelector( '.site-footer .footer-news' );
		if ( news ) { news.style.display = ( f.show_newsletter === false ) ? 'none' : ''; }
		var socialList = document.querySelector( '.site-footer .footer-social' );
		if ( socialList ) { socialList.style.display = ( f.show_socials === false ) ? 'none' : ''; }

		// Liens légaux (bas de page) : liste dédiée dans .footer-bottom, créée/mise à jour.
		var bottom = document.querySelector( '.site-footer .footer-bottom' );
		if ( bottom && typeof f.legal_links === 'string' ) {
			ensurePreviewStyle();
			var legal = bottom.querySelector( '.pst-footer-legal' );
			if ( ! legal ) { legal = document.createElement( 'ul' ); legal.className = 'pst-footer-legal'; legal.setAttribute( 'aria-label', 'Liens légaux' ); bottom.appendChild( legal ); }
			legal.textContent = '';
			f.legal_links.split( '\n' ).forEach( function ( line ) {
				var parts = line.split( '|' );
				var label = ( parts[ 0 ] || '' ).trim();
				if ( ! label ) { return; }
				var li = document.createElement( 'li' );
				var a = document.createElement( 'a' );
				a.textContent = label;
				a.setAttribute( 'href', safeHref( ( parts[ 1 ] || '#' ).trim() ) );
				li.appendChild( a );
				legal.appendChild( li );
			} );
			legal.style.display = legal.children.length ? '' : 'none';
		}

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
		applyIdentity( cfg );
		applyNavigation( cfg.navigation, cfg );
		applyFooter( cfg.footer, cfg );
		if ( CURRENT_PAGE === 'home' ) { applyHome( cfg.home ); }
		else { applyGenericPage( cfg, CURRENT_PAGE ); }
	}

	// ------------------------------------------------------------ cible d'aperçu (élément RÉEL)
	// L'éditeur peut demander de centrer l'aperçu sur un élément réel du front (Footer → <footer>).
	// Liste blanche de cibles : jamais de sélecteur arbitraire venu du message.
	var TARGETS = { footer: 'footer.site-footer, footer', header: 'header.site-header, header' };
	var targetTimer = null;
	function scrollToTarget( name ) {
		var sel = TARGETS[ name ];
		if ( ! sel ) { return; }
		var el = document.querySelector( sel );
		if ( ! el ) { return; }
		var r = el.getBoundingClientRect();
		// Hauteur de l'en-tête fixe/sticky (il recouvre le haut de la cible sinon).
		var header = document.querySelector( 'header.site-header' ), hh = 0;
		if ( header ) {
			var pos = window.getComputedStyle( header ).position;
			if ( pos === 'fixed' || pos === 'sticky' ) { hh = header.getBoundingClientRect().height; }
		}
		// Cible plus haute que la fenêtre (footer en mobile) → on montre son DÉBUT (marque) sous
		// l'en-tête ; sinon on la cale en bas de la fenêtre. Déterministe, sans dépendre du moment.
		var y = ( r.height + hh > window.innerHeight )
			? r.top + window.scrollY - hh
			: r.bottom + window.scrollY - window.innerHeight;
		// INSTANTANÉ : le front déclare `scroll-behavior: smooth` ; un défilement animé ne se termine
		// jamais dans un onglet en arrière-plan et retarde l'aperçu. On neutralise le temps du saut.
		var html = document.documentElement, prev = html.style.scrollBehavior;
		html.style.scrollBehavior = 'auto';
		window.scrollTo( 0, Math.max( 0, Math.round( y ) ) );
		html.style.scrollBehavior = prev;
	}
	function keepTarget( name ) {
		if ( ! TARGETS[ name ] ) { return; }
		// Après application de la config : recaler tout de suite (synchrone : fonctionne même dans un
		// onglet en arrière-plan où rAF/timers sont gelés), puis une fois le rendu stabilisé (polices,
		// images, colonnes ajoutées, widgets tardifs). Le hero n'est jamais remontré.
		scrollToTarget( name );
		window.requestAnimationFrame( function () { scrollToTarget( name ); } );
		clearTimeout( targetTimer );
		targetTimer = setTimeout( function () {
			scrollToTarget( name );
			targetTimer = setTimeout( function () { scrollToTarget( name ); }, 900 );
		}, 350 );
	}

	// ------------------------------------------------------------ bridge postMessage
	window.addEventListener( 'message', function ( e ) {
		if ( e.origin !== window.location.origin || ! e.data ) { return; }
		if ( e.data.type !== 'postelio-site-preview' || typeof e.data.config !== 'object' ) { return; }
		apply( e.data.config );
		if ( typeof e.data.target === 'string' ) { keepTarget( e.data.target ); }
	} );

	function ready() {
		try { window.parent.postMessage( { type: 'postelio-preview-ready', page: CURRENT_PAGE }, window.location.origin ); } catch ( err ) {}
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', ready ); } else { ready(); }
} )();
