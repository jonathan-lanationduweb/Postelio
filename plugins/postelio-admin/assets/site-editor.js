/**
 * Éditeur visuel du site Postelio (« Site Builder ») — Phase 2.
 * Piloté par le schéma injecté (window.PST_SITE). Éditeur à gauche, aperçu fidèle et responsive à
 * droite, save bar. Vanilla JS. Chaînes utilisateur insérées via textContent (jamais innerHTML)
 * dans l'aperçu → pas d'injection.
 */
( function () {
	'use strict';

	var CFG = window.PST_SITE;
	if ( ! CFG || ! CFG.schema ) { return; }

	var state = deepClone( CFG.values || {} );
	var dirty = false;
	var device = 'desktop';
	var previewTimer = null;
	var activeSeo = 'home';
	var resolveCache = {}; // { type: { id: {label,sub,state,missing} } }

	// SEO : chemins front par page (aperçu SERP).
	var SEO_PATHS = { home: '/', jobs: '/offres', companies: '/entreprises', skills: '/savoir-faire', advice: '/conseils', contact: '/contact' };

	// ------------------------------------------------------------- utilitaires
	function deepClone( o ) { return JSON.parse( JSON.stringify( o == null ? {} : o ) ); }
	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		if ( attrs ) { Object.keys( attrs ).forEach( function ( k ) {
			if ( k === 'class' ) { n.className = attrs[ k ]; }
			else if ( k === 'text' ) { n.textContent = attrs[ k ]; }
			else if ( k.indexOf( 'on' ) === 0 && typeof attrs[ k ] === 'function' ) { n.addEventListener( k.slice( 2 ), attrs[ k ] ); }
			else if ( attrs[ k ] != null ) { n.setAttribute( k, attrs[ k ] ); }
		} ); }
		( kids || [] ).forEach( function ( c ) { if ( c != null ) { n.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c ); } } );
		return n;
	}
	function markDirty() { if ( ! dirty ) { dirty = true; savebar( true ); } schedulePreview(); }
	function schedulePreview() { clearTimeout( previewTimer ); previewTimer = setTimeout( renderPreview, 120 ); }
	function appearance() { return CFG.page === 'appearance' ? state : ( CFG.appearance || {} ); }

	// ------------------------------------------------------------- state path
	function getPath( path ) { var o = state; for ( var i = 0; i < path.length; i++ ) { if ( o == null ) { return undefined; } o = o[ path[ i ] ]; } return o; }
	function setPath( path, val ) { var o = state; for ( var i = 0; i < path.length - 1; i++ ) { if ( o[ path[ i ] ] == null ) { o[ path[ i ] ] = ( typeof path[ i + 1 ] === 'number' ) ? [] : {}; } o = o[ path[ i ] ]; } o[ path[ path.length - 1 ] ] = val; }

	// ------------------------------------------------------------- defaults (reset)
	function fieldDefault( f ) {
		if ( f.default !== undefined ) { return deepClone( f.default ); }
		if ( f.type === 'toggle' ) { return false; }
		if ( f.type === 'number' ) { return 0; }
		if ( f.type === 'repeater' || f.type === 'collection' ) { return []; }
		return '';
	}
	function schemaDefaults( schema ) {
		if ( schema.type === 'sections' ) {
			var out = { _order: Object.keys( schema.sections ) };
			Object.keys( schema.sections ).forEach( function ( sk ) {
				var sec = {}; var fs = schema.sections[ sk ].fields || {};
				Object.keys( fs ).forEach( function ( fk ) { sec[ fk ] = fieldDefault( fs[ fk ] ); } );
				sec._enabled = schema.sections[ sk ].enabled_default !== false;
				out[ sk ] = sec;
			} );
			return out;
		}
		var o = {}; Object.keys( schema.fields ).forEach( function ( fk ) { o[ fk ] = fieldDefault( schema.fields[ fk ] ); } );
		return o;
	}

	// ============================================================ ÉDITEUR
	function renderEditor() {
		var panel = document.getElementById( 'pst-ed-panel' );
		panel.innerHTML = '';
		var schema = CFG.schema;

		if ( schema.backend_note ) { panel.appendChild( el( 'div', { class: 'pst-ed-note', text: schema.backend_note } ) ); }
		if ( schema.source_note ) { panel.appendChild( el( 'div', { class: 'pst-ed-note', text: schema.source_note } ) ); }
		if ( schema.resettable ) { panel.appendChild( resetControl() ); }

		if ( schema.type === 'sections' ) {
			var order = ( state._order && state._order.length ) ? state._order : Object.keys( schema.sections );
			// N'afficher que les sections connues, puis compléter par celles manquantes.
			order = order.filter( function ( k ) { return schema.sections[ k ]; } );
			Object.keys( schema.sections ).forEach( function ( k ) { if ( order.indexOf( k ) < 0 ) { order.push( k ); } } );
			order.forEach( function ( skey ) { panel.appendChild( sectionCard( skey, schema.sections[ skey ] ) ); } );
		} else if ( schema.groups ) {
			schema.groups.forEach( function ( g ) {
				panel.appendChild( plainCard( g.label, function ( body ) {
					g.fields.forEach( function ( fk ) { if ( schema.fields[ fk ] ) { body.appendChild( fieldRow( fk, schema.fields[ fk ], [ fk ] ) ); } } );
				} ) );
			} );
		} else {
			panel.appendChild( plainCard( 'Réglages', function ( body ) {
				Object.keys( schema.fields ).forEach( function ( fk ) { body.appendChild( fieldRow( fk, schema.fields[ fk ], [ fk ] ) ); } );
			} ) );
		}
	}

	function resetControl() {
		return el( 'div', { class: 'pst-ed-reset' }, [
			el( 'button', { type: 'button', class: 'pst-ed-btn pst-ed-btn--sm', text: '↺ Réinitialiser aux valeurs Postelio', onclick: function () {
				if ( window.confirm( 'Réinitialiser cette page aux valeurs Postelio par défaut ? Vos modifications non enregistrées seront perdues.' ) ) {
					state = schemaDefaults( CFG.schema ); markDirty(); renderEditor(); renderPreview();
				}
			} } )
		] );
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

	function sectionCard( skey, sdef ) {
		var sval = state[ skey ] = state[ skey ] || {};
		var noToggle = !! sdef.no_toggle;
		var enabled = noToggle || sval._enabled !== false;

		var body = el( 'div', { class: 'pst-ed-card__body' } );
		Object.keys( sdef.fields || {} ).forEach( function ( fk ) { body.appendChild( fieldRow( fk, sdef.fields[ fk ], [ skey, fk ] ) ); } );

		var headKids = [];
		if ( CFG.schema.type === 'sections' && sdef.reorderable !== false && ! CFG.schema.seo ) {
			headKids.push( el( 'div', { class: 'pst-ed-card__reorder' }, [
				el( 'button', { title: 'Monter', text: '▲', 'aria-label': 'Monter la section', onclick: function ( e ) { e.stopPropagation(); moveSection( skey, -1 ); } } ),
				el( 'button', { title: 'Descendre', text: '▼', 'aria-label': 'Descendre la section', onclick: function ( e ) { e.stopPropagation(); moveSection( skey, 1 ); } } )
			] ) );
		} else {
			headKids.push( el( 'span', { class: 'pst-ed-card__grip', text: CFG.schema.seo ? '' : '⋮⋮' } ) );
		}
		headKids.push( el( 'span', { class: 'pst-ed-card__title', text: sdef.label || skey } ) );
		if ( ! noToggle ) {
			headKids.push( labelWrap( switchEl( enabled, function ( on ) { sval._enabled = on; card.classList.toggle( 'is-off', ! on ); markDirty(); } ) ) );
		}
		headKids.push( el( 'span', { class: 'pst-ed-card__chevron', text: '▸' } ) );

		var head = el( 'div', { class: 'pst-ed-card__head', onclick: function () {
			card.classList.toggle( 'is-open' );
			if ( CFG.schema.seo && card.classList.contains( 'is-open' ) && skey !== 'global' ) { activeSeo = skey; schedulePreview(); }
		} }, headKids );

		var card = el( 'div', { class: 'pst-ed-card' + ( enabled ? '' : ' is-off' ) }, [ head, body ] );
		return card;
	}

	function labelWrap( node ) { var w = el( 'span', {}, [ node ] ); w.addEventListener( 'click', function ( e ) { e.stopPropagation(); } ); return w; }

	function moveSection( skey, dir ) {
		var schema = CFG.schema;
		var order = ( state._order && state._order.length ) ? state._order.slice() : Object.keys( schema.sections );
		order = order.filter( function ( k ) { return schema.sections[ k ]; } );
		Object.keys( schema.sections ).forEach( function ( k ) { if ( order.indexOf( k ) < 0 ) { order.push( k ); } } );
		var i = order.indexOf( skey ), j = i + dir;
		if ( i < 0 || j < 0 || j >= order.length ) { return; }
		order.splice( j, 0, order.splice( i, 1 )[ 0 ] );
		state._order = order; markDirty(); renderEditor(); renderPreview();
	}

	// ------------------------------------------------------------- champs
	function fieldRow( fk, fdef, path ) {
		var type = fdef.type || 'text';
		if ( type === 'repeater' ) { return wrapField( fdef, repeaterField( fk, fdef, path ) ); }
		if ( type === 'collection' ) { return wrapField( fdef, collectionField( fk, fdef, path ) ); }
		if ( type === 'toggle' ) { return toggleField( fk, fdef, path ); }
		if ( type === 'color' ) { return colorField( fk, fdef, path ); }
		if ( type === 'media' ) { return mediaField( fk, fdef, path ); }

		var value = getPath( path );
		var control, counter = null;
		if ( type === 'textarea' ) {
			control = el( 'textarea', { class: 'pst-ed-textarea', placeholder: fdef.placeholder || '' } );
			control.value = value || '';
			control.addEventListener( 'input', function () { setPath( path, control.value ); if ( counter ) { updateCounter( counter, control.value, fdef.counter ); } markDirty(); } );
		} else if ( type === 'select' ) {
			control = el( 'select', { class: 'pst-ed-select' } );
			var opts = fdef.options || {};
			Object.keys( opts ).forEach( function ( ov ) { control.appendChild( el( 'option', { value: ov, text: opts[ ov ] } ) ); } );
			control.value = value; control.addEventListener( 'change', function () { setPath( path, control.value ); markDirty(); } );
		} else if ( type === 'number' ) {
			control = el( 'input', { class: 'pst-ed-input', type: 'number', min: fdef.min, max: fdef.max } );
			control.value = value; control.addEventListener( 'input', function () { setPath( path, parseInt( control.value, 10 ) || 0 ); markDirty(); } );
		} else {
			control = el( 'input', { class: 'pst-ed-input', type: 'text', placeholder: fdef.placeholder || '' } );
			control.value = value || '';
			control.addEventListener( 'input', function () { setPath( path, control.value ); if ( counter ) { updateCounter( counter, control.value, fdef.counter ); } markDirty(); } );
		}
		var wrap = wrapField( fdef, control );
		if ( fdef.counter ) { counter = el( 'div', { class: 'pst-ed-count' } ); updateCounter( counter, value || '', fdef.counter ); wrap.appendChild( counter ); }
		return wrap;
	}

	function updateCounter( node, val, max ) { var n = ( val || '' ).length; node.textContent = n + ' / ' + max; node.classList.toggle( 'is-over', n > max ); }

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
		frame.on( 'select', function () { var att = frame.state().get( 'selection' ).first().toJSON(); setPath( path, att.url ); paint(); chooseBtn.textContent = 'Remplacer'; markDirty(); } );
		frame.open();
	}

	// ------------------------------------------------------------- repeater
	function repeaterField( fk, fdef, path ) {
		var rows = getPath( path );
		if ( ! Array.isArray( rows ) ) { rows = []; setPath( path, rows ); }
		var wrap = el( 'div', { class: 'pst-ed-rep' } );
		function rebuild() {
			wrap.innerHTML = '';
			rows.forEach( function ( row, i ) { wrap.appendChild( repRow( i ) ); } );
			wrap.appendChild( el( 'button', { class: 'pst-ed-rep__add', type: 'button', text: '+ Ajouter', onclick: function () {
				var blank = {}; Object.keys( fdef.fields ).forEach( function ( sk ) { blank[ sk ] = ''; } );
				rows.push( blank ); markDirty(); rebuild(); schedulePreview();
			} } ) );
		}
		function repRow( i ) {
			var body = el( 'div', { class: 'pst-ed-rep__row' } );
			body.appendChild( el( 'div', { class: 'pst-ed-rep__row-head' }, [
				el( 'span', { class: 'pst-ed-rep__row-title', text: ( fdef.label || 'Élément' ) + ' ' + ( i + 1 ) } ),
				el( 'div', { class: 'pst-ed-rep__row-tools' }, [
					el( 'button', { type: 'button', title: 'Monter', 'aria-label': 'Monter', text: '▲', onclick: function () { if ( i > 0 ) { rows.splice( i - 1, 0, rows.splice( i, 1 )[ 0 ] ); markDirty(); rebuild(); schedulePreview(); } } } ),
					el( 'button', { type: 'button', title: 'Descendre', 'aria-label': 'Descendre', text: '▼', onclick: function () { if ( i < rows.length - 1 ) { rows.splice( i + 1, 0, rows.splice( i, 1 )[ 0 ] ); markDirty(); rebuild(); schedulePreview(); } } } ),
					el( 'button', { type: 'button', title: 'Supprimer', 'aria-label': 'Supprimer', text: '✕', onclick: function () { rows.splice( i, 1 ); markDirty(); rebuild(); schedulePreview(); } } )
				] )
			] ) );
			Object.keys( fdef.fields ).forEach( function ( sk ) { body.appendChild( fieldRow( sk, fdef.fields[ sk ], path.concat( [ i, sk ] ) ) ); } );
			return body;
		}
		rebuild();
		return wrap;
	}

	// ------------------------------------------------------------- collection (sélecteur de contenu)
	function collectionField( fk, fdef, path ) {
		var type = fdef.ref_type || 'job';
		var ids = getPath( path );
		if ( ! Array.isArray( ids ) ) { ids = []; setPath( path, ids ); }
		resolveCache[ type ] = resolveCache[ type ] || {};

		var list = el( 'div', { class: 'pst-ed-coll__list' } );
		var wrap = el( 'div', { class: 'pst-ed-coll' }, [ list, searchBox() ] );

		function render() {
			list.innerHTML = '';
			if ( ! ids.length ) { list.appendChild( el( 'p', { class: 'pst-ed-coll__empty', text: 'Aucune sélection. Recherchez du contenu ci-dessous.' } ) ); return; }
			ids.forEach( function ( id, i ) {
				var item = resolveCache[ type ][ id ];
				var missing = item && item.missing;
				var thumbText = missing ? '!' : ( item && item.label ? item.label.trim().charAt( 0 ).toUpperCase() : '…' );
				var row = el( 'div', { class: 'pst-ed-coll__item' + ( missing ? ' is-missing' : '' ) }, [
					el( 'div', { class: 'pst-ed-coll__thumb', text: thumbText } ),
					el( 'div', { class: 'pst-ed-coll__main' }, [
						el( 'div', { class: 'pst-ed-coll__label', text: item ? ( missing ? 'Contenu indisponible' : ( item.label || id ) ) : 'Chargement…' } ),
						el( 'div', { class: 'pst-ed-coll__sub', text: item && ! missing ? ( item.sub || '' ) : ( 'réf. ' + id ) } )
					] ),
					( item && ! missing && item.state ) ? el( 'span', { class: 'pst-ed-coll__state', text: item.state } ) : null,
					el( 'div', { class: 'pst-ed-coll__tools' }, [
						el( 'button', { type: 'button', title: 'Monter', 'aria-label': 'Monter', text: '▲', onclick: function () { if ( i > 0 ) { ids.splice( i - 1, 0, ids.splice( i, 1 )[ 0 ] ); markDirty(); render(); } } } ),
						el( 'button', { type: 'button', title: 'Descendre', 'aria-label': 'Descendre', text: '▼', onclick: function () { if ( i < ids.length - 1 ) { ids.splice( i + 1, 0, ids.splice( i, 1 )[ 0 ] ); markDirty(); render(); } } } ),
						el( 'button', { type: 'button', title: 'Retirer', 'aria-label': 'Retirer', text: '✕', onclick: function () { ids.splice( i, 1 ); markDirty(); render(); schedulePreview(); } } )
					] )
				] );
				list.appendChild( row );
			} );
		}

		function searchBox() {
			var input = el( 'input', { class: 'pst-ed-input', type: 'search', placeholder: 'Rechercher à ajouter…' } );
			var results = el( 'div', { class: 'pst-ed-coll__results' } );
			var box = el( 'div', { class: 'pst-ed-coll__search' }, [ input, results ] );
			var t = null;
			input.addEventListener( 'input', function () {
				clearTimeout( t );
				var q = input.value.trim();
				if ( q.length < 2 ) { results.classList.remove( 'is-open' ); return; }
				t = setTimeout( function () { doSearch( q, results, input ); }, 250 );
			} );
			input.addEventListener( 'blur', function () { setTimeout( function () { results.classList.remove( 'is-open' ); }, 180 ); } );
			return box;
		}

		function doSearch( q, results, input ) {
			fetch( CFG.searchUrl + '?type=' + encodeURIComponent( type ) + '&q=' + encodeURIComponent( q ), { headers: { 'X-WP-Nonce': CFG.restNonce }, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				var items = ( j && j.data && j.data.items ) || [];
				results.innerHTML = '';
				if ( ! items.length ) { results.appendChild( el( 'div', { class: 'pst-ed-coll__empty', text: 'Aucun résultat.' } ) ); results.classList.add( 'is-open' ); return; }
				items.forEach( function ( it ) {
					var already = ids.indexOf( it.id ) >= 0;
					var r = el( 'div', { class: 'pst-ed-coll__result', onclick: function () {
						if ( ! already && it.id ) {
							resolveCache[ type ][ it.id ] = { label: it.label, sub: it.sub, state: it.state, missing: false };
							ids.push( it.id ); markDirty(); render(); schedulePreview();
							input.value = ''; results.classList.remove( 'is-open' );
						}
					} }, [
						el( 'b', { text: it.label + ( already ? '  ✓' : '' ) } ),
						el( 'span', { text: [ it.sub, it.state ].filter( Boolean ).join( ' · ' ) } )
					] );
					results.appendChild( r );
				} );
				results.classList.add( 'is-open' );
			} ).catch( function () { results.innerHTML = ''; results.appendChild( el( 'div', { class: 'pst-ed-coll__empty', text: 'Recherche indisponible.' } ) ); results.classList.add( 'is-open' ); } );
		}

		render();
		// Résout les ids inconnus.
		var unknown = ids.filter( function ( id ) { return ! resolveCache[ type ][ id ]; } );
		if ( unknown.length ) {
			fetch( CFG.resolveUrl + '?type=' + encodeURIComponent( type ) + '&ids=' + encodeURIComponent( unknown.join( ',' ) ), { headers: { 'X-WP-Nonce': CFG.restNonce }, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) { var items = ( j && j.data && j.data.items ) || []; items.forEach( function ( it ) { resolveCache[ type ][ it.id ] = it; } ); render(); } )
			.catch( function () {} );
		}
		return wrap;
	}

	// ============================================================ APERÇU
	function renderPreview() {
		var canvas = document.getElementById( 'pst-ed-canvas' );
		canvas.className = 'pst-ed-canvas' + ( device === 'tablet' ? ' is-tablet' : device === 'mobile' ? ' is-mobile' : '' );
		canvas.innerHTML = '';
		var pv = el( 'div', { class: 'pst-pv' } );
		applyTheme( pv, appearance() );
		var page = CFG.page;
		if ( page === 'navigation' ) { buildNav( pv ); }
		else if ( page === 'footer' ) { buildFooter( pv ); }
		else if ( page === 'appearance' ) { buildAppearance( pv ); }
		else if ( page === 'seo' ) { buildSEO( pv ); }
		else if ( CFG.schema.type === 'sections' ) { buildSections( pv ); }
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

	// --- Données de PREVIEW (fixtures visuelles, jamais persistées) --------
	var FIX = {
		job: [ { t: 'Assistant(e) de direction', co: 'NotifCo', v: 'Paris', c: 'CDI' }, { t: 'Secrétaire médical(e)', co: 'Santé+', v: 'Lyon', c: 'CDD' }, { t: 'Office manager', co: 'TechEa', v: 'Télétravail', c: 'CDI' }, { t: 'Assistant(e) commercial(e)', co: 'VentePro', v: 'Nantes', c: 'Alternance' }, { t: 'Gestionnaire ADV', co: 'LogiFlux', v: 'Lille', c: 'CDI' }, { t: 'Chargé(e) d\'accueil', co: 'Groupe Belair', v: 'Bordeaux', c: 'Intérim' } ],
		company: [ { t: 'NotifCo', s: 'Tech', ok: true }, { t: 'Santé+', s: 'Médical', ok: true }, { t: 'VentePro', s: 'Commerce', ok: false }, { t: 'LogiFlux', s: 'Logistique', ok: true }, { t: 'Groupe Belair', s: 'Immobilier', ok: true }, { t: 'TechEa', s: 'SaaS', ok: false }, { t: 'Cabinet Roux', s: 'Juridique', ok: true }, { t: 'EcoBat', s: 'BTP', ok: false } ],
		skill: [ { t: 'Maîtriser Excel en 10 astuces', a: 'Camille D.', cat: 'Bureautique' }, { t: 'Gérer un agenda partagé', a: 'Yanis B.', cat: 'Organisation' }, { t: 'Rédiger un e-mail pro', a: 'Sofia L.', cat: 'Communication' }, { t: 'Classer ses documents', a: 'Inès M.', cat: 'Méthode' }, { t: 'Prendre des notes efficaces', a: 'Karim T.', cat: 'Méthode' }, { t: 'Accueil téléphonique', a: 'Léa P.', cat: 'Relation' } ],
		article: [ { t: '5 conseils pour votre CV administratif', cat: 'Carrière', d: '12 mai' }, { t: 'Réussir son entretien à distance', cat: 'Entretien', d: '3 mai' }, { t: 'Les métiers du secrétariat en 2026', cat: 'Actualités', d: '28 avr' }, { t: 'Lettre de motivation : le guide', cat: 'Candidature', d: '20 avr' } ]
	};
	var CATS = [ 'Assistanat', 'Secrétariat', 'Comptabilité', 'Accueil', 'Ressources humaines', 'Juridique', 'Commercial', 'Médical' ];

	function initials( s ) { s = ( s || '?' ).trim(); var p = s.split( /\s+/ ); return ( ( p[ 0 ] || '' )[ 0 ] || '' ) + ( p.length > 1 ? ( p[ p.length - 1 ][ 0 ] || '' ) : '' ) || s[ 0 ] || '?'; }
	function chip( t, mod ) { return t ? el( 'span', { class: 'pst-pv__chip' + ( mod ? ' pst-pv__chip--' + mod : '' ), text: t } ) : null; }
	function logoBox( name, cls ) { return el( 'div', { class: cls || 'pst-pv__logo', text: initials( name ).toUpperCase() } ); }

	function collKind( skey ) { var s = CFG.schema.sections && CFG.schema.sections[ skey ]; return ( s && s.fields && s.fields.items && s.fields.items.ref_type ) || pageKind(); }
	function pageKind() { return CFG.page === 'jobs' ? 'job' : CFG.page === 'companies' ? 'company' : CFG.page === 'advice' ? 'article' : 'skill'; }

	// Données réelles (sélection manuelle résolue) sinon fixtures.
	function cardData( v, kind, fallbackN ) {
		if ( v && v.mode === 'manual' && Array.isArray( v.items ) && v.items.length ) {
			var real = v.items.map( function ( id ) { return ( resolveCache[ kind ] || {} )[ id ]; } ).filter( function ( x ) { return x && ! x.missing; } );
			if ( real.length ) { return real.map( function ( x ) { return { t: x.label, co: x.sub, s: x.sub, a: x.sub, v: '', c: '', cat: '', d: '', ok: x.state === 'verified' }; } ); }
		}
		var n = Math.max( 1, Math.min( 12, parseInt( ( v && v.count ) || fallbackN, 10 ) || fallbackN ) );
		var base = FIX[ kind ] || FIX.job, out = [];
		for ( var i = 0; i < n; i++ ) { out.push( base[ i % base.length ] ); }
		return out;
	}

	function card( kind, d ) {
		if ( kind === 'company' ) {
			return el( 'div', { class: 'pst-pv__cocard' }, [ logoBox( d.t ), el( 'h3', { text: d.t } ), el( 'div', { class: 'pst-pv__byline', text: d.s || '' } ), d.ok ? chip( '✓ Vérifiée', 'ok' ) : chip( 'Entreprise' ) ] );
		}
		if ( kind === 'skill' || kind === 'article' ) {
			return el( 'div', { class: 'pst-pv__mediacard' }, [
				el( 'div', { class: 'pst-pv__thumb pst-pv__thumb--' + ( kind === 'article' ? 'art' : 'skill' ) } ),
				el( 'div', { class: 'pst-pv__mediacard-body' }, [
					el( 'div', { class: 'pst-pv__tag', text: d.cat || '' } ),
					el( 'h3', { text: d.t } ),
					el( 'div', { class: 'pst-pv__byline', text: kind === 'article' ? ( d.d || '' ) : ( d.a || '' ) } )
				] )
			] );
		}
		// job
		return el( 'div', { class: 'pst-pv__jobcard' }, [
			el( 'div', { class: 'pst-pv__jobcard-top' }, [ logoBox( d.co || d.t ), el( 'div', {}, [ el( 'h3', { text: d.t } ), el( 'div', { class: 'pst-pv__jobcard-co', text: d.co || '' } ) ] ) ] ),
			el( 'div', { class: 'pst-pv__meta' }, [ chip( d.v ), chip( d.c, 'accent' ) ] )
		] );
	}

	function buildSections( pv ) {
		var schema = CFG.schema;
		var order = ( state._order && state._order.length ) ? state._order : Object.keys( schema.sections );
		order = order.filter( function ( k ) { return schema.sections[ k ]; } );
		Object.keys( schema.sections ).forEach( function ( k ) { if ( order.indexOf( k ) < 0 ) { order.push( k ); } } );
		order.forEach( function ( skey ) {
			if ( ! on( skey ) ) { return; }
			var v = state[ skey ] || {};
			pv.appendChild( sectionPreview( skey, v ) );
		} );
		if ( ! pv.children.length ) { pv.appendChild( el( 'div', { class: 'pst-ed-empty', text: 'Toutes les sections sont désactivées.' } ) ); }
	}

	function sectionPreview( skey, v ) {
		switch ( skey ) {
			case 'hero':        return heroBlock( v );
			case 'search':      return searchBlock( v );
			case 'filters':     return filtersBlock( v );
			case 'results':
			case 'feed':        return resultsBlock( v );
			case 'arguments':   return argsBlock( v );
			case 'cta':         return ctaBlock( v );
			case 'categories':  return categoriesBlock( v );
			case 'intro':       return introBlock( v );
			case 'coordinates': return coordinatesBlock( v );
			case 'form':        return formBlock( v );
			case 'extra':       return introBlock( v );
			default:            return collectionBlock( skey, v );
		}
	}

	function heroBlock( v ) {
		var cls = 'pst-pv__hero h-' + ( v.height || 'large' ) + ( v.align === 'center' ? ' align-center' : '' ) + ( v.text_light === false ? ' text-dark' : '' );
		var hero = el( 'section', { class: cls } );
		if ( v.background && /^https?:/.test( v.background ) ) { hero.style.backgroundImage = 'url(' + v.background + ')'; }
		if ( v.overlay !== false ) { hero.appendChild( el( 'div', { class: 'pst-pv__hero-overlay' } ) ); }
		var inner = el( 'div', { class: 'pst-pv__hero-inner' } );
		if ( v.title ) { inner.appendChild( el( 'h1', { text: v.title } ) ); }
		if ( v.subtitle ) { inner.appendChild( el( 'p', { text: v.subtitle } ) ); }
		if ( typeof v.search_placeholder === 'string' && v.search_placeholder !== '' ) {
			inner.appendChild( el( 'div', { class: 'pst-pv__searchbar' }, [ el( 'input', { disabled: 'disabled', placeholder: v.search_placeholder } ), btn( 'Rechercher', true ) ] ) );
		}
		var ctas = el( 'div', { class: 'pst-pv__cta-row' }, [ btn( v.cta_primary_label, true ), btn( v.cta_secondary_label, false ) ] );
		if ( ctas.children.length ) { inner.appendChild( ctas ); }
		hero.appendChild( inner );
		return hero;
	}

	function searchBlock( v ) {
		return el( 'section', { class: 'pst-pv__section' }, [
			v.title ? el( 'h2', { text: v.title } ) : null,
			el( 'div', { class: 'pst-pv__searchbar' }, [
				el( 'input', { disabled: 'disabled', placeholder: v.placeholder_role || 'Rechercher' } ),
				el( 'input', { disabled: 'disabled', placeholder: v.placeholder_city || 'Ville' } ),
				btn( v.button_label || 'Rechercher', true )
			] )
		] );
	}

	function filtersBlock( v ) {
		var chips = el( 'div', { class: 'pst-pv__filters' } );
		( Array.isArray( v.filters ) ? v.filters : [] ).forEach( function ( f ) { if ( f.label && f.visible !== false ) { chips.appendChild( el( 'span', { class: 'pst-pv__filter', text: f.label + '  ▾' } ) ); } } );
		return el( 'section', { class: 'pst-pv__section' }, [ v.title ? el( 'h2', { text: v.title } ) : null, chips ] );
	}

	function resultsBlock( v ) {
		var kind = pageKind();
		var n = Math.max( 2, Math.min( 9, parseInt( v.per_page, 10 ) || 6 ) );
		var grid = el( 'div', { class: 'pst-pv__grid' } );
		cardData( { count: n }, kind, n ).forEach( function ( d ) { grid.appendChild( card( kind, d ) ); } );
		return el( 'section', { class: 'pst-pv__section' }, [ v.title ? el( 'h2', { text: v.title } ) : null, v.text ? el( 'p', { class: 'pst-pv__section-sub', text: v.text } ) : null, grid ] );
	}

	function collectionBlock( skey, v ) {
		var kind = collKind( skey );
		var manual = v.mode === 'manual' && Array.isArray( v.items );
		var data = cardData( v, kind, Math.max( 1, Math.min( 12, parseInt( v.count, 10 ) || 4 ) ) );
		var body;
		if ( manual && ( ! v.items || ! v.items.length ) ) { body = el( 'p', { class: 'pst-pv__section-sub', text: v.empty_text || 'Aucun contenu sélectionné.' } ); }
		else { body = el( 'div', { class: 'pst-pv__grid' } ); data.forEach( function ( d ) { body.appendChild( card( kind, d ) ); } ); }
		return el( 'section', { class: 'pst-pv__section' }, [
			v.title ? el( 'h2', { text: v.title } ) : null,
			v.subtitle ? el( 'p', { class: 'pst-pv__section-sub', text: v.subtitle } ) : null,
			body,
			v.cta_label ? el( 'div', { style: 'margin-top:18px' }, [ btn( v.cta_label, false ) ] ) : null
		] );
	}

	function categoriesBlock( v ) {
		var chips = el( 'div', { class: 'pst-pv__cats' } );
		var labels;
		if ( Array.isArray( v.items ) && v.items.length && v.items[ 0 ] && v.items[ 0 ].label !== undefined ) {
			labels = v.items.map( function ( it ) { return it.label; } );
		} else {
			var n = Array.isArray( v.items ) && v.items.length ? v.items.length : 6;
			labels = CATS.slice( 0, Math.max( 3, Math.min( 8, n ) ) );
		}
		labels.forEach( function ( l ) { if ( l ) { chips.appendChild( el( 'span', { class: 'pst-pv__cat', text: l } ) ); } } );
		return el( 'section', { class: 'pst-pv__section' }, [ v.title ? el( 'h2', { text: v.title } ) : null, chips ] );
	}

	function introBlock( v ) { return el( 'section', { class: 'pst-pv__section' }, [ v.title ? el( 'h2', { text: v.title } ) : null, el( 'p', { text: v.text || '' } ) ] ); }

	function coordinatesBlock( v ) {
		var row = el( 'div', { class: 'pst-pv__coords' } );
		[ [ '✉️', v.email ], [ '📞', v.phone ], [ '📍', v.address ] ].forEach( function ( p ) { if ( p[ 1 ] ) { row.appendChild( el( 'div', { class: 'pst-pv__coord' }, [ el( 'span', { text: p[ 0 ] } ), el( 'span', { text: p[ 1 ] } ) ] ) ); } } );
		return el( 'section', { class: 'pst-pv__section' }, [ el( 'h2', { text: 'Nous contacter' } ), row.children.length ? row : el( 'p', { class: 'pst-pv__section-sub', text: 'Ajoutez vos coordonnées publiques.' } ) ] );
	}

	function formBlock( v ) {
		var box = el( 'div', { class: 'pst-pv__form' } );
		box.appendChild( el( 'input', { disabled: 'disabled', placeholder: v.name_label || 'Votre nom' } ) );
		box.appendChild( el( 'input', { disabled: 'disabled', placeholder: v.email_label || 'Votre e-mail' } ) );
		box.appendChild( el( 'textarea', { disabled: 'disabled', placeholder: v.message_ph || 'Votre message', style: 'min-height:82px' } ) );
		if ( v.consent_text ) { box.appendChild( el( 'p', { class: 'pst-pv__section-sub', style: 'font-size:.78em;margin:0 0 10px', text: v.consent_text } ) ); }
		box.appendChild( btn( v.button_label || 'Envoyer', true ) );
		return el( 'section', { class: 'pst-pv__section' }, [ v.title ? el( 'h2', { text: v.title } ) : null, box ] );
	}

	function argsBlock( v ) {
		var grid = el( 'div', { class: 'pst-pv__args' } );
		( Array.isArray( v.items ) ? v.items : [] ).forEach( function ( it ) { grid.appendChild( el( 'div', { class: 'pst-pv__arg' }, [ el( 'div', { class: 'pst-pv__arg-ic', text: it.icon || '•' } ), el( 'h3', { text: it.title || '' } ), el( 'p', { text: it.text || '' } ) ] ) ); } );
		return el( 'section', { class: 'pst-pv__section' }, [ v.title ? el( 'h2', { text: v.title } ) : null, v.subtitle ? el( 'p', { class: 'pst-pv__section-sub', text: v.subtitle } ) : null, grid ] );
	}

	function ctaBlock( v ) {
		return el( 'section', { class: 'pst-pv__cta-band' }, [ v.title ? el( 'h2', { text: v.title } ) : null, v.text ? el( 'p', { text: v.text } ) : null, el( 'div', { style: 'margin-top:14px' }, [ btn( v.button_label || 'Commencer', true ) ] ) ] );
	}

	function phCard() { return el( 'div', { class: 'pst-pv__card' }, [ el( 'div', { class: 'pst-pv__card-ph' } ), el( 'div', { class: 'pst-pv__card-ph short' } ) ] ); }

	function buildNav( pv ) {
		var v = state;
		var brand = el( 'div', { class: 'pst-pv__nav-brand' } );
		var logo = ( v.use_identity_logo !== false && appearance().logo ) ? appearance().logo : v.logo;
		if ( logo && /^https?:/.test( logo ) ) { brand.appendChild( el( 'img', { src: logo, alt: '' } ) ); }
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
		var fbrand = el( 'div', { class: 'pst-pv__footer-brand' } );
		var flogo = ( v.use_identity_logo !== false && appearance().logo_light ) ? appearance().logo_light : ( ( v.use_identity_logo !== false && appearance().logo ) ? appearance().logo : v.logo );
		if ( flogo && /^https?:/.test( flogo ) ) { fbrand.appendChild( el( 'img', { src: flogo, alt: '' } ) ); }
		fbrand.appendChild( el( 'span', { text: v.brand_text || 'Postelio' } ) );
		cols.appendChild( el( 'div', {}, [ fbrand, el( 'p', { class: 'pst-pv__footer-desc', text: v.description || '' } ) ] ) );
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
		var board = el( 'div', { class: 'pst-pv__brand' } );

		// Identité
		board.appendChild( el( 'div', { class: 'pst-pv__brand-label', text: 'Identité' } ) );
		var ident = el( 'div', { class: 'pst-pv__ident' } );
		[ [ 'Logo', a.logo ], [ 'Logo clair', a.logo_light ], [ 'Favicon', a.favicon ], [ 'Image sociale', a.social_image ] ].forEach( function ( p ) {
			var box = el( 'div', { class: 'pst-pv__ident-box' } );
			if ( p[ 1 ] && /^https?:/.test( p[ 1 ] ) ) { box.style.backgroundImage = 'url(' + p[ 1 ] + ')'; box.textContent = ''; } else { box.textContent = '—'; }
			ident.appendChild( el( 'div', { class: 'pst-pv__ident-item' }, [ box, el( 'div', { class: 'pst-pv__ident-cap', text: p[ 0 ] } ) ] ) );
		} );
		board.appendChild( ident );

		// Palette
		board.appendChild( el( 'div', { class: 'pst-pv__brand-label', text: 'Palette' } ) );
		var swatches = el( 'div', { class: 'pst-pv__swatches' } );
		[ [ 'Bleu nuit', a.color_primary ], [ 'Corail', a.color_accent ], [ 'Fond', a.color_bg ], [ 'Texte', a.color_text ] ].forEach( function ( pair ) {
			var c = el( 'div', { class: 'pst-pv__swatch-c' } ); c.style.background = pair[ 1 ] || '#ccc';
			swatches.appendChild( el( 'div', { class: 'pst-pv__swatch' }, [ c, el( 'div', { class: 'pst-pv__swatch-l' }, [ el( 'b', { text: pair[ 0 ] } ), document.createTextNode( pair[ 1 ] || '' ) ] ) ] ) );
		} );
		board.appendChild( swatches );

		// Typographie
		board.appendChild( el( 'div', { class: 'pst-pv__brand-label', text: 'Typographie' } ) );
		board.appendChild( el( 'div', { class: 'pst-pv__type-specimen' }, [
			el( 'h1', { text: 'Titre principal' } ),
			el( 'h2', { text: 'Titre de section' } ),
			el( 'p', { text: 'Texte courant : la plateforme emploi dédiée aux métiers du secrétariat et de l\'assistanat.' } )
		] ) );

		// Boutons
		board.appendChild( el( 'div', { class: 'pst-pv__brand-label', text: 'Boutons' } ) );
		board.appendChild( el( 'div', { class: 'pst-pv__cta-row' }, [ btn( 'Bouton principal', true ), btn( 'Bouton secondaire', false ) ] ) );

		pv.appendChild( board );
		pv.appendChild( heroBlock( { title: 'Aperçu appliqué', subtitle: 'Vos choix de marque en situation.', height: 'medium', overlay: true, cta_primary_label: 'Voir les offres', cta_secondary_label: 'Déposer mon profil', search_placeholder: 'Rechercher…' } ) );
	}

	function buildSEO( pv ) {
		var g = state.global || {};
		var key = ( state[ activeSeo ] ? activeSeo : 'home' );
		var s = state[ key ] || {};
		var label = ( CFG.schema.sections[ key ] && CFG.schema.sections[ key ].label ) || 'Accueil';
		var siteName = g.site_name || 'Postelio';
		var title = s.seo_title || ( ( g.title_template || '%page% — Postelio' ).replace( '%page%', label ) );
		var desc = s.meta_description || g.default_description || '';
		var img = s.social_image || g.default_social_image || '';
		var url = ( CFG.frontUrl || 'https://exemple.fr/' ).replace( /\/$/, '' ) + ( SEO_PATHS[ key ] || '/' );

		var wrap = el( 'section', { class: 'pst-pv__seo' } );
		wrap.appendChild( el( 'p', { class: 'pst-pv__seo-label', text: 'Aperçu Google — ' + label } ) );
		wrap.appendChild( el( 'div', { class: 'pst-pv__serp' }, [
			el( 'div', { class: 'pst-pv__serp-url' }, [ document.createTextNode( siteName + ' ' ), el( 'small', { text: url } ) ] ),
			el( 'div', { class: 'pst-pv__serp-title', text: title } ),
			el( 'div', { class: 'pst-pv__serp-desc', text: desc || 'Ajoutez une meta description pour contrôler cet extrait.' } )
		] ) );
		wrap.appendChild( el( 'p', { class: 'pst-pv__seo-label', text: 'Aperçu réseau social (Open Graph)' } ) );
		var ogImg = el( 'div', { class: 'pst-pv__og-img' } );
		if ( img && /^https?:/.test( img ) ) { ogImg.style.backgroundImage = 'url(' + img + ')'; } else { ogImg.textContent = 'Image sociale'; }
		wrap.appendChild( el( 'div', { class: 'pst-pv__og' }, [ ogImg, el( 'div', { class: 'pst-pv__og-body' }, [
			el( 'div', { class: 'pst-pv__og-site', text: siteName } ),
			el( 'div', { class: 'pst-pv__og-title', text: s.social_title || title } ),
			el( 'div', { class: 'pst-pv__og-desc', text: s.social_description || desc || '' } )
		] ) ] ) );
		wrap.appendChild( el( 'p', { class: 'pst-ed-preview-hint', text: 'Ouvrez une page à gauche pour voir son aperçu. Aperçu éditorial — ne reflète ni la position ni l\'indexation réelle.' } ) );
		pv.appendChild( wrap );
	}

	function buildGeneric( pv ) {
		var v = state;
		pv.appendChild( heroBlock( { title: v.title || CFG.schema.label || 'Page', height: 'medium', overlay: true } ) );
		pv.appendChild( el( 'section', { class: 'pst-pv__section' }, [ el( 'div', { class: 'pst-pv__grid' }, [ phCard(), phCard(), phCard() ] ) ] ) );
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
			method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.restNonce }, credentials: 'same-origin',
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
				savebar( true, '⚠ Échec de l\'enregistrement', ( res.j && res.j.error && res.j.error.message ) || 'Vos modifications sont conservées. Réessayez.' );
			}
		} ).catch( function () {
			btnSave.removeAttribute( 'disabled' ); btnSave.textContent = 'Enregistrer';
			savebar( true, '⚠ Erreur réseau', 'Vos modifications sont conservées. Vérifiez votre connexion.' );
		} );
	}

	// ============================================================ INIT
	function init() {
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
