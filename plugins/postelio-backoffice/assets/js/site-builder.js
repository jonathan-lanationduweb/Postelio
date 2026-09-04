/**
 * Site Builder — moteur d'édition du back-office unifié (vanilla JS, aucun framework).
 * Piloté par le SCHÉMA injecté (window.PST_BO_SITE) : cartes de section (repliées compactes /
 * ouvertes généreuses), champs (texte, zone, select, nombre, toggle, couleur, média, repeater,
 * collection), champs conditionnels (show_if), rappel d'identité globale, aperçu = VRAI FRONT en
 * iframe (?postelio_preview=1 → postMessage → preview-ready, cible et appareil imposables par le
 * schéma), save bar. Toute chaîne utilisateur passe par textContent (jamais innerHTML).
 *
 * Mécanique d'aperçu strictement conservée du Site Builder Phase 2 (bridge front inchangé).
 */
( function () {
	'use strict';

	var CFG = window.PST_BO_SITE;
	if ( ! CFG || ! CFG.schema ) { return; }

	// ============================================================ ÉTAT
	var state = clone( CFG.values || {} );
	var dirty = false;
	var FORCED_DEVICE  = ( [ 'mobile', 'tablet', 'desktop' ].indexOf( CFG.schema.preview_device ) >= 0 ) ? CFG.schema.preview_device : null;
	var PREVIEW_TARGET = ( [ 'footer', 'header' ].indexOf( CFG.schema.preview_target ) >= 0 ) ? CFG.schema.preview_target : null;
	var device = FORCED_DEVICE || 'desktop';
	var previewTimer = null;
	var activeSeo = 'home';
	var resolveCache = {};  // collection : { type: { id: {label,sub,state,missing} } }
	var mediaMeta = {};     // média : { url: { size: 'x Ko', heavy: bool } } (poids connu après sélection)
	var deps = [];          // champs conditionnels : { node, path, equals }

	var SEO_PATHS = { home: '/', jobs: '/offres', companies: '/entreprises', skills: '/savoir-faire', advice: '/conseils', contact: '/contact' };
	var FRONT_ROUTES = { home: '/index.html', navigation: '/index.html', footer: '/index.html', appearance: '/index.html', jobs: '/offres.html', companies: '/entreprises.html', skills: '/savoir-faire.html', advice: '/blog.html', contact: '/contact.html' };

	// ============================================================ UTILITAIRES
	function clone( o ) { return JSON.parse( JSON.stringify( o == null ? {} : o ) ); }
	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( v == null ) { return; }
			if ( k === 'class' ) { n.className = v; }
			else if ( k === 'text' ) { n.textContent = v; }
			else if ( k.indexOf( 'on' ) === 0 && typeof v === 'function' ) { n.addEventListener( k.slice( 2 ), v ); }
			else { n.setAttribute( k, v ); }
		} );
		( kids || [] ).forEach( function ( c ) { if ( c != null ) { n.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c ); } } );
		return n;
	}
	function getPath( path ) { var o = state; for ( var i = 0; i < path.length; i++ ) { if ( o == null ) { return undefined; } o = o[ path[ i ] ]; } return o; }
	function setPath( path, val ) { var o = state; for ( var i = 0; i < path.length - 1; i++ ) { if ( o[ path[ i ] ] == null ) { o[ path[ i ] ] = ( typeof path[ i + 1 ] === 'number' ) ? [] : {}; } o = o[ path[ i ] ]; } o[ path[ path.length - 1 ] ] = val; }
	function basename( u ) { return String( u || '' ).split( '/' ).pop().split( '?' )[ 0 ]; }
	function mediaUrl( v ) {
		v = String( v || '' ).trim();
		if ( /^https?:\/\//i.test( v ) ) { return v; }
		if ( /^\//.test( v ) ) { return window.location.origin + v; }
		return '';
	}
	function adminPage( slug ) { return ( CFG.adminBase || window.location.pathname ) + '?page=' + encodeURIComponent( slug ); }
	function appearance() { return CFG.page === 'appearance' ? state : ( CFG.appearance || {} ); }
	function markDirty() { if ( ! dirty ) { dirty = true; savebar( true ); } schedulePreview(); }
	function schedulePreview() { clearTimeout( previewTimer ); previewTimer = setTimeout( renderPreview, 120 ); }
	function normalizeHex( v ) { return /^#[0-9a-fA-F]{6}$/.test( v ) ? v : '#000000'; }

	// ============================================================ DÉFAUTS (réinitialisation)
	function fieldDefault( f ) {
		if ( f.default !== undefined ) { return clone( f.default ); }
		if ( f.type === 'toggle' ) { return false; }
		if ( f.type === 'number' ) { return 0; }
		if ( f.type === 'repeater' || f.type === 'collection' ) { return []; }
		return '';
	}
	function schemaDefaults( schema ) {
		if ( schema.type === 'sections' ) {
			var out = { _order: Object.keys( schema.sections ) };
			Object.keys( schema.sections ).forEach( function ( sk ) {
				var sec = {}, fs = schema.sections[ sk ].fields || {};
				Object.keys( fs ).forEach( function ( fk ) { sec[ fk ] = fieldDefault( fs[ fk ] ); } );
				sec._enabled = schema.sections[ sk ].enabled_default !== false;
				out[ sk ] = sec;
			} );
			return out;
		}
		var o = {};
		Object.keys( schema.fields ).forEach( function ( fk ) { o[ fk ] = fieldDefault( schema.fields[ fk ] ); } );
		return o;
	}

	// ============================================================ ÉDITEUR (colonne gauche)
	function renderEditor() {
		var panel = document.getElementById( 'pst-bo-panel' );
		panel.textContent = '';
		deps = [];
		var schema = CFG.schema;

		if ( schema.backend_note ) { panel.appendChild( el( 'div', { class: 'sb-note', text: schema.backend_note } ) ); }
		if ( schema.source_note ) { panel.appendChild( el( 'div', { class: 'sb-note', text: schema.source_note } ) ); }
		if ( schema.resettable ) { panel.appendChild( resetControl() ); }

		if ( schema.type === 'sections' ) {
			sectionOrder().forEach( function ( skey ) { panel.appendChild( sectionCard( skey, schema.sections[ skey ] ) ); } );
		} else if ( schema.groups ) {
			schema.groups.forEach( function ( g, i ) {
				panel.appendChild( groupCard( g.label, i === 0, function ( body ) {
					if ( g.identity_hint ) { body.appendChild( identityHint() ); }
					g.fields.forEach( function ( fk ) { if ( schema.fields[ fk ] ) { body.appendChild( fieldRow( fk, schema.fields[ fk ], [ fk ] ) ); } } );
				} ) );
			} );
		} else {
			panel.appendChild( groupCard( 'Réglages', true, function ( body ) {
				Object.keys( schema.fields ).forEach( function ( fk ) { body.appendChild( fieldRow( fk, schema.fields[ fk ], [ fk ] ) ); } );
			} ) );
		}
		refreshDeps();
	}

	function sectionOrder() {
		var schema = CFG.schema;
		var order = ( state._order && state._order.length ) ? state._order.slice() : Object.keys( schema.sections );
		order = order.filter( function ( k ) { return schema.sections[ k ]; } );
		Object.keys( schema.sections ).forEach( function ( k ) { if ( order.indexOf( k ) < 0 ) { order.push( k ); } } );
		return order;
	}

	function resetControl() {
		return el( 'div', { class: 'sb-reset' }, [
			el( 'button', { type: 'button', class: 'bo-btn bo-btn--sm', text: '↺ Réinitialiser aux valeurs Postelio', onclick: function () {
				if ( window.confirm( 'Réinitialiser cette page aux valeurs Postelio par défaut ? Vos modifications non enregistrées seront perdues.' ) ) {
					state = schemaDefaults( CFG.schema ); markDirty(); renderEditor(); renderPreview();
				}
			} } )
		] );
	}

	/** Carte de groupe (pages « single ») : ouverte par défaut pour la première, repliable. */
	function groupCard( title, open, fill ) {
		var fields = el( 'div', { class: 'sb-fields' } );
		fill( fields );
		var card = el( 'div', { class: 'sb-card' + ( open ? ' is-open' : '' ) } );
		card.appendChild( el( 'div', { class: 'sb-card__head', onclick: function () { card.classList.toggle( 'is-open' ); } }, [
			el( 'div', { class: 'sb-card__titles' }, [ el( 'span', { class: 'sb-card__title', text: title } ) ] ),
			el( 'span', { class: 'sb-card__chevron', text: '▸' } )
		] ) );
		card.appendChild( el( 'div', { class: 'sb-card__body' }, [ fields ] ) );
		return card;
	}

	/** Résumé compact d'une section repliée (« 6 affichés », « 3 éléments », début du titre). */
	function sectionSummary( v, sdef ) {
		var fields = sdef.fields || {}, k;
		for ( k in fields ) {
			if ( fields[ k ].type === 'collection' ) {
				if ( v.mode === 'manual' && Array.isArray( v.items ) ) { return v.items.length + ' sélectionné(s)'; }
				return ( parseInt( v.count, 10 ) || 0 ) + ' affichés';
			}
		}
		for ( k in fields ) {
			if ( fields[ k ].type === 'repeater' ) { var n = Array.isArray( v[ k ] ) ? v[ k ].length : 0; return n + ' élément' + ( n > 1 ? 's' : '' ); }
		}
		if ( typeof v.title === 'string' && v.title ) { return v.title.length > 52 ? v.title.slice( 0, 50 ) + '…' : v.title; }
		return '';
	}

	/** Carte de section (pages « sections ») : tête compacte (grip/ordre, titre + résumé, switch, chevron). */
	function sectionCard( skey, sdef ) {
		var sval = state[ skey ] = state[ skey ] || {};
		var noToggle = !! sdef.no_toggle;
		var enabled = noToggle || sval._enabled !== false;

		var fields = el( 'div', { class: 'sb-fields' } );
		Object.keys( sdef.fields || {} ).forEach( function ( fk ) { fields.appendChild( fieldRow( fk, sdef.fields[ fk ], [ skey, fk ] ) ); } );

		var headKids = [];
		if ( sdef.reorderable !== false && ! CFG.schema.seo ) {
			headKids.push( el( 'div', { class: 'sb-card__reorder' }, [
				el( 'button', { type: 'button', title: 'Monter', 'aria-label': 'Monter la section', text: '▲', onclick: function ( e ) { e.stopPropagation(); moveSection( skey, -1 ); } } ),
				el( 'button', { type: 'button', title: 'Descendre', 'aria-label': 'Descendre la section', text: '▼', onclick: function ( e ) { e.stopPropagation(); moveSection( skey, 1 ); } } )
			] ) );
		} else {
			headKids.push( el( 'span', { class: 'sb-card__grip', text: CFG.schema.seo ? '' : '⋮⋮' } ) );
		}
		var summary = sectionSummary( sval, sdef );
		headKids.push( el( 'div', { class: 'sb-card__titles' }, [
			el( 'span', { class: 'sb-card__title', text: sdef.label || skey } ),
			summary ? el( 'span', { class: 'sb-card__summary', text: summary } ) : null
		] ) );
		if ( ! noToggle ) {
			headKids.push( stopClicks( switchEl( enabled, function ( on ) { sval._enabled = on; card.classList.toggle( 'is-off', ! on ); markDirty(); } ) ) );
		}
		headKids.push( el( 'span', { class: 'sb-card__chevron', text: '▸' } ) );

		var head = el( 'div', { class: 'sb-card__head', onclick: function () {
			var willOpen = ! card.classList.contains( 'is-open' );
			if ( CFG.schema.seo && willOpen ) {
				Array.prototype.forEach.call( document.querySelectorAll( '#pst-bo-panel .sb-card.is-open' ), function ( c ) { if ( c !== card ) { c.classList.remove( 'is-open' ); } } );
			}
			card.classList.toggle( 'is-open' );
			if ( CFG.schema.seo && card.classList.contains( 'is-open' ) && skey !== 'global' ) { activeSeo = skey; schedulePreview(); }
		} }, headKids );

		var card = el( 'div', { class: 'sb-card' + ( enabled ? '' : ' is-off' ) }, [ head, el( 'div', { class: 'sb-card__body' }, [ fields ] ) ] );
		return card;
	}

	function stopClicks( node ) { var w = el( 'span', {}, [ node ] ); w.addEventListener( 'click', function ( e ) { e.stopPropagation(); } ); return w; }

	function moveSection( skey, dir ) {
		var order = sectionOrder();
		var i = order.indexOf( skey ), j = i + dir;
		if ( i < 0 || j < 0 || j >= order.length ) { return; }
		order.splice( j, 0, order.splice( i, 1 )[ 0 ] );
		state._order = order; markDirty(); renderEditor(); renderPreview();
	}

	// Champs conditionnels (`show_if: { field, equals }`, relatif au même niveau).
	function refreshDeps() {
		deps.forEach( function ( d ) { var v = getPath( d.path ); d.node.hidden = ! ( ( v == null ? false : v ) === d.equals ); } );
	}

	// Rappel de l'identité globale (Apparence → Identité) dans les cartes « Marque ».
	function identityHint() {
		var a = appearance();
		var logo = a.logo ? mediaUrl( a.logo ) : '';
		var thumb = el( 'span', { class: 'sb-identity__thumb' + ( logo ? '' : ' is-empty' ), text: logo ? '' : 'P' } );
		if ( logo ) { var img = el( 'img', { alt: '' } ); img.src = logo; thumb.appendChild( img ); }
		return el( 'div', { class: 'sb-identity' }, [
			thumb,
			el( 'div', { class: 'sb-identity__main' }, [
				el( 'span', { class: 'sb-identity__label', text: 'Identité globale : ' + ( a.brand_name || 'Postelio' ) } ),
				el( 'span', { class: 'sb-identity__sub', text: logo ? basename( logo ) : 'Aucun logo global — pastille « P » par défaut' } )
			] ),
			el( 'a', { href: adminPage( 'postelio-site-appearance' ), text: 'Modifier dans Apparence → Identité' } )
		] );
	}

	// ------------------------------------------------------------ champs
	function fieldRow( fk, fdef, path ) {
		var node = buildField( fk, fdef, path );
		if ( node && fdef.col === 'half' ) { node.classList.add( 'sb-field--half' ); }
		if ( node && fdef.show_if && fdef.show_if.field ) {
			deps.push( { node: node, path: path.slice( 0, -1 ).concat( [ fdef.show_if.field ] ), equals: fdef.show_if.equals } );
		}
		return node;
	}

	function buildField( fk, fdef, path ) {
		var type = fdef.type || 'text';
		if ( type === 'repeater' ) { return wrapField( fdef, repeaterField( fdef, path ) ); }
		if ( type === 'collection' ) { return wrapField( fdef, collectionField( fdef, path ) ); }
		if ( type === 'toggle' ) { return toggleField( fdef, path ); }
		if ( type === 'color' ) { return colorField( fdef, path ); }
		if ( type === 'media' ) { return mediaField( fdef, path ); }

		var value = getPath( path ), control, counter = null;
		if ( type === 'textarea' ) {
			control = el( 'textarea', { class: 'sb-textarea', placeholder: fdef.placeholder || '' } );
			control.value = value || '';
			control.addEventListener( 'input', function () { setPath( path, control.value ); if ( counter ) { updateCounter( counter, control.value, fdef.counter ); } markDirty(); } );
		} else if ( type === 'select' ) {
			control = el( 'select', { class: 'sb-select' } );
			var opts = fdef.options || {};
			Object.keys( opts ).forEach( function ( ov ) { control.appendChild( el( 'option', { value: ov, text: opts[ ov ] } ) ); } );
			control.value = value;
			control.addEventListener( 'change', function () { setPath( path, control.value ); markDirty(); } );
		} else if ( type === 'number' ) {
			control = el( 'input', { class: 'sb-input', type: 'number', min: fdef.min, max: fdef.max } );
			control.value = value;
			control.addEventListener( 'input', function () { setPath( path, parseInt( control.value, 10 ) || 0 ); markDirty(); } );
		} else {
			control = el( 'input', { class: 'sb-input', type: 'text', placeholder: fdef.placeholder || '' } );
			control.value = value || '';
			control.addEventListener( 'input', function () { setPath( path, control.value ); if ( counter ) { updateCounter( counter, control.value, fdef.counter ); } markDirty(); } );
		}
		var wrap = wrapField( fdef, control );
		if ( fdef.counter ) { counter = el( 'div', { class: 'sb-count' } ); updateCounter( counter, value || '', fdef.counter ); wrap.appendChild( counter ); }
		return wrap;
	}

	function updateCounter( node, val, max ) { var n = ( val || '' ).length; node.textContent = n + ' / ' + max; node.classList.toggle( 'is-over', n > max ); }

	function wrapField( fdef, control ) {
		var kids = [ el( 'label', { class: 'sb-field__label', text: fdef.label || '' } ), control ];
		if ( fdef.help ) { kids.push( el( 'p', { class: 'sb-field__help', text: fdef.help } ) ); }
		return el( 'div', { class: 'sb-field' }, kids );
	}

	function toggleField( fdef, path ) {
		var sw = switchEl( !! getPath( path ), function ( on ) { setPath( path, on ); refreshDeps(); markDirty(); } );
		return el( 'div', { class: 'sb-field' }, [
			el( 'div', { class: 'sb-toggle' }, [ el( 'label', { class: 'sb-field__label', text: fdef.label || '' } ), sw ] ),
			fdef.help ? el( 'p', { class: 'sb-field__help', text: fdef.help } ) : null
		] );
	}

	function switchEl( checked, onChange ) {
		var input = el( 'input', { type: 'checkbox' } ); input.checked = !! checked;
		input.addEventListener( 'change', function () { onChange( input.checked ); } );
		return el( 'label', { class: 'sb-switch' }, [ input, el( 'span', { class: 'sb-switch__track' } ) ] );
	}

	function colorField( fdef, path ) {
		var value = getPath( path ) || fdef.default || '#000000';
		var picker = el( 'input', { type: 'color' } ); picker.value = normalizeHex( value );
		var hex = el( 'input', { class: 'sb-input', type: 'text' } ); hex.value = value;
		picker.addEventListener( 'input', function () { hex.value = picker.value; setPath( path, picker.value ); markDirty(); } );
		hex.addEventListener( 'input', function () { if ( /^#[0-9a-fA-F]{6}$/.test( hex.value ) ) { picker.value = hex.value; } setPath( path, hex.value ); markDirty(); } );
		return el( 'div', { class: 'sb-field' }, [ el( 'label', { class: 'sb-field__label', text: fdef.label || '' } ), el( 'div', { class: 'sb-color' }, [ picker, hex ] ) ] );
	}

	/** Média : vignette réelle + nom + poids + Choisir/Remplacer · Retirer · Restaurer la valeur par défaut. */
	function mediaField( fdef, path ) {
		var isVideo = fdef.media_type === 'video';
		var kind = fdef.preview === 'icon' ? 'icon' : ( fdef.preview === 'contain' ? 'contain' : 'cover' );
		var thumb = el( 'div', { class: 'sb-media__thumb' + ( isVideo ? ' is-video' : '' ) + ( kind !== 'cover' ? ' is-' + kind : '' ) } );
		var name = el( 'div', { class: 'sb-media__name' } );
		var meta = el( 'div', { class: 'sb-media__meta' } );
		var status = el( 'p', { class: 'sb-field__help' } );
		var hasDefault = !! fdef.default;

		var choose = el( 'button', { class: 'bo-btn bo-btn--sm', type: 'button', onclick: function () { openMedia( path, fdef, paint, status ); } } );
		var remove = el( 'button', { class: 'bo-btn bo-btn--sm bo-btn--ghost', type: 'button', text: 'Retirer', onclick: function () { setPath( path, '' ); clearStatus(); paint(); markDirty(); } } );
		var reset = el( 'button', { class: 'bo-btn bo-btn--sm bo-btn--ghost', type: 'button', text: 'Restaurer la valeur par défaut', onclick: function () { setPath( path, fdef.default || '' ); clearStatus(); paint(); markDirty(); } } );
		function clearStatus() { status.textContent = ''; status.className = 'sb-field__help'; }

		function paint() {
			var v = getPath( path ), url = mediaUrl( v );
			thumb.style.backgroundImage = 'none';
			thumb.textContent = '';
			if ( isVideo ) {
				thumb.textContent = v ? '🎬 Vidéo' : 'Vidéo par défaut du site';
				name.textContent = v ? basename( v ) : 'Vidéo par défaut du site';
			} else if ( url && kind === 'icon' ) {
				var big = el( 'img', { alt: '' } ); big.src = url;
				var small = el( 'img', { alt: '' } ); small.src = url;
				thumb.appendChild( big ); thumb.appendChild( small );
				name.textContent = basename( url );
			} else if ( url && kind === 'contain' ) {
				var img = el( 'img', { alt: '' } ); img.src = url; thumb.appendChild( img );
				name.textContent = basename( url );
			} else if ( url ) {
				thumb.style.backgroundImage = 'url(' + url + ')';
				name.textContent = basename( url );
			} else {
				thumb.textContent = v ? 'Média #' + v : 'Aucun média';
				name.textContent = v ? 'Média #' + v : ( hasDefault ? 'Aucun média (défaut retiré)' : 'Aucun média' );
			}
			var m = mediaMeta[ url ];
			meta.textContent = ( hasDefault && v === fdef.default ) ? 'Valeur Postelio par défaut' : ( m ? m.size + ( m.heavy ? ' — fichier lourd, pensez à compresser' : '' ) : ( url ? 'Média de la bibliothèque' : '' ) );
			choose.textContent = v ? 'Remplacer' : ( isVideo ? 'Choisir une vidéo' : 'Choisir un média' );
			reset.hidden = ! hasDefault || v === fdef.default;
			remove.hidden = ! v;
		}
		paint();

		var info = el( 'div', { class: 'sb-media__info' }, [ name, meta, el( 'div', { class: 'sb-media__actions' }, [ choose, remove, reset ] ) ] );
		return el( 'div', { class: 'sb-field' }, [ el( 'label', { class: 'sb-field__label', text: fdef.label || '' } ), el( 'div', { class: 'sb-media' }, [ thumb, info ] ), fdef.help ? el( 'p', { class: 'sb-field__help', text: fdef.help } ) : null, status ] );
	}

	function openMedia( path, fdef, paint, status ) {
		if ( ! window.wp || ! window.wp.media ) { window.alert( 'Médiathèque WordPress indisponible.' ); return; }
		var opts = { title: fdef.media_type === 'video' ? 'Choisir une vidéo' : 'Choisir un média', multiple: false };
		if ( fdef.media_type ) { opts.library = { type: fdef.media_type }; }
		var frame = window.wp.media( opts );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var ext = ( att.url || '' ).split( '.' ).pop().toLowerCase().split( '?' )[ 0 ];
			if ( Array.isArray( fdef.accept ) && fdef.accept.indexOf( ext ) < 0 ) {
				status.className = 'sb-field__help is-warn';
				status.textContent = 'Format non pris en charge (' + ext + '). Attendu : ' + fdef.accept.join( ', ' ) + '.';
				return;
			}
			setPath( path, att.url );
			if ( att.filesizeHumanReadable ) {
				mediaMeta[ att.url ] = { size: att.filesizeHumanReadable, heavy: !! ( att.filesizeInBytes && att.filesizeInBytes > 15 * 1024 * 1024 ) };
			}
			status.textContent = ''; status.className = 'sb-field__help';
			paint(); markDirty();
		} );
		frame.open();
	}

	// ------------------------------------------------------------ repeater
	function repeaterField( fdef, path ) {
		var rows = getPath( path );
		if ( ! Array.isArray( rows ) ) { rows = []; setPath( path, rows ); }
		var wrap = el( 'div', { class: 'sb-rep' } );
		var openIndex = -1;
		var keys = Object.keys( fdef.fields );

		function rebuild() {
			wrap.textContent = '';
			rows.forEach( function ( row, i ) { wrap.appendChild( repRow( i ) ); } );
			wrap.appendChild( el( 'button', { class: 'sb-rep__add', type: 'button', text: '+ Ajouter', onclick: function () {
				var blank = {}; keys.forEach( function ( sk ) { blank[ sk ] = ''; } );
				rows.push( blank ); openIndex = rows.length - 1; markDirty(); rebuild();
			} } ) );
			openIndex = -1;
		}
		function repRow( i ) {
			var row = rows[ i ] || {};
			// Ligne fermée : 1er champ = titre ; champs suivants = résumé « /offres · Tout le monde »
			// (les selects affichent leur libellé, les toggles « Oui / Non », une zone → 1re ligne).
			var title = ( keys[ 0 ] && row[ keys[ 0 ] ] ) ? String( row[ keys[ 0 ] ] ) : ( ( fdef.label || 'Élément' ) + ' ' + ( i + 1 ) );
			var sub = keys.slice( 1 ).map( function ( sk ) {
				var def = fdef.fields[ sk ] || {}, v = row[ sk ];
				if ( v === '' || v == null ) { return ''; }
				if ( def.type === 'select' ) { return ( def.options && def.options[ v ] ) || String( v ); }
				if ( def.type === 'toggle' ) { return ( def.label || sk ) + ' : ' + ( v ? 'Oui' : 'Non' ); }
				if ( def.type === 'media' ) { return basename( v ); }
				return String( v ).split( '\n' )[ 0 ];
			} ).filter( Boolean ).join( ' · ' );
			var fieldsWrap = el( 'div', { class: 'sb-fields' } );
			keys.forEach( function ( sk ) { fieldsWrap.appendChild( fieldRow( sk, fdef.fields[ sk ], path.concat( [ i, sk ] ) ) ); } );
			function move( to ) { rows.splice( to, 0, rows.splice( i, 1 )[ 0 ] ); markDirty(); rebuild(); }
			var tools = el( 'div', { class: 'sb-rep__tools' }, [
				el( 'button', { type: 'button', title: 'Monter', 'aria-label': 'Monter', text: '▲', onclick: function ( e ) { e.stopPropagation(); if ( i > 0 ) { move( i - 1 ); } } } ),
				el( 'button', { type: 'button', title: 'Descendre', 'aria-label': 'Descendre', text: '▼', onclick: function ( e ) { e.stopPropagation(); if ( i < rows.length - 1 ) { move( i + 1 ); } } } ),
				el( 'button', { type: 'button', title: 'Supprimer', 'aria-label': 'Supprimer', text: '✕', onclick: function ( e ) { e.stopPropagation(); rows.splice( i, 1 ); markDirty(); rebuild(); } } )
			] );
			var card = el( 'div', { class: 'sb-rep__row' + ( i === openIndex ? ' is-open' : '' ) }, [
				el( 'div', { class: 'sb-rep__head', onclick: function ( e ) { if ( e.target.closest( 'button' ) ) { return; } card.classList.toggle( 'is-open' ); } }, [
					el( 'span', { class: 'sb-rep__index', text: String( i + 1 ) } ),
					el( 'div', { class: 'sb-rep__main' }, [ el( 'span', { class: 'sb-rep__title', text: title } ), sub ? el( 'span', { class: 'sb-rep__sub', text: sub } ) : null ] ),
					tools,
					el( 'span', { class: 'sb-rep__chevron', text: '▸' } )
				] ),
				el( 'div', { class: 'sb-rep__fields' }, [ fieldsWrap ] )
			] );
			return card;
		}
		rebuild();
		return wrap;
	}

	// ------------------------------------------------------------ collection (sélecteur de contenu)
	function collectionField( fdef, path ) {
		var type = fdef.ref_type || 'job';
		var ids = getPath( path );
		if ( ! Array.isArray( ids ) ) { ids = []; setPath( path, ids ); }
		resolveCache[ type ] = resolveCache[ type ] || {};
		var list = el( 'div', { class: 'sb-coll__list' } );
		var wrap = el( 'div', { class: 'sb-coll' }, [ list, searchBox() ] );

		function render() {
			list.textContent = '';
			if ( ! ids.length ) { list.appendChild( el( 'p', { class: 'sb-coll__empty', text: 'Aucune sélection. Recherchez du contenu ci-dessous.' } ) ); return; }
			ids.forEach( function ( id, i ) {
				var item = resolveCache[ type ][ id ], missing = item && item.missing;
				list.appendChild( el( 'div', { class: 'sb-coll__item' + ( missing ? ' is-missing' : '' ) }, [
					el( 'div', { class: 'sb-coll__thumb', text: missing ? '!' : ( item && item.label ? item.label.trim().charAt( 0 ).toUpperCase() : '…' ) } ),
					el( 'div', { class: 'sb-coll__main' }, [
						el( 'div', { class: 'sb-coll__label', text: item ? ( missing ? 'Contenu indisponible' : ( item.label || id ) ) : 'Chargement…' } ),
						el( 'div', { class: 'sb-coll__sub', text: item && ! missing ? ( item.sub || '' ) : ( 'réf. ' + id ) } )
					] ),
					( item && ! missing && item.state ) ? el( 'span', { class: 'sb-coll__state', text: item.state } ) : null,
					el( 'div', { class: 'sb-coll__tools' }, [
						el( 'button', { type: 'button', title: 'Monter', 'aria-label': 'Monter', text: '▲', onclick: function () { if ( i > 0 ) { ids.splice( i - 1, 0, ids.splice( i, 1 )[ 0 ] ); markDirty(); render(); } } } ),
						el( 'button', { type: 'button', title: 'Descendre', 'aria-label': 'Descendre', text: '▼', onclick: function () { if ( i < ids.length - 1 ) { ids.splice( i + 1, 0, ids.splice( i, 1 )[ 0 ] ); markDirty(); render(); } } } ),
						el( 'button', { type: 'button', title: 'Retirer', 'aria-label': 'Retirer', text: '✕', onclick: function () { ids.splice( i, 1 ); markDirty(); render(); } } )
					] )
				] ) );
			} );
		}
		function searchBox() {
			var input = el( 'input', { class: 'sb-input', type: 'search', placeholder: 'Rechercher à ajouter…' } );
			var results = el( 'div', { class: 'sb-coll__results' } );
			var t = null;
			input.addEventListener( 'input', function () {
				clearTimeout( t );
				var q = input.value.trim();
				if ( q.length < 2 ) { results.classList.remove( 'is-open' ); return; }
				t = setTimeout( function () { doSearch( q, results, input ); }, 250 );
			} );
			input.addEventListener( 'blur', function () { setTimeout( function () { results.classList.remove( 'is-open' ); }, 180 ); } );
			return el( 'div', { class: 'sb-coll__search' }, [ input, results ] );
		}
		function doSearch( q, results, input ) {
			fetch( CFG.searchUrl + '?type=' + encodeURIComponent( type ) + '&q=' + encodeURIComponent( q ), { headers: { 'X-WP-Nonce': CFG.restNonce }, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				var items = ( j && j.data && j.data.items ) || [];
				results.textContent = '';
				if ( ! items.length ) { results.appendChild( el( 'div', { class: 'sb-coll__empty', text: 'Aucun résultat.' } ) ); results.classList.add( 'is-open' ); return; }
				items.forEach( function ( it ) {
					var already = ids.indexOf( it.id ) >= 0;
					results.appendChild( el( 'div', { class: 'sb-coll__result', onclick: function () {
						if ( ! already && it.id ) {
							resolveCache[ type ][ it.id ] = { label: it.label, sub: it.sub, state: it.state, missing: false };
							ids.push( it.id ); markDirty(); render(); input.value = ''; results.classList.remove( 'is-open' );
						}
					} }, [ el( 'b', { text: it.label + ( already ? '  ✓' : '' ) } ), el( 'span', { text: [ it.sub, it.state ].filter( Boolean ).join( ' · ' ) } ) ] ) );
				} );
				results.classList.add( 'is-open' );
			} ).catch( function () { results.textContent = ''; results.appendChild( el( 'div', { class: 'sb-coll__empty', text: 'Recherche indisponible.' } ) ); results.classList.add( 'is-open' ); } );
		}
		render();
		var unknown = ids.filter( function ( id ) { return ! resolveCache[ type ][ id ]; } );
		if ( unknown.length ) {
			fetch( CFG.resolveUrl + '?type=' + encodeURIComponent( type ) + '&ids=' + encodeURIComponent( unknown.join( ',' ) ), { headers: { 'X-WP-Nonce': CFG.restNonce }, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) { ( ( j && j.data && j.data.items ) || [] ).forEach( function ( it ) { resolveCache[ type ][ it.id ] = it; } ); render(); } )
			.catch( function () {} );
		}
		return wrap;
	}

	// ============================================================ APERÇU (VRAI FRONT en iframe)
	var iframeEl = null, previewReady = false, savedAll = null, frameTimeout = null;

	// `v` = version de l'admin : force un document d'aperçu frais à chaque mise à jour (le bridge du
	// front ignore ce paramètre ; sans lui, un index.html en cache garderait un vieux bridge).
	function frontUrl( page ) { return window.location.origin + ( FRONT_ROUTES[ page ] || '/index.html' ) + '?postelio_preview=1&v=' + encodeURIComponent( CFG.version || '1' ); }

	function renderPreview() {
		var canvas = document.getElementById( 'pst-bo-canvas' );
		canvas.className = 'sb-canvas' + ( device === 'tablet' ? ' is-tablet' : device === 'mobile' ? ' is-mobile' : '' );
		if ( CFG.page === 'seo' ) {
			canvas.classList.add( 'sb-canvas--doc' );
			canvas.textContent = '';
			canvas.appendChild( buildSEO() );
			iframeEl = null;
			return;
		}
		ensureFrame();
		postToPreview();
	}

	function ensureFrame() {
		var canvas = document.getElementById( 'pst-bo-canvas' );
		if ( iframeEl && iframeEl.getAttribute( 'data-page' ) === CFG.page ) { return; }
		canvas.textContent = '';
		previewReady = false;
		clearTimeout( frameTimeout );

		iframeEl = document.createElement( 'iframe' );
		iframeEl.className = 'sb-frame';
		iframeEl.setAttribute( 'title', 'Aperçu du site Postelio' );
		iframeEl.setAttribute( 'data-page', CFG.page );
		iframeEl.addEventListener( 'load', function () { setTimeout( postToPreview, 120 ); } );
		iframeEl.addEventListener( 'error', frameError );
		iframeEl.src = frontUrl( CFG.page );

		frameTimeout = setTimeout( function () { if ( ! previewReady ) { frameError(); } }, 9000 );
		canvas.appendChild( iframeEl );
		canvas.appendChild( el( 'div', { class: 'sb-frame__state', id: 'pst-bo-frame-state' }, [ el( 'span', { class: 'sb-spin' } ), el( 'span', { text: 'Chargement de l’aperçu…' } ) ] ) );
	}

	function frameError() {
		var s = document.getElementById( 'pst-bo-frame-state' );
		if ( ! s ) { return; }
		s.className = 'sb-frame__state is-error';
		s.textContent = '';
		s.appendChild( el( 'span', { text: 'Impossible de charger l’aperçu du site.' } ) );
		s.appendChild( el( 'button', { class: 'bo-btn bo-btn--sm', type: 'button', text: 'Réessayer', onclick: function () { iframeEl = null; renderPreview(); } } ) );
	}

	/** Config ENREGISTRÉE des autres pages + état LOCAL de la page courante (les modifications non enregistrées priment). */
	function postToPreview() {
		if ( ! iframeEl || ! iframeEl.contentWindow ) { return; }
		var merged = {};
		if ( savedAll && typeof savedAll === 'object' ) { Object.keys( savedAll ).forEach( function ( k ) { merged[ k ] = savedAll[ k ]; } ); }
		merged[ CFG.page ] = state;
		try {
			iframeEl.contentWindow.postMessage( { type: 'postelio-site-preview', page: CFG.page, config: merged, target: PREVIEW_TARGET }, window.location.origin );
		} catch ( e ) {}
	}

	function wirePreview() {
		window.addEventListener( 'message', function ( e ) {
			if ( e.origin !== window.location.origin || ! e.data ) { return; }
			if ( e.data.type === 'postelio-preview-ready' ) {
				previewReady = true;
				clearTimeout( frameTimeout );
				var s = document.getElementById( 'pst-bo-frame-state' );
				if ( s ) { s.hidden = true; }
				postToPreview();
			}
		} );
		if ( CFG.configUrl ) {
			fetch( CFG.configUrl, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) { savedAll = ( j && j.data && j.data.pages ) || {}; postToPreview(); } )
			.catch( function () { savedAll = {}; } );
		}
	}

	function buildSEO() {
		var g = state.global || {};
		var key = state[ activeSeo ] ? activeSeo : 'home';
		var s = state[ key ] || {};
		var label = ( CFG.schema.sections[ key ] && CFG.schema.sections[ key ].label ) || 'Accueil';
		var siteName = g.site_name || 'Postelio';
		var title = s.seo_title || ( ( g.title_template || '%page% — Postelio' ).replace( '%page%', label ) );
		var desc = s.meta_description || g.default_description || '';
		var img = s.social_image || g.default_social_image || '';
		var url = ( CFG.frontUrl || 'https://exemple.fr/' ).replace( /\/$/, '' ) + ( SEO_PATHS[ key ] || '/' );
		var ogImg = el( 'div', { class: 'sb-og__img' } );
		if ( mediaUrl( img ) ) { ogImg.style.backgroundImage = 'url(' + mediaUrl( img ) + ')'; } else { ogImg.textContent = 'Image sociale'; }
		return el( 'section', { class: 'sb-seo' }, [
			el( 'p', { class: 'sb-seo__label', text: 'Aperçu Google — ' + label } ),
			el( 'div', { class: 'sb-serp' }, [
				el( 'div', { class: 'sb-serp__url' }, [ document.createTextNode( siteName + ' ' ), el( 'small', { text: url } ) ] ),
				el( 'div', { class: 'sb-serp__title', text: title } ),
				el( 'div', { class: 'sb-serp__desc', text: desc || 'Ajoutez une meta description pour contrôler cet extrait.' } )
			] ),
			el( 'p', { class: 'sb-seo__label', text: 'Aperçu réseau social (Open Graph)' } ),
			el( 'div', { class: 'sb-og' }, [ ogImg, el( 'div', { class: 'sb-og__body' }, [
				el( 'div', { class: 'sb-og__site', text: siteName } ),
				el( 'div', { class: 'sb-og__title', text: s.social_title || title } ),
				el( 'div', { class: 'sb-og__desc', text: s.social_description || desc || '' } )
			] ) ] ),
			el( 'p', { class: 'sb-preview__hint', text: 'Ouvrez une page à gauche pour voir son aperçu. Aperçu éditorial — ne reflète ni la position ni l’indexation réelle.' } )
		] );
	}

	// ============================================================ SAVE BAR
	function savebar( show, msg, sub ) {
		var bar = document.getElementById( 'pst-bo-savebar' );
		if ( ! bar ) { return; }
		bar.classList.toggle( 'is-visible', !! show );
		var m = bar.querySelector( '.sb-savebar__msg' );
		if ( m ) { m.textContent = ''; m.appendChild( document.createTextNode( msg || 'Modifications non enregistrées' ) ); if ( sub ) { m.appendChild( el( 'small', { text: sub } ) ); } }
	}

	function save() {
		var buttons = [ document.getElementById( 'pst-bo-save' ), document.getElementById( 'pst-bo-savebar-save' ) ].filter( Boolean );
		buttons.forEach( function ( b ) { b.setAttribute( 'disabled', 'disabled' ); b.textContent = 'Enregistrement…'; } );
		function done() { buttons.forEach( function ( b ) { b.removeAttribute( 'disabled' ); b.textContent = 'Enregistrer'; } ); }
		fetch( CFG.saveUrl, {
			method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.restNonce }, credentials: 'same-origin',
			body: JSON.stringify( { values: state } )
		} ).then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, j: j }; } ); } )
		.then( function ( res ) {
			done();
			if ( res.ok && res.j && res.j.data ) {
				state = clone( res.j.data.values || state );
				CFG.values = clone( state );
				dirty = false; savebar( true, '✓ Modifications enregistrées' );
				renderEditor(); renderPreview();
				setTimeout( function () { if ( ! dirty ) { savebar( false ); } }, 2200 );
			} else {
				savebar( true, '⚠ Échec de l’enregistrement', ( res.j && res.j.error && res.j.error.message ) || 'Vos modifications sont conservées. Réessayez.' );
			}
		} ).catch( function () {
			done();
			savebar( true, '⚠ Erreur réseau', 'Vos modifications sont conservées. Vérifiez votre connexion.' );
		} );
	}

	// ============================================================ INIT
	function init() {
		var devs = document.getElementById( 'pst-bo-devices' );
		if ( FORCED_DEVICE ) {
			if ( devs ) { devs.hidden = true; }
			var lbl = document.getElementById( 'pst-bo-pvlabel' );
			if ( lbl ) { lbl.textContent = FORCED_DEVICE === 'mobile' ? 'Aperçu mobile' : ( FORCED_DEVICE === 'tablet' ? 'Aperçu tablette' : 'Aperçu desktop' ); }
		} else if ( devs ) {
			Array.prototype.forEach.call( devs.querySelectorAll( 'button' ), function ( b ) {
				b.addEventListener( 'click', function () {
					device = b.getAttribute( 'data-device' );
					Array.prototype.forEach.call( devs.querySelectorAll( 'button' ), function ( x ) { x.classList.toggle( 'is-active', x === b ); } );
					renderPreview();
				} );
			} );
		}
		if ( CFG.page === 'seo' && devs ) { devs.hidden = true; }

		var s1 = document.getElementById( 'pst-bo-save' ); if ( s1 ) { s1.addEventListener( 'click', save ); }
		var s2 = document.getElementById( 'pst-bo-savebar-save' ); if ( s2 ) { s2.addEventListener( 'click', save ); }
		var c = document.getElementById( 'pst-bo-cancel' ); if ( c ) { c.addEventListener( 'click', function () { state = clone( CFG.values || {} ); dirty = false; savebar( false ); renderEditor(); renderPreview(); } ); }
		window.addEventListener( 'beforeunload', function ( e ) { if ( dirty ) { e.preventDefault(); e.returnValue = ''; } } );
		var open = document.getElementById( 'pst-bo-pvopen' ); if ( open ) { open.href = window.location.origin + ( FRONT_ROUTES[ CFG.page ] || '/index.html' ); }

		wirePreview();
		renderEditor();
		renderPreview();
	}

	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', init ); } else { init(); }
} )();
