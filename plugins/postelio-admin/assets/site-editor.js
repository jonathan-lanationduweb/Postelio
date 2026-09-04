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
	// Certaines pages imposent un appareil et une cible d'aperçu (ex. Footer : mobile + footer réel).
	// Ces indications viennent du SCHÉMA (source de vérité), donc survivent au rechargement.
	var FORCED_DEVICE  = ( CFG.schema.preview_device === 'mobile' || CFG.schema.preview_device === 'tablet' || CFG.schema.preview_device === 'desktop' ) ? CFG.schema.preview_device : null;
	var PREVIEW_TARGET = ( CFG.schema.preview_target === 'footer' || CFG.schema.preview_target === 'header' ) ? CFG.schema.preview_target : null;
	var device = FORCED_DEVICE || 'desktop';
	var deps = []; // champs conditionnels (show_if) : { node, path, equals }
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
		deps = [];
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
					if ( g.identity_hint ) { body.appendChild( identityHint() ); }
					g.fields.forEach( function ( fk ) { if ( schema.fields[ fk ] ) { body.appendChild( fieldRow( fk, schema.fields[ fk ], [ fk ] ) ); } } );
				} ) );
			} );
		} else {
			panel.appendChild( plainCard( 'Réglages', function ( body ) {
				Object.keys( schema.fields ).forEach( function ( fk ) { body.appendChild( fieldRow( fk, schema.fields[ fk ], [ fk ] ) ); } );
			} ) );
		}
		refreshDeps();
	}

	// Champs conditionnels (`show_if: { field, equals }`, relatif au même niveau que le champ).
	function refreshDeps() {
		deps.forEach( function ( d ) {
			var v = getPath( d.path );
			d.node.hidden = ! ( ( v == null ? false : v ) === d.equals );
		} );
	}

	// Rappel de l'identité GLOBALE (Apparence → Identité) dans les cartes « Marque » de Navigation /
	// Footer : le logo global réellement utilisé quand « Utiliser le logo global » est activé.
	function identityHint() {
		var a = appearance();
		var logo = a.logo ? mediaUrl( a.logo ) : '';
		var thumb = el( 'span', { class: 'pst-ed-identity__thumb' + ( logo ? '' : ' is-empty' ), text: logo ? '' : 'P' } );
		if ( logo ) { var img = el( 'img', { alt: '' } ); img.src = logo; thumb.appendChild( img ); }
		var link = el( 'a', { href: adminPageUrl( 'postelio-site-appearance' ), text: 'Modifier dans Apparence → Identité' } );
		return el( 'div', { class: 'pst-ed-identity' }, [
			thumb,
			el( 'div', { class: 'pst-ed-identity__main' }, [
				el( 'span', { class: 'pst-ed-identity__label', text: 'Identité globale : ' + ( a.brand_name || 'Postelio' ) } ),
				el( 'span', { class: 'pst-ed-identity__sub', text: logo ? basename( logo ) : 'Aucun logo global — pastille « P » par défaut' } )
			] ),
			link
		] );
	}
	function adminPageUrl( slug ) { return window.location.pathname + '?page=' + encodeURIComponent( slug ); }
	function basename( u ) { return String( u || '' ).split( '/' ).pop().split( '?' )[ 0 ]; }
	// URL affichable d'une valeur média : absolue, ou chemin racine du front (même origine que wp-admin).
	function mediaUrl( v ) {
		v = String( v || '' ).trim();
		if ( /^https?:\/\//i.test( v ) ) { return v; }
		if ( /^\//.test( v ) ) { return window.location.origin + v; }
		return '';
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
		var fields = el( 'div', { class: 'pst-ed-fields' } );
		fill( fields );
		var body = el( 'div', { class: 'pst-ed-card__body' }, [ fields ] );
		var card = el( 'div', { class: 'pst-ed-card is-open' }, [
			el( 'div', { class: 'pst-ed-card__head', onclick: function () { card.classList.toggle( 'is-open' ); } }, [
				el( 'div', { class: 'pst-ed-card__titles' }, [ el( 'span', { class: 'pst-ed-card__title', text: title } ) ] ),
				el( 'span', { class: 'pst-ed-card__chevron', text: '▸' } )
			] ),
			body
		] );
		return card;
	}

	// Résumé compact affiché quand une section est fermée (ex. « 6 affichés », « 3 liens »).
	function sectionSummary( skey, v, sdef ) {
		var fields = sdef.fields || {};
		var k;
		for ( k in fields ) {
			if ( fields[ k ].type === 'collection' ) {
				if ( v.mode === 'manual' && Array.isArray( v.items ) ) { return v.items.length + ' sélectionné(s)'; }
				return ( parseInt( v.count, 10 ) || 0 ) + ' affichés';
			}
		}
		for ( k in fields ) {
			if ( fields[ k ].type === 'repeater' ) {
				var n = Array.isArray( v[ k ] ) ? v[ k ].length : 0;
				return n + ' élément' + ( n > 1 ? 's' : '' );
			}
		}
		if ( typeof v.title === 'string' && v.title ) { return v.title.length > 48 ? v.title.slice( 0, 46 ) + '…' : v.title; }
		return '';
	}

	function sectionCard( skey, sdef ) {
		var sval = state[ skey ] = state[ skey ] || {};
		var noToggle = !! sdef.no_toggle;
		var enabled = noToggle || sval._enabled !== false;

		var fields = el( 'div', { class: 'pst-ed-fields' } );
		Object.keys( sdef.fields || {} ).forEach( function ( fk ) { fields.appendChild( fieldRow( fk, sdef.fields[ fk ], [ skey, fk ] ) ); } );
		var body = el( 'div', { class: 'pst-ed-card__body' }, [ fields ] );

		var headKids = [];
		if ( CFG.schema.type === 'sections' && sdef.reorderable !== false && ! CFG.schema.seo ) {
			headKids.push( el( 'div', { class: 'pst-ed-card__reorder' }, [
				el( 'button', { title: 'Monter', text: '▲', 'aria-label': 'Monter la section', onclick: function ( e ) { e.stopPropagation(); moveSection( skey, -1 ); } } ),
				el( 'button', { title: 'Descendre', text: '▼', 'aria-label': 'Descendre la section', onclick: function ( e ) { e.stopPropagation(); moveSection( skey, 1 ); } } )
			] ) );
		} else {
			headKids.push( el( 'span', { class: 'pst-ed-card__grip', text: CFG.schema.seo ? '' : '⋮⋮' } ) );
		}
		var summary = sectionSummary( skey, sval, sdef );
		headKids.push( el( 'div', { class: 'pst-ed-card__titles' }, [
			el( 'span', { class: 'pst-ed-card__title', text: sdef.label || skey } ),
			summary ? el( 'span', { class: 'pst-ed-card__sub', text: summary } ) : null
		] ) );
		if ( ! noToggle ) {
			headKids.push( labelWrap( switchEl( enabled, function ( on ) { sval._enabled = on; card.classList.toggle( 'is-off', ! on ); markDirty(); } ) ) );
		}
		headKids.push( el( 'span', { class: 'pst-ed-card__chevron', text: '▸' } ) );

		var head = el( 'div', { class: 'pst-ed-card__head', onclick: function () {
			var willOpen = ! card.classList.contains( 'is-open' );
			if ( CFG.schema.seo && willOpen ) { // SEO = accordéon : une seule page ouverte à la fois.
				Array.prototype.forEach.call( document.querySelectorAll( '#pst-ed-panel .pst-ed-card.is-open' ), function ( c ) { if ( c !== card ) { c.classList.remove( 'is-open' ); } } );
			}
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
		var e = fieldRowBuild( fk, fdef, path );
		if ( e && e.classList && fdef.col === 'half' ) { e.classList.add( 'pst-ed-field--half' ); }
		if ( e && fdef.show_if && fdef.show_if.field ) {
			deps.push( { node: e, path: path.slice( 0, -1 ).concat( [ fdef.show_if.field ] ), equals: fdef.show_if.equals } );
		}
		return e;
	}
	function fieldRowBuild( fk, fdef, path ) {
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
		var sw = switchEl( value, function ( on ) { setPath( path, on ); refreshDeps(); markDirty(); } );
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
		var isVideo = fdef.media_type === 'video';
		var kind = fdef.preview === 'icon' ? 'icon' : ( fdef.preview === 'contain' ? 'contain' : 'cover' );
		var preview = el( 'div', { class: 'pst-ed-media__preview' + ( isVideo ? ' is-video' : '' ) + ( kind !== 'cover' ? ' is-' + kind : '' ) } );
		var status = el( 'p', { class: 'pst-ed-field__help' } );
		var hasDefault = !! fdef.default;
		function paint() {
			var v = getPath( path );
			var url = mediaUrl( v );
			preview.style.backgroundImage = 'none';
			preview.textContent = '';
			if ( isVideo ) {
				preview.textContent = v ? ( '🎬 ' + basename( v ) ) : 'Vidéo par défaut du site';
			} else if ( url && kind === 'icon' ) {
				// Vraie prévisualisation du favicon (taille réelle d'onglet + agrandie) + nom du fichier.
				var small = el( 'img', { class: 'pst-ed-media__icon pst-ed-media__icon--16', alt: '' } ); small.src = url;
				var big = el( 'img', { class: 'pst-ed-media__icon pst-ed-media__icon--32', alt: '' } ); big.src = url;
				preview.appendChild( el( 'span', { class: 'pst-ed-media__iconrow' }, [ small, big ] ) );
				preview.appendChild( el( 'span', { class: 'pst-ed-media__name', text: basename( url ) + ( hasDefault && v === fdef.default ? ' · favicon Postelio par défaut' : '' ) } ) );
			} else if ( url && kind === 'contain' ) {
				var img = el( 'img', { class: 'pst-ed-media__img', alt: '' } ); img.src = url;
				preview.appendChild( img );
				preview.appendChild( el( 'span', { class: 'pst-ed-media__name', text: basename( url ) } ) );
			} else if ( url ) {
				preview.style.backgroundImage = 'url(' + url + ')';
			} else {
				preview.textContent = v ? ( 'Média #' + v ) : ( hasDefault ? 'Aucun média (défaut du site retiré)' : 'Aucun média' );
			}
			choose.textContent = v ? 'Remplacer' : ( isVideo ? 'Choisir une vidéo' : 'Choisir un média' );
			reset.hidden = ! hasDefault || v === fdef.default;
			remove.hidden = ! v;
		}
		var choose = el( 'button', { class: 'pst-ed-btn pst-ed-btn--sm', type: 'button', onclick: function () { openMedia( path, fdef, paint, status ); } } );
		var remove = el( 'button', { class: 'pst-ed-btn pst-ed-btn--sm pst-ed-btn--ghost', type: 'button', text: 'Retirer', onclick: function () { setPath( path, '' ); status.textContent = ''; status.className = 'pst-ed-field__help'; paint(); markDirty(); } } );
		var reset = el( 'button', { class: 'pst-ed-btn pst-ed-btn--sm pst-ed-btn--ghost', type: 'button', text: 'Restaurer la valeur par défaut', onclick: function () { setPath( path, fdef.default || '' ); status.textContent = ''; status.className = 'pst-ed-field__help'; paint(); markDirty(); } } );
		paint();
		return el( 'div', { class: 'pst-ed-field' }, [ el( 'label', { text: fdef.label || '' } ), preview, el( 'div', { class: 'pst-ed-media__actions' }, [ choose, remove, reset ] ), fdef.help ? el( 'p', { class: 'pst-ed-field__help', text: fdef.help } ) : null, status ] );
	}

	function openMedia( path, fdef, paint, status ) {
		if ( ! window.wp || ! window.wp.media ) { window.alert( 'Médiathèque WordPress indisponible.' ); return; }
		var opts = { title: fdef.media_type === 'video' ? 'Choisir une vidéo' : 'Choisir un média', multiple: false };
		if ( fdef.media_type ) { opts.library = { type: fdef.media_type }; }
		var frame = window.wp.media( opts );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			// Validation d'extension côté UI (le serveur revalide).
			var ext = ( att.url || '' ).split( '.' ).pop().toLowerCase().split( '?' )[ 0 ];
			if ( Array.isArray( fdef.accept ) && fdef.accept.indexOf( ext ) < 0 ) {
				status.className = 'pst-ed-field__help is-warn';
				status.textContent = 'Format non pris en charge (' + ext + '). Attendu : ' + fdef.accept.join( ', ' ) + '.';
				return;
			}
			setPath( path, att.url );
			// Poids + avertissement si lourd.
			if ( att.filesizeHumanReadable ) {
				var heavy = att.filesizeInBytes && att.filesizeInBytes > 15 * 1024 * 1024;
				status.className = 'pst-ed-field__help' + ( heavy ? ' is-warn' : '' );
				status.textContent = att.filesizeHumanReadable + ( heavy ? ' — fichier lourd : pensez à compresser pour un chargement rapide.' : '' );
			} else { status.textContent = ''; status.className = 'pst-ed-field__help'; }
			paint();
			markDirty();
		} );
		frame.open();
	}

	// ------------------------------------------------------------- repeater
	function repeaterField( fk, fdef, path ) {
		var rows = getPath( path );
		if ( ! Array.isArray( rows ) ) { rows = []; setPath( path, rows ); }
		var wrap = el( 'div', { class: 'pst-ed-rep' } );
		var openIndex = -1; // ligne à ouvrir après reconstruction (nouvel élément)
		function rebuild() {
			wrap.innerHTML = '';
			rows.forEach( function ( row, i ) { wrap.appendChild( repRow( i ) ); } );
			wrap.appendChild( el( 'button', { class: 'pst-ed-rep__add', type: 'button', text: '+ Ajouter', onclick: function () {
				var blank = {}; Object.keys( fdef.fields ).forEach( function ( sk ) { blank[ sk ] = ''; } );
				rows.push( blank ); openIndex = rows.length - 1; markDirty(); rebuild(); schedulePreview();
			} } ) );
			openIndex = -1;
		}
		function repRow( i ) {
			var row = rows[ i ] || {};
			var keys = Object.keys( fdef.fields );
			var title = ( keys[ 0 ] && row[ keys[ 0 ] ] ) ? String( row[ keys[ 0 ] ] ) : ( ( fdef.label || 'Élément' ) + ' ' + ( i + 1 ) );
			var sub = ( keys[ 1 ] && row[ keys[ 1 ] ] ) ? String( row[ keys[ 1 ] ] ) : '';

			var fieldsWrap = el( 'div', { class: 'pst-ed-fields' } );
			keys.forEach( function ( sk ) { fieldsWrap.appendChild( fieldRow( sk, fdef.fields[ sk ], path.concat( [ i, sk ] ) ) ); } );

			var tools = el( 'div', { class: 'pst-ed-rep__row-tools' }, [
				el( 'button', { type: 'button', title: 'Monter', 'aria-label': 'Monter', text: '▲', onclick: function ( e ) { e.stopPropagation(); if ( i > 0 ) { rows.splice( i - 1, 0, rows.splice( i, 1 )[ 0 ] ); markDirty(); rebuild(); schedulePreview(); } } } ),
				el( 'button', { type: 'button', title: 'Descendre', 'aria-label': 'Descendre', text: '▼', onclick: function ( e ) { e.stopPropagation(); if ( i < rows.length - 1 ) { rows.splice( i + 1, 0, rows.splice( i, 1 )[ 0 ] ); markDirty(); rebuild(); schedulePreview(); } } } ),
				el( 'button', { type: 'button', title: 'Supprimer', 'aria-label': 'Supprimer', text: '✕', onclick: function ( e ) { e.stopPropagation(); rows.splice( i, 1 ); markDirty(); rebuild(); schedulePreview(); } } )
			] );
			var card = el( 'div', { class: 'pst-ed-rep__row' + ( i === openIndex ? ' is-open' : '' ) }, [
				el( 'div', { class: 'pst-ed-rep__row-head', onclick: function ( e ) { if ( e.target.closest( 'button' ) ) { return; } card.classList.toggle( 'is-open' ); } }, [
					el( 'span', { class: 'pst-ed-rep__grip', text: '⋮⋮' } ),
					el( 'div', { class: 'pst-ed-rep__row-main' }, [
						el( 'span', { class: 'pst-ed-rep__row-title', text: title } ),
						sub ? el( 'span', { class: 'pst-ed-rep__row-sub', text: sub } ) : null
					] ),
					tools,
					el( 'span', { class: 'pst-ed-rep__row-chevron', text: '▸' } )
				] ),
				el( 'div', { class: 'pst-ed-rep__fields' }, [ fieldsWrap ] )
			] );
			return card;
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

	// ============================================================ APERÇU (VRAI FRONT via iframe)
	// L'aperçu n'est PLUS recréé dans l'admin : c'est le VRAI front Postelio chargé en iframe, à qui
	// on injecte la configuration (enregistrée + modifications locales) par postMessage. Le SEO reste
	// un composant éditorial admin (SERP / Open Graph), pas un aperçu du site.
	var iframeEl = null, previewReady = false, savedAll = null, frameTimeout = null;

	// Routes réelles du front public (servi à la racine de l'origine).
	var FRONT_ROUTES = { home: '/index.html', navigation: '/index.html', footer: '/index.html', appearance: '/index.html', jobs: '/offres.html', companies: '/entreprises.html', skills: '/savoir-faire.html', advice: '/blog.html', contact: '/contact.html' };
	// `v` = version de l'admin : force un document d'aperçu frais à chaque mise à jour (le bridge du
	// front ignore ce paramètre ; sans lui, un index.html en cache navigateur garderait un vieux bridge).
	function frontUrl( page ) { return window.location.origin + ( FRONT_ROUTES[ page ] || '/index.html' ) + '?postelio_preview=1&v=' + encodeURIComponent( CFG.version || '1' ); }

	function renderPreview() {
		var canvas = document.getElementById( 'pst-ed-canvas' );
		canvas.className = 'pst-ed-canvas' + ( device === 'tablet' ? ' is-tablet' : device === 'mobile' ? ' is-mobile' : '' );
		if ( CFG.page === 'seo' ) {
			canvas.classList.add( 'pst-ed-canvas--doc' );
			canvas.innerHTML = '';
			var pv = el( 'div', { class: 'pst-pv' } );
			buildSEO( pv );
			canvas.appendChild( pv );
			iframeEl = null;
			return;
		}
		canvas.classList.remove( 'pst-ed-canvas--doc' );
		ensureFrame();
		postToPreview();
	}

	function ensureFrame() {
		var canvas = document.getElementById( 'pst-ed-canvas' );
		if ( iframeEl && iframeEl.getAttribute( 'data-page' ) === CFG.page ) { return; }
		canvas.innerHTML = '';
		previewReady = false;
		clearTimeout( frameTimeout );

		var loader = el( 'div', { class: 'pst-ed-frame__state', id: 'pst-ed-frame-state' }, [
			el( 'span', { class: 'pst-ed-spin' } ),
			el( 'span', { text: 'Chargement de l’aperçu…' } )
		] );
		iframeEl = document.createElement( 'iframe' );
		iframeEl.className = 'pst-ed-frame';
		iframeEl.setAttribute( 'title', 'Aperçu du site Postelio' );
		iframeEl.setAttribute( 'data-page', CFG.page );
		iframeEl.addEventListener( 'load', function () { setTimeout( postToPreview, 120 ); } );
		iframeEl.addEventListener( 'error', frameError );
		iframeEl.src = frontUrl( CFG.page );

		frameTimeout = setTimeout( function () { if ( ! previewReady ) { frameError(); } }, 9000 );
		canvas.appendChild( iframeEl );
		canvas.appendChild( loader );
	}

	function frameError() {
		var s = document.getElementById( 'pst-ed-frame-state' );
		if ( ! s ) { return; }
		s.className = 'pst-ed-frame__state is-error';
		s.textContent = '';
		s.appendChild( el( 'span', { text: 'Impossible de charger l’aperçu du site.' } ) );
		s.appendChild( el( 'button', { class: 'pst-ed-btn pst-ed-btn--sm', type: 'button', text: 'Réessayer', onclick: function () { iframeEl = null; renderPreview(); } } ) );
	}

	function postToPreview() {
		if ( ! iframeEl || ! iframeEl.contentWindow ) { return; }
		var merged = {};
		if ( savedAll && typeof savedAll === 'object' ) { Object.keys( savedAll ).forEach( function ( k ) { merged[ k ] = savedAll[ k ]; } ); }
		merged[ CFG.page ] = state; // les modifications NON ENREGISTRÉES priment
		try {
			// `target` : le bridge du front positionne l'aperçu sur l'élément RÉEL (ex. footer) et
			// l'y maintient après chaque modification live (jamais de retour au hero).
			iframeEl.contentWindow.postMessage( { type: 'postelio-site-preview', page: CFG.page, config: merged, target: PREVIEW_TARGET }, window.location.origin );
		} catch ( e ) {}
	}

	function wirePreview() {
		window.addEventListener( 'message', function ( e ) {
			if ( e.origin !== window.location.origin || ! e.data ) { return; }
			if ( e.data.type === 'postelio-preview-ready' ) {
				previewReady = true;
				clearTimeout( frameTimeout );
				var s = document.getElementById( 'pst-ed-frame-state' );
				if ( s ) { s.style.display = 'none'; }
				postToPreview();
			}
		} );
		// Charge une fois la config ENREGISTRÉE des autres pages (REST public, non sensible).
		if ( CFG.configUrl ) {
			fetch( CFG.configUrl, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) { savedAll = ( j && j.data && j.data.pages ) || {}; postToPreview(); } )
			.catch( function () { savedAll = {}; } );
		}
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
		if ( FORCED_DEVICE ) {
			// Page à appareil imposé (Footer = mobile) : pas de sélecteur Desktop / Tablette / Mobile.
			var devs = document.getElementById( 'pst-ed-devices' ); if ( devs ) { devs.hidden = true; }
			var lbl = document.getElementById( 'pst-ed-pvlabel' );
			if ( lbl ) { lbl.textContent = FORCED_DEVICE === 'mobile' ? 'Aperçu mobile' : ( FORCED_DEVICE === 'tablet' ? 'Aperçu tablette' : 'Aperçu desktop' ); }
		} else {
			Array.prototype.forEach.call( document.querySelectorAll( '.pst-ed-devices button' ), function ( b ) {
				b.addEventListener( 'click', function () {
					device = b.getAttribute( 'data-device' );
					Array.prototype.forEach.call( document.querySelectorAll( '.pst-ed-devices button' ), function ( x ) { x.classList.toggle( 'is-active', x === b ); } );
					renderPreview();
				} );
			} );
		}
		var s = document.getElementById( 'pst-ed-save' ); if ( s ) { s.addEventListener( 'click', save ); }
		var s2 = document.getElementById( 'pst-ed-savebar-save' ); if ( s2 ) { s2.addEventListener( 'click', save ); }
		var c = document.getElementById( 'pst-ed-savebar-cancel' ); if ( c ) { c.addEventListener( 'click', function () { state = deepClone( CFG.values || {} ); dirty = false; savebar( false ); renderEditor(); renderPreview(); } ); }
		window.addEventListener( 'beforeunload', function ( e ) { if ( dirty ) { e.preventDefault(); e.returnValue = ''; } } );
		var voir = document.getElementById( 'pst-ed-voir' ); if ( voir ) { voir.href = window.location.origin + '/'; }
		var pvopen = document.getElementById( 'pst-ed-pvopen' ); if ( pvopen ) { pvopen.href = window.location.origin + ( FRONT_ROUTES[ CFG.page ] || '/index.html' ); }
		wirePreview();
		renderEditor(); renderPreview();
	}

	function normalizeHex( v ) { return /^#[0-9a-fA-F]{6}$/.test( v ) ? v : '#000000'; }

	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', init ); } else { init(); }
} )();
