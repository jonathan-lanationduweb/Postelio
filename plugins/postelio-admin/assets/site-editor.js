/**
 * Éditeur visuel du site Postelio (« Site Builder »).
 * Piloté par le schéma injecté (window.PST_SITE). Rendu de l'éditeur à gauche, aperçu fidèle et
 * responsive à droite, save bar. Aucune dépendance externe (vanilla JS). Les chaînes utilisateur
 * sont insérées via textContent (jamais innerHTML) → pas d'injection dans l'aperçu.
 */
( function () {
	'use strict';

	var CFG = window.PST_SITE;
	if ( ! CFG || ! CFG.schema ) { return; }

	var state = deepClone( CFG.values || {} );
	var dirty = false;
	var device = 'desktop';
	var previewTimer = null;

	// ------------------------------------------------------------- utilitaires
	function deepClone( o ) { return JSON.parse( JSON.stringify( o == null ? {} : o ) ); }
	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		if ( attrs ) { Object.keys( attrs ).forEach( function ( k ) {
			if ( k === 'class' ) { n.className = attrs[ k ]; }
			else if ( k === 'text' ) { n.textContent = attrs[ k ]; }
			else if ( k === 'html' ) { n.innerHTML = attrs[ k ]; }
			else if ( k.indexOf( 'on' ) === 0 && typeof attrs[ k ] === 'function' ) { n.addEventListener( k.slice( 2 ), attrs[ k ] ); }
			else if ( attrs[ k ] != null ) { n.setAttribute( k, attrs[ k ] ); }
		} ); }
		( kids || [] ).forEach( function ( c ) { if ( c != null ) { n.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c ); } } );
		return n;
	}
	function markDirty() { if ( ! dirty ) { dirty = true; savebar( true ); } schedulePreview(); }
	function schedulePreview() { clearTimeout( previewTimer ); previewTimer = setTimeout( renderPreview, 120 ); }
	function appearance() { return CFG.page === 'appearance' ? state : ( CFG.appearance || {} ); }

	// ============================================================ ÉDITEUR
	function renderEditor() {
		var panel = document.getElementById( 'pst-ed-panel' );
		panel.innerHTML = '';
		var schema = CFG.schema;

		if ( schema.type === 'sections' ) {
			if ( schema.prepared ) { panel.appendChild( preparedNote() ); }
			var order = ( state._order && state._order.length ) ? state._order : Object.keys( schema.sections );
			order.forEach( function ( skey, idx ) {
				var sdef = schema.sections[ skey ];
				if ( ! sdef ) { return; }
				panel.appendChild( sectionCard( skey, sdef, idx, order.length ) );
			} );
		} else {
			if ( schema.prepared ) { panel.appendChild( preparedNote() ); }
			// Page « single » : une carte réglages + une carte par repeater.
			var scalarFields = {}, repeaters = {};
			Object.keys( schema.fields ).forEach( function ( fk ) {
				if ( schema.fields[ fk ].type === 'repeater' ) { repeaters[ fk ] = schema.fields[ fk ]; }
				else { scalarFields[ fk ] = schema.fields[ fk ]; }
			} );
			if ( Object.keys( scalarFields ).length ) {
				panel.appendChild( plainCard( schema.icon ? schema.icon + ' Réglages' : 'Réglages', function ( body ) {
					Object.keys( scalarFields ).forEach( function ( fk ) { body.appendChild( fieldRow( fk, scalarFields[ fk ], [ fk ] ) ); } );
				} ) );
			}
			Object.keys( repeaters ).forEach( function ( fk ) {
				panel.appendChild( plainCard( repeaters[ fk ].label || fk, function ( body ) {
					body.appendChild( repeaterField( fk, repeaters[ fk ], [ fk ] ) );
				} ) );
			} );
		}
	}

	function preparedNote() {
		return el( 'div', { class: 'pst-ed-note', text: 'Page préparée (Phase 1) : la structure est disponible et enregistrable ; le branchement au front se fera ultérieurement.' } );
	}

	function plainCard( title, fill ) {
		var body = el( 'div', { class: 'pst-ed-card__body' } );
		fill( body );
		var card = el( 'div', { class: 'pst-ed-card is-open' }, [
			el( 'div', { class: 'pst-ed-card__head', onclick: function () { card.classList.toggle( 'is-open' ); } }, [
				el( 'span', { class: 'pst-ed-card__title', text: title } ),
				el( 'span', { class: 'pst-ed-card__chevron', text: '▸' } )
			] ),
			body
		] );
		return card;
	}

	function sectionCard( skey, sdef, idx, total ) {
		var sval = state[ skey ] = state[ skey ] || {};
		var enabled = sval._enabled !== false;

		var body = el( 'div', { class: 'pst-ed-card__body' } );
		Object.keys( sdef.fields || {} ).forEach( function ( fk ) {
			body.appendChild( fieldRow( fk, sdef.fields[ fk ], [ skey, fk ] ) );
		} );

		var toggle = switchEl( enabled, function ( on ) {
			sval._enabled = on; card.classList.toggle( 'is-off', ! on ); markDirty();
		} );

		var reorder = null;
		if ( CFG.schema.type === 'sections' && sdef.reorderable !== false ) {
			reorder = el( 'div', { class: 'pst-ed-card__reorder' }, [
				el( 'button', { title: 'Monter', text: '▲', onclick: function ( e ) { e.stopPropagation(); moveSection( skey, -1 ); } } ),
				el( 'button', { title: 'Descendre', text: '▼', onclick: function ( e ) { e.stopPropagation(); moveSection( skey, 1 ); } } )
			] );
		}

		var head = el( 'div', { class: 'pst-ed-card__head', onclick: function () { card.classList.toggle( 'is-open' ); } }, [
			reorder || el( 'span', { class: 'pst-ed-card__grip', text: '⋮⋮' } ),
			el( 'span', { class: 'pst-ed-card__title', text: sdef.label || skey } ),
			labelWrap( toggle ),
			el( 'span', { class: 'pst-ed-card__chevron', text: '▸' } )
		] );

		var card = el( 'div', { class: 'pst-ed-card' + ( enabled ? '' : ' is-off' ) }, [ head, body ] );
		return card;
	}

	function labelWrap( node ) {
		// évite que le clic sur le switch ne replie la carte
		var w = el( 'span', {}, [ node ] );
		w.addEventListener( 'click', function ( e ) { e.stopPropagation(); } );
		return w;
	}

	function moveSection( skey, dir ) {
		var order = ( state._order && state._order.length ) ? state._order.slice() : Object.keys( CFG.schema.sections );
		var i = order.indexOf( skey );
		var j = i + dir;
		if ( i < 0 || j < 0 || j >= order.length ) { return; }
		order.splice( j, 0, order.splice( i, 1 )[ 0 ] );
		state._order = order;
		markDirty(); renderEditor(); renderPreview();
	}

	// ------------------------------------------------------------- champs
	function fieldRow( fk, fdef, path ) {
		var type = fdef.type || 'text';
		if ( type === 'repeater' ) { return wrapField( fdef, repeaterField( fk, fdef, path ) ); }
		if ( type === 'toggle' ) { return toggleField( fk, fdef, path ); }

		var value = getPath( path );
		var control;
		if ( type === 'textarea' ) {
			control = el( 'textarea', { class: 'pst-ed-textarea', placeholder: fdef.placeholder || '' } );
			control.value = value || ''; control.addEventListener( 'input', function () { setPath( path, control.value ); markDirty(); } );
		} else if ( type === 'select' ) {
			control = el( 'select', { class: 'pst-ed-select' } );
			var opts = fdef.options || {};
			Object.keys( opts ).forEach( function ( ov ) { control.appendChild( el( 'option', { value: ov, text: opts[ ov ] } ) ); } );
			control.value = value; control.addEventListener( 'change', function () { setPath( path, control.value ); markDirty(); } );
		} else if ( type === 'number' ) {
			control = el( 'input', { class: 'pst-ed-input', type: 'number', min: fdef.min, max: fdef.max } );
			control.value = value; control.addEventListener( 'input', function () { setPath( path, parseInt( control.value, 10 ) || 0 ); markDirty(); } );
		} else if ( type === 'color' ) {
			return colorField( fk, fdef, path );
		} else if ( type === 'media' ) {
			return mediaField( fk, fdef, path );
		} else {
			control = el( 'input', { class: 'pst-ed-input', type: 'text', placeholder: fdef.placeholder || '' } );
			control.value = value || ''; control.addEventListener( 'input', function () { setPath( path, control.value ); markDirty(); } );
		}
		return wrapField( fdef, control );
	}

	function wrapField( fdef, control ) {
		var kids = [ el( 'label', { text: fdef.label || '' } ), control ];
		if ( fdef.help ) { kids.push( el( 'p', { class: 'pst-ed-field__help', text: fdef.help } ) ); }
		return el( 'div', { class: 'pst-ed-field' }, kids );
	}

	function toggleField( fk, fdef, path ) {
		var value = !! getPath( path );
		var sw = switchEl( value, function ( on ) { setPath( path, on ); markDirty(); } );
		return el( 'div', { class: 'pst-ed-field' }, [
			el( 'div', { class: 'pst-ed-toggle' }, [ el( 'label', { text: fdef.label || '', style: 'margin:0' } ), sw ] ),
			fdef.help ? el( 'p', { class: 'pst-ed-field__help', text: fdef.help } ) : null
		] );
	}

	function switchEl( checked, onChange ) {
		var input = el( 'input', { type: 'checkbox' } ); input.checked = !! checked;
		input.addEventListener( 'change', function () { onChange( input.checked ); } );
		return el( 'label', { class: 'pst-switch' }, [ input, el( 'span', { class: 'pst-switch__slider' } ) ] );
	}

	function colorField( fk, fdef, path ) {
		var value = getPath( path ) || fdef.default || '#000000';
		var picker = el( 'input', { type: 'color' } ); picker.value = normalizeHex( value );
		var hex = el( 'input', { class: 'pst-ed-input', type: 'text' } ); hex.value = value;
		picker.addEventListener( 'input', function () { hex.value = picker.value; setPath( path, picker.value ); markDirty(); } );
		hex.addEventListener( 'input', function () { if ( /^#[0-9a-fA-F]{6}$/.test( hex.value ) ) { picker.value = hex.value; } setPath( path, hex.value ); markDirty(); } );
		return el( 'div', { class: 'pst-ed-field' }, [ el( 'label', { text: fdef.label || '' } ), el( 'div', { class: 'pst-ed-color' }, [ picker, hex ] ) ] );
	}

	function mediaField( fk, fdef, path ) {
		var value = getPath( path ) || '';
		var preview = el( 'div', { class: 'pst-ed-media__preview' } );
		function paint() {
			var v = getPath( path );
			if ( v && /^https?:/.test( v ) ) { preview.style.backgroundImage = 'url(' + v + ')'; preview.textContent = ''; }
			else { preview.style.backgroundImage = 'none'; preview.textContent = v ? ( 'Média #' + v ) : 'Aucun média'; }
		}
		paint();
		var choose = el( 'button', { class: 'pst-ed-btn pst-ed-btn--sm', type: 'button', text: value ? 'Remplacer' : 'Choisir un média', onclick: function () { openMedia( path, paint, choose ); } } );
		var remove = el( 'button', { class: 'pst-ed-btn pst-ed-btn--sm pst-ed-btn--ghost', type: 'button', text: 'Retirer', onclick: function () { setPath( path, '' ); paint(); choose.textContent = 'Choisir un média'; markDirty(); } } );
		return el( 'div', { class: 'pst-ed-field' }, [ el( 'label', { text: fdef.label || '' } ), preview, el( 'div', { class: 'pst-ed-media__actions' }, [ choose, remove ] ), fdef.help ? el( 'p', { class: 'pst-ed-field__help', text: fdef.help } ) : null ] );
	}

	function openMedia( path, paint, chooseBtn ) {
		if ( ! window.wp || ! window.wp.media ) { window.alert( 'Médiathèque WordPress indisponible.' ); return; }
		var frame = window.wp.media( { title: 'Choisir un média', multiple: false } );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			setPath( path, att.url ); paint(); chooseBtn.textContent = 'Remplacer'; markDirty();
		} );
		frame.open();
	}

	// ------------------------------------------------------------- repeater
	function repeaterField( fk, fdef, path ) {
		var rows = getPath( path );
		if ( ! Array.isArray( rows ) ) { rows = []; setPath( path, rows ); }
		var wrap = el( 'div', { class: 'pst-ed-rep' } );
		function rebuild() {
			wrap.innerHTML = '';
			rows.forEach( function ( row, i ) { wrap.appendChild( repRow( fk, fdef, path, i, rows ) ); } );
			wrap.appendChild( el( 'button', { class: 'pst-ed-rep__add', type: 'button', text: '+ Ajouter', onclick: function () {
				var blank = {}; Object.keys( fdef.fields ).forEach( function ( sk ) { blank[ sk ] = ''; } );
				rows.push( blank ); markDirty(); rebuild(); schedulePreview();
			} } ) );
		}
		function repRow( fk, fdef, path, i, rows ) {
			var body = el( 'div', { class: 'pst-ed-rep__row' } );
			body.appendChild( el( 'div', { class: 'pst-ed-rep__row-head' }, [
				el( 'span', { class: 'pst-ed-rep__row-title', text: ( fdef.label || 'Élément' ) + ' ' + ( i + 1 ) } ),
				el( 'div', { class: 'pst-ed-rep__row-tools' }, [
					el( 'button', { type: 'button', title: 'Monter', text: '▲', onclick: function () { if ( i > 0 ) { rows.splice( i - 1, 0, rows.splice( i, 1 )[ 0 ] ); markDirty(); rebuild(); schedulePreview(); } } } ),
					el( 'button', { type: 'button', title: 'Descendre', text: '▼', onclick: function () { if ( i < rows.length - 1 ) { rows.splice( i + 1, 0, rows.splice( i, 1 )[ 0 ] ); markDirty(); rebuild(); schedulePreview(); } } } ),
					el( 'button', { type: 'button', title: 'Supprimer', text: '✕', onclick: function () { rows.splice( i, 1 ); markDirty(); rebuild(); schedulePreview(); } } )
				] )
			] ) );
			Object.keys( fdef.fields ).forEach( function ( sk ) { body.appendChild( fieldRow( sk, fdef.fields[ sk ], path.concat( [ i, sk ] ) ) ); } );
			return body;
		}
		rebuild();
		return wrap;
	}

	// ------------------------------------------------------------- state path
	function getPath( path ) { var o = state; for ( var i = 0; i < path.length; i++ ) { if ( o == null ) { return undefined; } o = o[ path[ i ] ]; } return o; }
	function setPath( path, val ) { var o = state; for ( var i = 0; i < path.length - 1; i++ ) { if ( o[ path[ i ] ] == null ) { o[ path[ i ] ] = ( typeof path[ i + 1 ] === 'number' ) ? [] : {}; } o = o[ path[ i ] ]; } o[ path[ path.length - 1 ] ] = val; }

	// ============================================================ APERÇU
	function renderPreview() {
		var canvas = document.getElementById( 'pst-ed-canvas' );
		canvas.className = 'pst-ed-canvas' + ( device === 'tablet' ? ' is-tablet' : device === 'mobile' ? ' is-mobile' : '' );
		canvas.innerHTML = '';
		var pv = el( 'div', { class: 'pst-pv' } );
		applyTheme( pv, appearance() );
		var page = CFG.page;
		if ( page === 'home' ) { buildHome( pv ); }
		else if ( page === 'navigation' ) { buildNav( pv ); }
		else if ( page === 'footer' ) { buildFooter( pv ); }
		else if ( page === 'appearance' ) { buildAppearance( pv ); }
		else { buildGeneric( pv ); }
		canvas.appendChild( pv );
	}

	function applyTheme( pv, a ) {
		if ( a.color_primary ) { pv.style.setProperty( '--pv-primary', a.color_primary ); }
		if ( a.color_accent ) { pv.style.setProperty( '--pv-accent', a.color_accent ); }
		if ( a.color_bg ) { pv.style.setProperty( '--pv-bg', a.color_bg ); }
		if ( a.color_text ) { pv.style.setProperty( '--pv-text', a.color_text ); }
		pv.style.setProperty( '--pv-radius', a.button_radius === 'sm' ? '8px' : a.button_radius === 'md' ? '14px' : '999px' );
		if ( a.font_body === 'serif' ) { pv.classList.add( 'font-serif' ); }
		if ( a.font_headings === 'serif' ) { pv.classList.add( 'head-serif' ); }
		if ( a.font_headings === 'display' ) { pv.classList.add( 'head-display' ); }
		pv.classList.add( 'size-' + ( a.base_size || 'md' ) );
		if ( a.button_style === 'outline' ) { pv.classList.add( 'btn-outline' ); }
	}

	function btn( label, primary ) { return label ? el( 'a', { class: 'pst-pv__btn ' + ( primary ? 'pst-pv__btn--primary' : 'pst-pv__btn--ghost' ), text: label } ) : null; }
	function on( skey ) { var s = state[ skey ]; return s && s._enabled !== false; }

	function buildHome( pv ) {
		var order = ( state._order && state._order.length ) ? state._order : Object.keys( CFG.schema.sections );
		order.forEach( function ( skey ) {
			if ( ! on( skey ) ) { return; }
			var v = state[ skey ] || {};
			if ( skey === 'hero' ) { pv.appendChild( heroBlock( v ) ); }
			else if ( skey === 'search' ) { pv.appendChild( searchBlock( v ) ); }
			else if ( skey === 'arguments' ) { pv.appendChild( argsBlock( v ) ); }
			else if ( skey === 'cta' ) { pv.appendChild( ctaBlock( v ) ); }
			else { pv.appendChild( collectionBlock( v ) ); }
		} );
		if ( ! pv.children.length ) { pv.appendChild( el( 'div', { class: 'pst-ed-empty', text: 'Toutes les sections sont désactivées.' } ) ); }
	}

	function heroBlock( v ) {
		var cls = 'pst-pv__hero h-' + ( v.height || 'large' ) + ( v.align === 'center' ? ' align-center' : '' ) + ( v.text_light === false ? ' text-dark' : '' );
		var hero = el( 'section', { class: cls } );
		if ( v.background && /^https?:/.test( v.background ) ) { hero.style.backgroundImage = 'url(' + v.background + ')'; }
		if ( v.overlay !== false ) { hero.appendChild( el( 'div', { class: 'pst-pv__hero-overlay' } ) ); }
		var inner = el( 'div', { class: 'pst-pv__hero-inner' } );
		if ( v.title ) { inner.appendChild( el( 'h1', { text: v.title } ) ); }
		if ( v.subtitle ) { inner.appendChild( el( 'p', { text: v.subtitle } ) ); }
		var sb = el( 'div', { class: 'pst-pv__searchbar' }, [ el( 'input', { disabled: 'disabled', placeholder: v.search_placeholder || 'Rechercher…' } ), btn( 'Rechercher', true ) ] );
		inner.appendChild( sb );
		var ctas = el( 'div', { class: 'pst-pv__cta-row' }, [ btn( v.cta_primary_label, true ), btn( v.cta_secondary_label, false ) ] );
		inner.appendChild( ctas );
		hero.appendChild( inner );
		return hero;
	}

	function searchBlock( v ) {
		return el( 'section', { class: 'pst-pv__section' }, [
			v.title ? el( 'h2', { text: v.title } ) : null,
			el( 'div', { class: 'pst-pv__searchbar' }, [
				el( 'input', { disabled: 'disabled', placeholder: v.placeholder_role || 'Métier' } ),
				el( 'input', { disabled: 'disabled', placeholder: v.placeholder_city || 'Ville' } ),
				btn( v.button_label || 'Rechercher', true )
			] )
		] );
	}

	function collectionBlock( v ) {
		var n = Math.max( 1, Math.min( 12, parseInt( v.count, 10 ) || 4 ) );
		var grid = el( 'div', { class: 'pst-pv__grid' } );
		for ( var i = 0; i < n; i++ ) { grid.appendChild( el( 'div', { class: 'pst-pv__card' }, [ el( 'div', { class: 'pst-pv__card-ph' } ), el( 'div', { class: 'pst-pv__card-ph short' } ) ] ) ); }
		return el( 'section', { class: 'pst-pv__section' }, [
			v.title ? el( 'h2', { text: v.title } ) : null,
			v.subtitle ? el( 'p', { class: 'pst-pv__section-sub', text: v.subtitle } ) : null,
			grid,
			v.cta_label ? el( 'div', { style: 'margin-top:16px' }, [ btn( v.cta_label, false ) ] ) : null
		] );
	}

	function argsBlock( v ) {
		var grid = el( 'div', { class: 'pst-pv__args' } );
		( Array.isArray( v.items ) ? v.items : [] ).forEach( function ( it ) {
			grid.appendChild( el( 'div', { class: 'pst-pv__arg' }, [
				el( 'div', { class: 'pst-pv__arg-ic', text: it.icon || '•' } ),
				el( 'h3', { text: it.title || '' } ),
				el( 'p', { text: it.text || '' } )
			] ) );
		} );
		return el( 'section', { class: 'pst-pv__section' }, [ v.title ? el( 'h2', { text: v.title } ) : null, v.subtitle ? el( 'p', { class: 'pst-pv__section-sub', text: v.subtitle } ) : null, grid ] );
	}

	function ctaBlock( v ) {
		return el( 'section', { class: 'pst-pv__cta-band' }, [
			v.title ? el( 'h2', { text: v.title } ) : null,
			v.text ? el( 'p', { text: v.text } ) : null,
			el( 'div', { style: 'margin-top:14px' }, [ btn( v.button_label || 'Commencer', true ) ] )
		] );
	}

	function buildNav( pv ) {
		var v = state;
		var brand = el( 'div', { class: 'pst-pv__nav-brand' } );
		if ( v.logo && /^https?:/.test( v.logo ) ) { brand.appendChild( el( 'img', { src: v.logo, alt: '' } ) ); }
		brand.appendChild( el( 'span', { text: v.brand_text || 'Postelio' } ) );
		var links = el( 'div', { class: 'pst-pv__nav-links' } );
		( Array.isArray( v.items ) ? v.items : [] ).forEach( function ( it ) { if ( it.label ) { links.appendChild( el( 'a', { text: it.label } ) ); } } );
		var actions = el( 'div', { class: 'pst-pv__nav-actions' } );
		if ( v.show_login !== false ) { actions.appendChild( btn( v.login_label || 'Connexion', false ) ); }
		if ( v.show_signup !== false ) { actions.appendChild( btn( v.signup_label || 'Inscription', true ) ); }
		pv.appendChild( el( 'header', { class: 'pst-pv__nav' }, [ brand, links, actions ] ) );
		pv.appendChild( el( 'div', { class: 'pst-ed-preview-hint', text: 'Aperçu de l\'en-tête' } ) );
	}

	function buildFooter( pv ) {
		var v = state;
		var cols = el( 'div', { class: 'pst-pv__footer-cols' } );
		var brandCol = el( 'div', {}, [ el( 'div', { class: 'pst-pv__footer-brand', text: v.brand_text || 'Postelio' } ), el( 'p', { class: 'pst-pv__footer-desc', text: v.description || '' } ) ] );
		cols.appendChild( brandCol );
		( Array.isArray( v.columns ) ? v.columns : [] ).forEach( function ( c ) {
			var col = el( 'div', {}, [ el( 'h4', { text: c.title || '' } ) ] );
			( c.links || '' ).split( '\n' ).forEach( function ( line ) { var lbl = line.split( '|' )[ 0 ].trim(); if ( lbl ) { col.appendChild( el( 'a', { text: lbl } ) ); } } );
			cols.appendChild( col );
		} );
		var socials = el( 'div', { class: 'pst-pv__socials' } );
		( Array.isArray( v.socials ) ? v.socials : [] ).forEach( function ( s ) { if ( s.network ) { socials.appendChild( el( 'span', { text: s.network } ) ); } } );
		pv.appendChild( el( 'footer', { class: 'pst-pv__footer' }, [ cols, el( 'div', { class: 'pst-pv__footer-bottom' }, [ el( 'span', { text: v.copyright || '' } ), socials ] ) ] ) );
	}

	function buildAppearance( pv ) {
		var a = state;
		var swatches = el( 'div', { class: 'pst-pv__swatches' } );
		[ [ 'Primaire', a.color_primary ], [ 'Accent', a.color_accent ], [ 'Fond', a.color_bg ], [ 'Texte', a.color_text ] ].forEach( function ( pair ) {
			var c = el( 'div', { class: 'pst-pv__swatch-c' } ); c.style.background = pair[ 1 ] || '#ccc';
			swatches.appendChild( el( 'div', { class: 'pst-pv__swatch' }, [ c, el( 'div', { class: 'pst-pv__swatch-l' }, [ el( 'b', { text: pair[ 0 ] } ), document.createTextNode( pair[ 1 ] || '' ) ] ) ] ) );
		} );
		pv.appendChild( swatches );
		pv.appendChild( heroBlock( { title: 'Exemple de titre', subtitle: 'Un aperçu du style appliqué à votre site.', height: 'medium', overlay: true, cta_primary_label: 'Bouton principal', cta_secondary_label: 'Bouton secondaire', search_placeholder: 'Rechercher…' } ) );
		pv.appendChild( el( 'section', { class: 'pst-pv__section' }, [ el( 'h2', { text: 'Titre de section' } ), el( 'p', { text: 'Texte courant dans la police et la taille choisies. Les boutons ci-dessous reflètent le style sélectionné.' } ), el( 'div', { class: 'pst-pv__cta-row' }, [ btn( 'Principal', true ), btn( 'Secondaire', false ) ] ) ] ) );
	}

	function buildGeneric( pv ) {
		var v = state, s = CFG.schema.fields;
		var title = v.hero_title || v.title || ( CFG.schema.label ) || 'Page';
		pv.appendChild( heroBlock( { title: title, subtitle: v.hero_subtitle || '', height: 'medium', overlay: true, search_placeholder: 'Rechercher…' } ) );
		var body = el( 'section', { class: 'pst-pv__section' } );
		if ( v.hero_intro || v.text ) { body.appendChild( el( 'p', { text: v.hero_intro || v.text } ) ); }
		body.appendChild( el( 'div', { class: 'pst-pv__grid' }, [ ph(), ph(), ph() ] ) );
		pv.appendChild( body );
		function ph() { return el( 'div', { class: 'pst-pv__card' }, [ el( 'div', { class: 'pst-pv__card-ph' } ), el( 'div', { class: 'pst-pv__card-ph short' } ) ] ); }
	}

	// ============================================================ SAVE BAR
	function savebar( show, msg, sub ) {
		var bar = document.getElementById( 'pst-ed-savebar' );
		if ( ! bar ) { return; }
		bar.classList.toggle( 'is-visible', !! show );
		var m = bar.querySelector( '.pst-ed-savebar__msg' );
		if ( m ) { m.innerHTML = ''; m.appendChild( document.createTextNode( msg || 'Modifications non enregistrées' ) ); if ( sub ) { m.appendChild( el( 'small', { text: sub } ) ); } }
	}

	function save() {
		var btnSave = document.getElementById( 'pst-ed-save' );
		btnSave.setAttribute( 'disabled', 'disabled' ); btnSave.textContent = 'Enregistrement…';
		fetch( CFG.saveUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.restNonce },
			credentials: 'same-origin',
			body: JSON.stringify( { values: state } )
		} ).then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, j: j }; } ); } )
		.then( function ( res ) {
			btnSave.removeAttribute( 'disabled' ); btnSave.textContent = 'Enregistrer';
			if ( res.ok && res.j && res.j.data ) {
				state = deepClone( res.j.data.values || state );
				dirty = false; savebar( true, '✓ Modifications enregistrées' );
				renderEditor(); renderPreview();
				setTimeout( function () { if ( ! dirty ) { savebar( false ); } }, 2200 );
			} else {
				savebar( true, '⚠ Échec de l\'enregistrement', ( res.j && res.j.error && res.j.error.message ) || 'Réessayez.' );
			}
		} ).catch( function () {
			btnSave.removeAttribute( 'disabled' ); btnSave.textContent = 'Enregistrer';
			savebar( true, '⚠ Erreur réseau', 'Vérifiez votre connexion.' );
		} );
	}

	// ============================================================ INIT
	function init() {
		// Sélecteur d'appareil
		Array.prototype.forEach.call( document.querySelectorAll( '.pst-ed-devices button' ), function ( b ) {
			b.addEventListener( 'click', function () {
				device = b.getAttribute( 'data-device' );
				Array.prototype.forEach.call( document.querySelectorAll( '.pst-ed-devices button' ), function ( x ) { x.classList.toggle( 'is-active', x === b ); } );
				renderPreview();
			} );
		} );
		var s = document.getElementById( 'pst-ed-save' ); if ( s ) { s.addEventListener( 'click', save ); }
		var s2 = document.getElementById( 'pst-ed-savebar-save' ); if ( s2 ) { s2.addEventListener( 'click', save ); }
		var c = document.getElementById( 'pst-ed-savebar-cancel' ); if ( c ) { c.addEventListener( 'click', function () { state = deepClone( CFG.values || {} ); dirty = false; savebar( false ); renderEditor(); renderPreview(); } ); }
		window.addEventListener( 'beforeunload', function ( e ) { if ( dirty ) { e.preventDefault(); e.returnValue = ''; } } );
		renderEditor(); renderPreview();
	}

	function normalizeHex( v ) { return /^#[0-9a-fA-F]{6}$/.test( v ) ? v : '#000000'; }

	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', init ); } else { init(); }
} )();
