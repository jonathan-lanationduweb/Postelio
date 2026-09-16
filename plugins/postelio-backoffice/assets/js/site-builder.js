/**
 * Site Builder — moteur d'édition du back-office unifié (vanilla JS, aucun framework).
 * Piloté par le SCHÉMA injecté (window.PST_BO_SITE) : accordéons de section numérotés (titre,
 * description courte, état, switch, chevron ; utilisables au clavier), champs (texte, zone, select,
 * nombre, switch, couleur, média, répéteur, collection), champs conditionnels (show_if), rappel
 * d'identité globale, cartes média (aperçu réel, nom, format, dimensions, poids), aperçu = VRAI FRONT
 * en iframe (?postelio_preview=1 → postMessage → preview-ready, cible et appareil imposables par le
 * schéma), état de sauvegarde réel (en-tête + barre), retours discrets. Toute chaîne utilisateur
 * passe par textContent (jamais innerHTML).
 *
 * Mécanique d'aperçu strictement conservée (bridge front inchangé : message
 * `postelio-site-preview` { page, config, target } / réponse `postelio-preview-ready`).
 */
( function () {
	'use strict';

	var CFG = window.PST_BO_SITE;
	if ( ! CFG || ! CFG.schema ) { return; }

	// ============================================================ ÉTAT
	var state = clone( CFG.values || {} );
	var dirty = false;
	var saving = false;
	var FORCED_DEVICE  = ( [ 'mobile', 'tablet', 'desktop' ].indexOf( CFG.schema.preview_device ) >= 0 ) ? CFG.schema.preview_device : null;
	var PREVIEW_TARGET = ( [ 'footer', 'header' ].indexOf( CFG.schema.preview_target ) >= 0 ) ? CFG.schema.preview_target : null;
	var device = FORCED_DEVICE || 'desktop';
	var previewTimer = null;
	var activeSeo = 'home';
	var resolveCache = {};  // collection : { type: { id: {label,sub,state,missing} } }
	var mediaMeta = {};     // média : { url: { size, heavy, w, h } } (poids connu après sélection, dimensions à la lecture)
	var deps = [];          // champs conditionnels : { node, path, equals }
	var uid = 0;

	var SEO_PATHS = { home: '/', jobs: '/offres', companies: '/entreprises', skills: '/savoir-faire', advice: '/conseils', contact: '/contact' };
	var FRONT_ROUTES = { home: '/index.html', navigation: '/index.html', footer: '/index.html', appearance: '/index.html', jobs: '/offres.html', companies: '/entreprises.html', skills: '/savoir-faire.html', advice: '/blog.html', contact: '/contact.html' };

	// ============================================================ LANGAGE UTILISATEUR
	// Descriptions courtes des sections / groupes (présentation uniquement ; le schéma reste la source).
	var SECTION_DESC = {
		hero: 'Premier écran de la page', search: 'Barre de recherche et textes indicatifs', categories: 'Catégories mises en avant',
		jobs: 'Offres mises en avant', companies: 'Entreprises mises en avant', skills: 'Savoir-faire mis en avant',
		arguments: 'Points forts de la plateforme', articles: 'Articles mis en avant', cta: 'Appel à l’action en bas de page',
		filters: 'Filtres proposés aux visiteurs', results: 'Liste des résultats', featured: 'Sélection mise en avant',
		feed: 'Derniers contenus publiés', intro: 'Texte d’introduction', coordinates: 'Coordonnées affichées',
		form: 'Champs et libellés du formulaire', extra: 'Bloc complémentaire', global: 'Réglages communs à toutes les pages'
	};
	var GROUP_DESC = {
		'Marque': 'Logo et nom affichés', 'Liens': 'Liens du menu principal', 'Boutons': 'Connexion et inscription',
		'Colonnes de liens': 'Colonnes du pied de page', 'Réseaux sociaux': 'Profils affichés', 'Mentions / bas de page': 'Liens légaux et copyright',
		'Réglages': 'Blocs affichés', 'Identité': 'Nom, logo, favicon et image de partage', 'Couleurs': 'Palette du site',
		'Typographie': 'Polices et taille de base'
	};
	if ( CFG.page === 'appearance' ) { GROUP_DESC[ 'Boutons' ] = 'Forme et style des boutons'; }
	// Libellés / aides réécrits en langage utilisateur (les clés techniques restent internes).
	var LABELS = {
		'Logo (override en-tête)': 'Logo de l’en-tête', 'Nom de marque (override en-tête)': 'Nom affiché dans l’en-tête',
		'Logo (override footer)': 'Logo du footer', 'Nom de marque (override footer)': 'Nom affiché dans le footer',
		'Utiliser le logo global (Apparence → Identité)': 'Utiliser le logo global', 'Description (propre au footer)': 'Description',
		'Ne pas indexer (noindex)': 'Masquer des moteurs de recherche', 'Template de titre': 'Modèle de titre',
		'Placeholder recherche': 'Texte indicatif de la recherche', 'Placeholder principal': 'Texte indicatif (métier)',
		'Placeholder localisation': 'Texte indicatif (lieu)', 'Placeholder message': 'Texte indicatif du message',
		'CTA principal — libellé': 'Bouton principal', 'CTA principal — lien': 'Lien du bouton principal',
		'CTA secondaire — libellé': 'Bouton secondaire', 'CTA secondaire — lien': 'Lien du bouton secondaire',
		'Titre social (Open Graph)': 'Titre pour les réseaux sociaux', 'Sélection (mode manuel)': 'Contenus sélectionnés',
		'Nombre (mode auto)': 'Nombre affiché', 'Icône (emoji)': 'Icône', 'Couleur primaire': 'Couleur principale',
		'Couleur accent': 'Couleur d’accent', 'Activer l’intro cinématique': 'Intro vidéo', 'Activer l\'intro cinématique': 'Intro vidéo',
		'Poster (image de la vidéo)': 'Image d’attente de la vidéo', 'Image sociale par défaut': 'Image de partage par défaut',
		'Image sociale': 'Image de partage', 'Meta description': 'Description pour les moteurs de recherche',
		'Meta description par défaut': 'Description par défaut pour les moteurs de recherche', 'Titre SEO': 'Titre pour les moteurs de recherche',
		'Description sociale': 'Description pour les réseaux sociaux', 'Filtres visibles (ordre = affichage)': 'Filtres visibles',
		'Texte « aucune offre »': 'Texte si aucune offre'
	};
	var HELPS = {
		'Le comportement de défilement reste géré par le front ; on ne change ici que le média.': 'L’animation reste celle du site ; seul le média change.',
		'Recherchez et ajoutez du contenu ; le stockage se fait par référence stable.': 'Recherchez un contenu puis ajoutez-le à la sélection.',
		'Activé seulement si le front l\'implémente.': 'Pris en compte si le site le propose.',
		'Activé seulement si le front l’implémente.': 'Pris en compte si le site le propose.',
		'Appliqué par le front s\'il le supporte.': 'Pris en compte si le site le permet.',
		'Appliqué par le front s’il le supporte.': 'Pris en compte si le site le permet.',
		'Affiché dans l\'en-tête et le footer (sauf override local).': 'Affiché dans l’en-tête et le footer, sauf nom propre défini localement.',
		'Affiché dans l’en-tête et le footer (sauf override local).': 'Affiché dans l’en-tête et le footer, sauf nom propre défini localement.',
		'Laissez vide pour reprendre le nom de marque global (Apparence → Identité).': 'Vide : le nom de marque global est repris.',
		'Utilisez %page% pour insérer le titre de la page.': 'Le mot %page% est remplacé par le titre de la page.',
		'SVG (recommandé), PNG ou ICO, carré. « Défaut » restaure le favicon Postelio validé.': 'SVG (recommandé), PNG ou ICO, carré. « Restaurer » remet le favicon Postelio.',
		'SVG, PNG, WebP ou JPG. Vide = pastille « P » par défaut du site.': 'SVG, PNG, WebP ou JPG. Vide : pastille « P » du site.',
		'MP4 ou WebM. Vide = vidéo par défaut du site. Compressez les vidéos lourdes pour un chargement rapide.': 'MP4 ou WebM. Vide : vidéo par défaut du site. Une vidéo légère charge plus vite.',
		'Désactivez pour définir un logo propre au footer.': 'Désactivez pour choisir un logo propre au footer.'
	};
	// Compteurs informatifs (jamais bloquants) sur les champs éditoriaux courts.
	var SOFT_COUNTERS = { title: 70, subtitle: 120, brand_name: 40, site_name: 40 };
	// Libellé du bouton d'ajout des répéteurs, selon ce qu'on ajoute réellement.
	var ADD_LABELS = { 'Liens du menu': 'Ajouter un lien', 'Colonnes de liens': 'Ajouter une colonne', 'Réseaux sociaux': 'Ajouter un réseau', 'Filtres visibles': 'Ajouter un filtre', 'Arguments': 'Ajouter un argument', 'Catégories visibles': 'Ajouter une catégorie', 'Sujets proposés': 'Ajouter un sujet' };

	function label( fdef ) { var l = fdef.label || ''; return LABELS[ l ] || l; }
	function help( fdef ) { var h = fdef.help || ''; return HELPS[ h ] || h; }
	function addLabel( fdef ) { var l = label( fdef ); return ADD_LABELS[ l ] || 'Ajouter un élément'; }

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
	/** Icône SVG statique interne (aucune donnée utilisateur n'y transite). */
	function svg( name ) {
		var P = {
			chevron: '<path d="m9 6 6 6-6 6"/>', up: '<path d="m6 15 6-6 6 6"/>', down: '<path d="m6 9 6 6 6-6"/>',
			close: '<path d="M6 6l12 12"/><path d="M18 6 6 18"/>', image: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-8 8"/>',
			video: '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10 5-3v10l-5-3"/>', refresh: '<path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v5h-5"/>',
			check: '<path d="m5 12 5 5L20 7"/>', plus: '<path d="M12 5v14"/><path d="M5 12h14"/>', pending: '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>'
		};
		var ns = 'http://www.w3.org/2000/svg';
		var s = document.createElementNS( ns, 'svg' );
		s.setAttribute( 'class', 'sb-ico' ); s.setAttribute( 'viewBox', '0 0 24 24' ); s.setAttribute( 'aria-hidden', 'true' );
		var tpl = document.createElementNS( ns, 'svg' );
		tpl.innerHTML = P[ name ] || ''; // chaîne constante du code, jamais une saisie
		while ( tpl.firstChild ) { s.appendChild( tpl.firstChild ); }
		return s;
	}
	function iconBtn( name, title, cls, onclick ) {
		return el( 'button', { type: 'button', class: 'sb-iconbtn' + ( cls ? ' ' + cls : '' ), title: title, 'aria-label': title, onclick: onclick }, [ svg( name ) ] );
	}
	function getPath( path ) { var o = state; for ( var i = 0; i < path.length; i++ ) { if ( o == null ) { return undefined; } o = o[ path[ i ] ]; } return o; }
	function setPath( path, val ) { var o = state; for ( var i = 0; i < path.length - 1; i++ ) { if ( o[ path[ i ] ] == null ) { o[ path[ i ] ] = ( typeof path[ i + 1 ] === 'number' ) ? [] : {}; } o = o[ path[ i ] ]; } o[ path[ path.length - 1 ] ] = val; }
	function basename( u ) { return String( u || '' ).split( '/' ).pop().split( '?' )[ 0 ]; }
	function ext( u ) { var b = basename( u ); return b.indexOf( '.' ) >= 0 ? b.split( '.' ).pop().toUpperCase() : ''; }
	function mediaUrl( v ) {
		v = String( v || '' ).trim();
		if ( /^https?:\/\//i.test( v ) ) { return v; }
		if ( /^\//.test( v ) ) { return window.location.origin + v; }
		return '';
	}
	function adminPage( slug ) { return ( CFG.adminBase || window.location.pathname ) + '?page=' + encodeURIComponent( slug ); }
	function appearance() { return CFG.page === 'appearance' ? state : ( CFG.appearance || {} ); }
	function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }
	function normalizeHex( v ) { return /^#[0-9a-fA-F]{6}$/.test( v ) ? v : '#000000'; }
	function schedulePreview() { clearTimeout( previewTimer ); previewTimer = setTimeout( renderPreview, 120 ); }
	function markDirty() { if ( ! dirty ) { dirty = true; saveState( 'dirty' ); } schedulePreview(); }

	// ============================================================ RETOURS DISCRETS
	var toastTimer = null;
	function notify( text, kind ) {
		var old = document.getElementById( 'pst-bo-toast' );
		if ( old ) { old.remove(); }
		var t = el( 'div', { id: 'pst-bo-toast', class: 'sb-toast' + ( kind ? ' sb-toast--' + kind : '' ), role: 'status' }, [ svg( kind === 'error' ? 'close' : 'check' ), el( 'span', { text: text } ) ] );
		document.body.appendChild( t );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () { t.classList.add( 'is-leaving' ); setTimeout( function () { t.remove(); }, 220 ); }, kind === 'error' ? 4200 : 2200 );
	}

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
		if ( schema.source_note ) { panel.appendChild( el( 'div', { class: 'sb-note sb-note--info', text: schema.source_note } ) ); }

		if ( schema.type === 'sections' ) {
			sectionOrder().forEach( function ( skey, i ) { panel.appendChild( sectionCard( skey, schema.sections[ skey ], i + 1 ) ); } );
		} else if ( schema.groups ) {
			schema.groups.forEach( function ( g, i ) {
				panel.appendChild( groupCard( g.label, GROUP_DESC[ g.label ] || '', i + 1, i === 0, function ( body ) {
					if ( g.identity_hint ) { body.appendChild( identityHint() ); }
					g.fields.forEach( function ( fk ) { if ( schema.fields[ fk ] ) { body.appendChild( fieldRow( fk, schema.fields[ fk ], [ fk ] ) ); } } );
				} ) );
			} );
		} else {
			panel.appendChild( groupCard( 'Réglages', '', 1, true, function ( body ) {
				Object.keys( schema.fields ).forEach( function ( fk ) { body.appendChild( fieldRow( fk, schema.fields[ fk ], [ fk ] ) ); } );
			} ) );
		}
		if ( schema.resettable ) { panel.appendChild( resetControl() ); }
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
			el( 'button', { type: 'button', class: 'bo-btn bo-btn--sm bo-btn--ghost', text: 'Réinitialiser aux valeurs Postelio', onclick: function () {
				if ( window.confirm( 'Réinitialiser cette page aux valeurs Postelio par défaut ? Vos modifications non enregistrées seront perdues.' ) ) {
					state = schemaDefaults( CFG.schema ); markDirty(); renderEditor(); renderPreview(); notify( 'Valeurs par défaut restaurées' );
				}
			} } )
		] );
	}

	/**
	 * Accordéon (section ou groupe) : en-tête numéroté (titre, description, état, switch, chevron),
	 * utilisable au clavier (Entrée / Espace), aria-expanded / aria-controls.
	 */
	function accordion( opts ) {
		var id = 'pst-acc-' + ( ++uid );
		var body = el( 'div', { class: 'sb-acc__body', id: id }, [ opts.body ] );
		var card = el( 'section', { class: 'sb-acc' + ( opts.open ? ' is-open' : '' ) + ( opts.off ? ' is-off' : '' ) } );
		var chip = opts.hasToggle ? el( 'span', { class: 'sb-chip' + ( opts.off ? ' sb-chip--off' : ' sb-chip--on' ), text: opts.off ? 'Inactif' : 'Actif' } ) : null;
		var head = el( 'div', { class: 'sb-acc__head', role: 'button', tabindex: '0', 'aria-expanded': opts.open ? 'true' : 'false', 'aria-controls': id }, [
			opts.lead || el( 'span', { class: 'sb-acc__num', text: pad( opts.num ) } ),
			el( 'div', { class: 'sb-acc__titles' }, [
				el( 'span', { class: 'sb-acc__title', text: opts.title } ),
				opts.desc ? el( 'span', { class: 'sb-acc__desc', text: opts.desc } ) : null,
				opts.summary ? el( 'span', { class: 'sb-acc__summary', text: opts.summary } ) : null
			] ),
			chip,
			opts.control ? stopClicks( opts.control ) : null,
			el( 'span', { class: 'sb-acc__chevron' }, [ svg( 'chevron' ) ] )
		] );
		function toggle() {
			var open = ! card.classList.contains( 'is-open' );
			if ( opts.exclusive && open ) {
				Array.prototype.forEach.call( document.querySelectorAll( '#pst-bo-panel .sb-acc.is-open' ), function ( c ) {
					if ( c !== card ) { c.classList.remove( 'is-open' ); var h = c.querySelector( '.sb-acc__head' ); if ( h ) { h.setAttribute( 'aria-expanded', 'false' ); } }
				} );
			}
			card.classList.toggle( 'is-open', open );
			head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( opts.onToggle ) { opts.onToggle( open ); }
		}
		head.addEventListener( 'click', function ( e ) { if ( e.target.closest( 'button, input, label, a' ) ) { return; } toggle(); } );
		head.addEventListener( 'keydown', function ( e ) { if ( e.target !== head ) { return; } if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); toggle(); } } );
		card.appendChild( head ); card.appendChild( body );
		card.setState = function ( on ) { card.classList.toggle( 'is-off', ! on ); if ( chip ) { chip.textContent = on ? 'Actif' : 'Inactif'; chip.className = 'sb-chip ' + ( on ? 'sb-chip--on' : 'sb-chip--off' ); } };
		return card;
	}

	/** Groupe (pages « single ») : ouvert par défaut pour le premier. */
	function groupCard( title, desc, num, open, fill ) {
		var fields = el( 'div', { class: 'sb-fields' } );
		fill( fields );
		return accordion( { num: num, title: title, desc: desc, open: open, body: fields } );
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

	/** Section (pages « sections ») : accordéon numéroté + switch d'activation + réordonnancement réel (Monter / Descendre). */
	function sectionCard( skey, sdef, num ) {
		var sval = state[ skey ] = state[ skey ] || {};
		var noToggle = !! sdef.no_toggle;
		var enabled = noToggle || sval._enabled !== false;
		var isSeo = !! CFG.schema.seo;

		var fields = el( 'div', { class: 'sb-fields' } );
		Object.keys( sdef.fields || {} ).forEach( function ( fk ) { fields.appendChild( fieldRow( fk, sdef.fields[ fk ], [ skey, fk ] ) ); } );

		var control = null, card;
		if ( ! noToggle ) {
			control = switchEl( enabled, function ( on ) { sval._enabled = on; card.setState( on ); markDirty(); notify( 'Section « ' + ( sdef.label || skey ) + ' » ' + ( on ? 'activée' : 'désactivée' ) ); }, 'Activer la section ' + ( sdef.label || skey ) );
		}
		var lead = null;
		if ( sdef.reorderable !== false && ! isSeo ) {
			lead = el( 'span', { class: 'sb-acc__lead' }, [
				el( 'span', { class: 'sb-acc__num', text: pad( num ) } ),
				el( 'span', { class: 'sb-acc__reorder' }, [
					iconBtn( 'up', 'Monter la section', 'sb-iconbtn--xs', function ( e ) { e.stopPropagation(); moveSection( skey, -1 ); } ),
					iconBtn( 'down', 'Descendre la section', 'sb-iconbtn--xs', function ( e ) { e.stopPropagation(); moveSection( skey, 1 ); } )
				] )
			] );
		}
		card = accordion( {
			num: num, lead: lead, title: sdef.label || skey, desc: isSeo && skey !== 'global' ? 'Titre, description et image de partage de la page' : ( SECTION_DESC[ skey ] || '' ), summary: sectionSummary( sval, sdef ),
			hasToggle: ! noToggle, off: ! enabled, control: control, body: fields, exclusive: isSeo,
			onToggle: function ( open ) { if ( isSeo && open && skey !== 'global' ) { activeSeo = skey; schedulePreview(); } }
		} );
		return card;
	}

	function stopClicks( node ) { var w = el( 'span', { class: 'sb-acc__control' }, [ node ] ); w.addEventListener( 'click', function ( e ) { e.stopPropagation(); } ); return w; }

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

	// Rappel de l'identité globale (Apparence → Identité) dans les groupes « Marque ».
	function identityHint() {
		var a = appearance();
		var logo = a.logo ? mediaUrl( a.logo ) : '';
		var thumb = el( 'span', { class: 'sb-identity__thumb' + ( logo ? '' : ' is-empty' ), text: logo ? '' : 'P' } );
		if ( logo ) { var img = el( 'img', { alt: '' } ); img.src = logo; thumb.appendChild( img ); }
		return el( 'div', { class: 'sb-identity' }, [
			thumb,
			el( 'div', { class: 'sb-identity__main' }, [
				el( 'span', { class: 'sb-identity__label', text: 'Identité globale : ' + ( a.brand_name || 'Postelio' ) } ),
				el( 'span', { class: 'sb-identity__sub', text: logo ? basename( logo ) : 'Aucun logo global : pastille « P » du site' } )
			] ),
			el( 'a', { class: 'bo-btn bo-btn--sm bo-btn--ghost', href: adminPage( 'postelio-site-appearance' ), text: 'Modifier dans Apparence' } )
		] );
	}

	// ------------------------------------------------------------ champs
	function fieldRow( fk, fdef, path ) {
		var node = buildField( fk, fdef, path );
		if ( node && ( fdef.col === 'half' || fdef.type === 'color' ) ) { node.classList.add( 'sb-field--half' ); }
		if ( node && fdef.show_if && fdef.show_if.field ) {
			deps.push( { node: node, path: path.slice( 0, -1 ).concat( [ fdef.show_if.field ] ), equals: fdef.show_if.equals } );
		}
		return node;
	}

	function buildField( fk, fdef, path ) {
		var type = fdef.type || 'text';
		if ( type === 'repeater' ) { return wrapField( fdef, repeaterField( fdef, path ), null, true ); }
		if ( type === 'collection' ) { return wrapField( fdef, collectionField( fdef, path ), null, true ); }
		if ( type === 'toggle' ) { return toggleField( fdef, path ); }
		if ( type === 'color' ) { return colorField( fdef, path ); }
		if ( type === 'media' ) { return mediaField( fk, fdef, path ); }

		var value = getPath( path ), control, counter = null, max = fdef.counter || SOFT_COUNTERS[ fk ] || 0;
		var id = 'pst-f-' + ( ++uid );
		if ( type === 'textarea' ) {
			control = el( 'textarea', { class: 'sb-textarea', id: id, placeholder: fdef.placeholder || '' } );
			control.value = value || '';
			control.addEventListener( 'input', function () { setPath( path, control.value ); if ( counter ) { updateCounter( counter, control.value, max ); } markDirty(); } );
		} else if ( type === 'select' ) {
			control = el( 'select', { class: 'sb-select', id: id } );
			var opts = fdef.options || {};
			Object.keys( opts ).forEach( function ( ov ) { control.appendChild( el( 'option', { value: ov, text: opts[ ov ] } ) ); } );
			control.value = value;
			control.addEventListener( 'change', function () { setPath( path, control.value ); markDirty(); } );
		} else if ( type === 'number' ) {
			control = el( 'input', { class: 'sb-input', id: id, type: 'number', min: fdef.min, max: fdef.max } );
			control.value = value;
			control.addEventListener( 'input', function () { setPath( path, parseInt( control.value, 10 ) || 0 ); markDirty(); } );
		} else {
			control = el( 'input', { class: 'sb-input', id: id, type: 'text', placeholder: fdef.placeholder || '' } );
			control.value = value || '';
			control.addEventListener( 'input', function () { setPath( path, control.value ); if ( counter ) { updateCounter( counter, control.value, max ); } markDirty(); } );
		}
		if ( max ) { counter = el( 'span', { class: 'sb-count', 'aria-live': 'polite' } ); updateCounter( counter, value || '', max ); }
		return wrapField( fdef, control, counter, false, id );
	}

	function updateCounter( node, val, max ) { var n = ( val || '' ).length; node.textContent = n + ' / ' + max; node.classList.toggle( 'is-over', n > max ); }

	/** Enveloppe de champ : libellé (+ compteur à droite), contrôle, aide courte. */
	function wrapField( fdef, control, counter, block, forId ) {
		var head = el( 'div', { class: 'sb-field__head' }, [ el( 'label', { class: 'sb-field__label', 'for': forId || null, text: label( fdef ) } ), counter ] );
		var kids = [ head, control ];
		var h = help( fdef );
		if ( h ) { kids.push( el( 'p', { class: 'sb-field__help', text: h } ) ); }
		return el( 'div', { class: 'sb-field' + ( block ? ' sb-field--block' : '' ) }, kids );
	}

	function toggleField( fdef, path ) {
		var stateTxt = el( 'span', { class: 'sb-toggle__state', text: getPath( path ) ? 'Actif' : 'Inactif' } );
		var sw = switchEl( !! getPath( path ), function ( on ) { setPath( path, on ); stateTxt.textContent = on ? 'Actif' : 'Inactif'; refreshDeps(); markDirty(); }, label( fdef ) );
		var h = help( fdef );
		return el( 'div', { class: 'sb-field sb-field--toggle' }, [
			el( 'div', { class: 'sb-toggle' }, [
				el( 'div', { class: 'sb-toggle__text' }, [ el( 'span', { class: 'sb-field__label', text: label( fdef ) } ), h ? el( 'span', { class: 'sb-field__help', text: h } ) : null ] ),
				el( 'div', { class: 'sb-toggle__ctl' }, [ stateTxt, sw ] )
			] )
		] );
	}

	function switchEl( checked, onChange, ariaLabel ) {
		var input = el( 'input', { type: 'checkbox', role: 'switch', 'aria-label': ariaLabel || null } ); input.checked = !! checked;
		input.setAttribute( 'aria-checked', checked ? 'true' : 'false' );
		input.addEventListener( 'change', function () { input.setAttribute( 'aria-checked', input.checked ? 'true' : 'false' ); onChange( input.checked ); } );
		return el( 'label', { class: 'sb-switch' }, [ input, el( 'span', { class: 'sb-switch__track' } ) ] );
	}

	/** Couleur : pastille cliquable (sélecteur natif), valeur hexadécimale, nom fonctionnel. */
	function colorField( fdef, path ) {
		var value = getPath( path ) || fdef.default || '#000000';
		var picker = el( 'input', { type: 'color', class: 'sb-color__native', 'aria-label': label( fdef ) } ); picker.value = normalizeHex( value );
		var swatch = el( 'span', { class: 'sb-color__swatch', 'aria-hidden': 'true' } ); swatch.style.background = normalizeHex( value );
		var hex = el( 'input', { class: 'sb-input sb-color__hex', type: 'text', 'aria-label': label( fdef ) + ' (hexadécimal)', spellcheck: 'false' } ); hex.value = value;
		var mark = el( 'span', { class: 'sb-color__default', text: 'Couleur Postelio' } );
		function refresh( v ) { if ( /^#[0-9a-fA-F]{6}$/.test( v ) ) { swatch.style.background = v; picker.value = v; } mark.hidden = ! ( fdef.default && v.toLowerCase() === String( fdef.default ).toLowerCase() ); }
		function apply( v ) { setPath( path, v ); refresh( v ); markDirty(); }
		picker.addEventListener( 'input', function () { hex.value = picker.value; apply( picker.value ); } );
		hex.addEventListener( 'input', function () { apply( hex.value.trim() ); } );
		refresh( value );
		return el( 'div', { class: 'sb-field' }, [
			el( 'div', { class: 'sb-color' }, [
				el( 'label', { class: 'sb-color__well', title: 'Choisir une couleur' }, [ swatch, picker ] ),
				el( 'div', { class: 'sb-color__main' }, [
					el( 'span', { class: 'sb-color__name', text: label( fdef ) } ),
					el( 'div', { class: 'sb-color__row' }, [ hex, mark ] )
				] )
			] )
		] );
	}

	// ------------------------------------------------------------ média (carte visuelle)
	function mediaVariant( fk, fdef ) {
		if ( fdef.media_type === 'video' ) { return 'video'; }
		if ( fdef.preview === 'icon' || fk === 'favicon' ) { return 'favicon'; }
		if ( fk === 'logo_light' ) { return 'logo-dark'; }
		if ( fdef.preview === 'contain' || fk === 'logo' ) { return 'logo'; }
		if ( /social/.test( fk ) ) { return 'social'; }
		return 'image';
	}

	/**
	 * Carte média : aperçu réel (image, logo sur fond clair / sombre, favicon 16 et 32 px, image de
	 * partage au ratio social, vidéo avec son image d'attente), nom, format · dimensions · poids,
	 * actions compactes ; état vide = zone d'ajout propre. Le warning > 15 Mo reste visible et discret.
	 */
	function mediaField( fk, fdef, path ) {
		var variant = mediaVariant( fk, fdef );
		var isVideo = variant === 'video';
		var hasDefault = !! fdef.default;
		var accept = Array.isArray( fdef.accept ) ? fdef.accept.map( function ( a ) { return a.toUpperCase(); } ).join( ', ' ) : ( isVideo ? 'MP4, WebM' : 'JPG, PNG, WebP, SVG' );
		var card = el( 'div', { class: 'sb-media sb-media--' + variant } );
		var status = el( 'p', { class: 'sb-field__help is-warn', hidden: 'hidden' } );

		function paint() {
			card.textContent = '';
			var v = getPath( path ), url = mediaUrl( v );
			if ( ! v ) {
				card.classList.add( 'is-empty' );
				card.appendChild( el( 'button', { type: 'button', class: 'sb-media__empty', onclick: function () { openMedia( path, fdef, paint, status, false ); } }, [
					el( 'span', { class: 'sb-media__emptyicon' }, [ svg( isVideo ? 'video' : 'image' ) ] ),
					el( 'span', { class: 'sb-media__emptytitle', text: isVideo ? 'Ajouter une vidéo' : 'Ajouter un média' } ),
					el( 'span', { class: 'sb-media__emptysub', text: 'Formats acceptés : ' + accept } )
				] ) );
				if ( hasDefault ) {
					card.appendChild( el( 'div', { class: 'sb-media__foot' }, [ el( 'button', { class: 'bo-btn bo-btn--sm bo-btn--ghost', type: 'button', text: 'Restaurer la valeur Postelio', onclick: function () { setPath( path, fdef.default || '' ); paint(); markDirty(); notify( 'Valeur par défaut restaurée' ); } } ) ] ) );
				}
				return;
			}
			card.classList.remove( 'is-empty' );
			var preview = el( 'div', { class: 'sb-media__preview' } );
			var meta = el( 'div', { class: 'sb-media__meta' } );
			var m = mediaMeta[ url ] || {};
			function metaText() {
				var parts = [];
				var e = ext( url || String( v ) );
				if ( e ) { parts.push( e ); }
				if ( m.w && m.h ) { parts.push( m.w + ' × ' + m.h + ' px' ); }
				if ( m.size ) { parts.push( m.size ); }
				if ( hasDefault && v === fdef.default ) { parts.push( 'Valeur Postelio par défaut' ); }
				meta.textContent = parts.join( ' · ' );
			}
			if ( isVideo ) {
				var video = el( 'video', { muted: 'muted', playsinline: 'playsinline', preload: 'metadata', 'aria-label': 'Aperçu de la vidéo' } );
				var posterVal = getPath( path.slice( 0, -1 ).concat( [ 'poster' ] ) );
				if ( posterVal && mediaUrl( posterVal ) ) { video.setAttribute( 'poster', mediaUrl( posterVal ) ); }
				if ( url ) { video.src = url; }
				video.addEventListener( 'loadedmetadata', function () { if ( video.videoWidth ) { m.w = video.videoWidth; m.h = video.videoHeight; mediaMeta[ url ] = m; metaText(); } } );
				preview.appendChild( video );
				preview.appendChild( el( 'span', { class: 'sb-media__badge' }, [ svg( 'video' ), el( 'span', { text: 'Vidéo' } ) ] ) );
			} else if ( url && variant === 'favicon' ) {
				[ 32, 16 ].forEach( function ( s ) {
					var img = el( 'img', { alt: '', width: String( s ), height: String( s ) } ); img.src = url;
					preview.appendChild( el( 'span', { class: 'sb-media__favicon' }, [ img, el( 'small', { text: s + ' × ' + s } ) ] ) );
				} );
				var tabImg = el( 'img', { alt: '' } ); tabImg.src = url;
				preview.appendChild( el( 'span', { class: 'sb-media__tab' }, [ tabImg, el( 'span', { text: ( appearance().brand_name || 'Postelio' ) } ) ] ) );
			} else if ( url ) {
				var img = el( 'img', { alt: '' } ); img.src = url;
				img.addEventListener( 'load', function () { if ( img.naturalWidth > 1 ) { m.w = img.naturalWidth; m.h = img.naturalHeight; mediaMeta[ url ] = m; metaText(); } } );
				preview.appendChild( img );
			} else {
				preview.appendChild( el( 'span', { class: 'sb-media__badge', text: 'Média de la bibliothèque' } ) );
			}
			var name = el( 'div', { class: 'sb-media__name', text: url ? basename( url ) : 'Média n° ' + v } );
			metaText();
			var actions = el( 'div', { class: 'sb-media__actions' }, [
				el( 'button', { class: 'bo-btn bo-btn--sm', type: 'button', text: 'Remplacer', onclick: function () { openMedia( path, fdef, paint, status, true ); } } ),
				el( 'button', { class: 'bo-btn bo-btn--sm bo-btn--ghost', type: 'button', text: 'Supprimer', onclick: function () { setPath( path, '' ); paint(); markDirty(); notify( isVideo ? 'Vidéo retirée' : 'Média retiré' ); } } ),
				( hasDefault && v !== fdef.default ) ? el( 'button', { class: 'bo-btn bo-btn--sm bo-btn--ghost', type: 'button', text: 'Restaurer', title: 'Restaurer la valeur Postelio par défaut', onclick: function () { setPath( path, fdef.default || '' ); paint(); markDirty(); notify( 'Valeur par défaut restaurée' ); } } ) : null
			] );
			var body = el( 'div', { class: 'sb-media__body' }, [ name, meta, actions ] );
			if ( m.heavy ) { body.appendChild( el( 'p', { class: 'sb-media__warn', text: 'Fichier lourd (plus de 15 Mo) : la page se chargera plus lentement. Une version compressée est recommandée.' } ) ); }
			card.appendChild( preview ); card.appendChild( body );
		}
		paint();
		return el( 'div', { class: 'sb-field sb-field--block' }, [
			el( 'div', { class: 'sb-field__head' }, [ el( 'span', { class: 'sb-field__label', text: label( fdef ) } ) ] ),
			card,
			help( fdef ) ? el( 'p', { class: 'sb-field__help', text: help( fdef ) } ) : null,
			status
		] );
	}

	function openMedia( path, fdef, paint, status, replacing ) {
		if ( ! window.wp || ! window.wp.media ) { notify( 'Médiathèque WordPress indisponible.', 'error' ); return; }
		var opts = { title: fdef.media_type === 'video' ? 'Choisir une vidéo' : 'Choisir un média', multiple: false };
		if ( fdef.media_type ) { opts.library = { type: fdef.media_type }; }
		var frame = window.wp.media( opts );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var e = ( att.url || '' ).split( '.' ).pop().toLowerCase().split( '?' )[ 0 ];
			if ( Array.isArray( fdef.accept ) && fdef.accept.indexOf( e ) < 0 ) {
				status.hidden = false;
				status.textContent = 'Format non pris en charge (' + e.toUpperCase() + '). Formats acceptés : ' + fdef.accept.join( ', ' ).toUpperCase() + '.';
				return;
			}
			setPath( path, att.url );
			mediaMeta[ att.url ] = {
				size: att.filesizeHumanReadable || '',
				heavy: !! ( att.filesizeInBytes && att.filesizeInBytes > 15 * 1024 * 1024 ),
				w: att.width || 0, h: att.height || 0
			};
			status.hidden = true; status.textContent = '';
			paint(); markDirty();
			notify( replacing ? 'Média remplacé' : 'Média ajouté' );
		} );
		frame.open();
	}

	// ------------------------------------------------------------ répéteur (lignes compactes → édition dépliée)
	function repeaterField( fdef, path ) {
		var rows = getPath( path );
		if ( ! Array.isArray( rows ) ) { rows = []; setPath( path, rows ); }
		var wrap = el( 'div', { class: 'sb-rep' } );
		var openIndex = -1;
		var keys = Object.keys( fdef.fields );

		function rebuild() {
			wrap.textContent = '';
			if ( ! rows.length ) { wrap.appendChild( el( 'p', { class: 'sb-rep__empty', text: 'Aucun élément pour le moment.' } ) ); }
			rows.forEach( function ( row, i ) { wrap.appendChild( repRow( i ) ); } );
			wrap.appendChild( el( 'button', { class: 'sb-rep__add', type: 'button', onclick: function () {
				var blank = {}; keys.forEach( function ( sk ) { blank[ sk ] = fdef.fields[ sk ].type === 'toggle' ? true : ''; } );
				rows.push( blank ); openIndex = rows.length - 1; markDirty(); rebuild();
			} }, [ svg( 'plus' ), el( 'span', { text: addLabel( fdef ) } ) ] ) );
			openIndex = -1;
		}
		function repRow( i ) {
			var row = rows[ i ] || {};
			// Ligne fermée : 1er champ = titre ; champs suivants = résumé « /offres · Tout le monde ».
			var title = ( keys[ 0 ] && row[ keys[ 0 ] ] ) ? String( row[ keys[ 0 ] ] ) : ( 'Élément ' + ( i + 1 ) );
			var sub = keys.slice( 1 ).map( function ( sk ) {
				var def = fdef.fields[ sk ] || {}, v = row[ sk ];
				if ( v === '' || v == null ) { return ''; }
				if ( def.type === 'select' ) { return ( def.options && def.options[ v ] ) || String( v ); }
				if ( def.type === 'toggle' ) { return label( def ) + ' : ' + ( v ? 'Oui' : 'Non' ); }
				if ( def.type === 'media' ) { return basename( v ); }
				return String( v ).split( '\n' )[ 0 ];
			} ).filter( Boolean ).join( ' · ' );
			var fieldsWrap = el( 'div', { class: 'sb-fields' } );
			keys.forEach( function ( sk ) { fieldsWrap.appendChild( fieldRow( sk, fdef.fields[ sk ], path.concat( [ i, sk ] ) ) ); } );
			function move( to ) { rows.splice( to, 0, rows.splice( i, 1 )[ 0 ] ); markDirty(); rebuild(); }
			var tools = el( 'div', { class: 'sb-rep__tools' }, [
				iconBtn( 'up', 'Monter', i === 0 ? 'is-disabled' : '', function ( e ) { e.stopPropagation(); if ( i > 0 ) { move( i - 1 ); } } ),
				iconBtn( 'down', 'Descendre', i === rows.length - 1 ? 'is-disabled' : '', function ( e ) { e.stopPropagation(); if ( i < rows.length - 1 ) { move( i + 1 ); } } ),
				iconBtn( 'close', 'Supprimer', 'sb-iconbtn--danger', function ( e ) { e.stopPropagation(); rows.splice( i, 1 ); markDirty(); rebuild(); notify( 'Élément supprimé' ); } )
			] );
			var id = 'pst-rep-' + ( ++uid );
			var card = el( 'div', { class: 'sb-rep__row' + ( i === openIndex ? ' is-open' : '' ) } );
			var head = el( 'div', { class: 'sb-rep__head', role: 'button', tabindex: '0', 'aria-expanded': i === openIndex ? 'true' : 'false', 'aria-controls': id }, [
				el( 'span', { class: 'sb-rep__index', text: String( i + 1 ) } ),
				el( 'div', { class: 'sb-rep__main' }, [ el( 'span', { class: 'sb-rep__title', text: title } ), sub ? el( 'span', { class: 'sb-rep__sub', text: sub } ) : null ] ),
				tools,
				el( 'span', { class: 'sb-rep__chevron' }, [ svg( 'chevron' ) ] )
			] );
			function toggle() { var open = ! card.classList.contains( 'is-open' ); card.classList.toggle( 'is-open', open ); head.setAttribute( 'aria-expanded', open ? 'true' : 'false' ); }
			head.addEventListener( 'click', function ( e ) { if ( e.target.closest( 'button' ) ) { return; } toggle(); } );
			head.addEventListener( 'keydown', function ( e ) { if ( e.target !== head ) { return; } if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); toggle(); } } );
			card.appendChild( head );
			card.appendChild( el( 'div', { class: 'sb-rep__fields', id: id }, [ fieldsWrap ] ) );
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
			if ( ! ids.length ) { list.appendChild( el( 'p', { class: 'sb-rep__empty', text: 'Aucun contenu sélectionné. Recherchez ci-dessous pour en ajouter.' } ) ); return; }
			ids.forEach( function ( id, i ) {
				var item = resolveCache[ type ][ id ], missing = item && item.missing;
				list.appendChild( el( 'div', { class: 'sb-coll__item' + ( missing ? ' is-missing' : '' ) }, [
					el( 'div', { class: 'sb-coll__thumb', text: missing ? '!' : ( item && item.label ? item.label.trim().charAt( 0 ).toUpperCase() : '…' ) } ),
					el( 'div', { class: 'sb-coll__main' }, [
						el( 'div', { class: 'sb-coll__label', text: item ? ( missing ? 'Contenu indisponible' : ( item.label || 'Contenu' ) ) : 'Chargement…' } ),
						el( 'div', { class: 'sb-coll__sub', text: item && ! missing ? ( item.sub || '' ) : ( missing ? 'Ce contenu n’existe plus ou n’est plus publié.' : '' ) } )
					] ),
					( item && ! missing && item.state ) ? el( 'span', { class: 'sb-chip sb-chip--on', text: item.state } ) : null,
					el( 'div', { class: 'sb-rep__tools' }, [
						iconBtn( 'up', 'Monter', i === 0 ? 'is-disabled' : '', function () { if ( i > 0 ) { ids.splice( i - 1, 0, ids.splice( i, 1 )[ 0 ] ); markDirty(); render(); } } ),
						iconBtn( 'down', 'Descendre', i === ids.length - 1 ? 'is-disabled' : '', function () { if ( i < ids.length - 1 ) { ids.splice( i + 1, 0, ids.splice( i, 1 )[ 0 ] ); markDirty(); render(); } } ),
						iconBtn( 'close', 'Retirer', 'sb-iconbtn--danger', function () { ids.splice( i, 1 ); markDirty(); render(); } )
					] )
				] ) );
			} );
		}
		function searchBox() {
			var input = el( 'input', { class: 'sb-input', type: 'search', placeholder: 'Rechercher un contenu à ajouter…', 'aria-label': 'Rechercher un contenu à ajouter' } );
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
					results.appendChild( el( 'button', { type: 'button', class: 'sb-coll__result' + ( already ? ' is-added' : '' ), onclick: function () {
						if ( ! already && it.id ) {
							resolveCache[ type ][ it.id ] = { label: it.label, sub: it.sub, state: it.state, missing: false };
							ids.push( it.id ); markDirty(); render(); input.value = ''; results.classList.remove( 'is-open' ); notify( 'Contenu ajouté à la sélection' );
						}
					} }, [ el( 'b', { text: it.label + ( already ? ' (déjà sélectionné)' : '' ) } ), el( 'span', { text: [ it.sub, it.state ].filter( Boolean ).join( ' · ' ) } ) ] ) );
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

	// ============================================================ APERÇU (VRAI FRONT en iframe — mécanique inchangée)
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
		s.appendChild( el( 'button', { class: 'bo-btn bo-btn--sm', type: 'button', text: 'Réessayer', onclick: refreshPreview } ) );
	}

	function refreshPreview() { iframeEl = null; renderPreview(); }

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

	/** Aperçu éditorial SEO (extrait Google + carte de partage), UNIQUEMENT à partir des champs réels. */
	function buildSEO() {
		var g = state.global || {};
		var key = state[ activeSeo ] ? activeSeo : 'home';
		var s = state[ key ] || {};
		var lbl = ( CFG.schema.sections[ key ] && CFG.schema.sections[ key ].label ) || 'Accueil';
		var siteName = g.site_name || 'Postelio';
		var title = s.seo_title || ( ( g.title_template || '%page% — Postelio' ).replace( '%page%', lbl ) );
		var desc = s.meta_description || g.default_description || '';
		var img = s.social_image || g.default_social_image || '';
		var url = ( CFG.frontUrl || 'https://exemple.fr/' ).replace( /\/$/, '' ) + ( SEO_PATHS[ key ] || '/' );
		var ogImg = el( 'div', { class: 'sb-og__img' } );
		if ( mediaUrl( img ) ) { ogImg.style.backgroundImage = 'url(' + mediaUrl( img ) + ')'; } else { ogImg.textContent = 'Aucune image de partage'; }
		return el( 'section', { class: 'sb-seo' }, [
			el( 'p', { class: 'sb-seo__label', text: 'Extrait Google · ' + lbl } ),
			el( 'div', { class: 'sb-serp' }, [
				el( 'div', { class: 'sb-serp__url' }, [ document.createTextNode( siteName + ' ' ), el( 'small', { text: url } ) ] ),
				el( 'div', { class: 'sb-serp__title', text: title } ),
				el( 'div', { class: 'sb-serp__desc', text: desc || 'Ajoutez une description pour contrôler cet extrait.' } )
			] ),
			el( 'p', { class: 'sb-seo__label', text: 'Carte de partage (réseaux sociaux)' } ),
			el( 'div', { class: 'sb-og' }, [ ogImg, el( 'div', { class: 'sb-og__body' }, [
				el( 'div', { class: 'sb-og__site', text: siteName } ),
				el( 'div', { class: 'sb-og__title', text: s.social_title || title } ),
				el( 'div', { class: 'sb-og__desc', text: s.social_description || desc || '' } )
			] ) ] ),
			el( 'p', { class: 'sb-preview__hint', text: 'Ouvrez une page à gauche pour voir son extrait. Aperçu éditorial : il ne reflète ni le classement ni l’indexation réels.' } )
		] );
	}

	// ============================================================ ÉTAT DE SAUVEGARDE (réel : dirty → saving → saved / error)
	function saveState( kind, msg ) {
		var status = document.getElementById( 'pst-bo-status' );
		var bar = document.getElementById( 'pst-bo-savebar' );
		var headBtn = document.getElementById( 'pst-bo-save' );
		var barBtn = document.getElementById( 'pst-bo-savebar-save' );
		var texts = { clean: 'Enregistré', dirty: 'Modifications non enregistrées', saving: 'Enregistrement…', saved: 'Modifications enregistrées', error: msg || 'L’enregistrement a échoué' };
		if ( status ) { status.className = 'sb-status sb-status--' + kind; status.textContent = ''; status.appendChild( svg( kind === 'error' ? 'close' : ( kind === 'dirty' ? 'pending' : 'check' ) ) ); status.appendChild( el( 'span', { text: texts[ kind ] } ) ); }
		if ( headBtn ) { headBtn.disabled = ( kind === 'clean' || kind === 'saved' || kind === 'saving' ); headBtn.textContent = kind === 'saving' ? 'Enregistrement…' : 'Enregistrer'; }
		if ( barBtn ) { barBtn.disabled = kind === 'saving'; barBtn.textContent = kind === 'saving' ? 'Enregistrement…' : 'Enregistrer'; }
		if ( bar ) {
			bar.classList.toggle( 'is-visible', kind === 'dirty' || kind === 'saving' || kind === 'error' );
			bar.classList.toggle( 'is-error', kind === 'error' );
			var m = bar.querySelector( '.sb-savebar__msg' );
			if ( m ) { m.textContent = ''; m.appendChild( document.createTextNode( texts[ kind ] ) ); if ( kind === 'error' ) { m.appendChild( el( 'small', { text: 'Vos modifications sont conservées. Réessayez.' } ) ); } }
		}
	}

	function save() {
		if ( saving || ! dirty ) { return; }
		saving = true; saveState( 'saving' );
		fetch( CFG.saveUrl, {
			method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.restNonce }, credentials: 'same-origin',
			body: JSON.stringify( { values: state } )
		} ).then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, j: j }; } ); } )
		.then( function ( res ) {
			saving = false;
			if ( res.ok && res.j && res.j.data ) {
				state = clone( res.j.data.values || state );
				CFG.values = clone( state );
				dirty = false; saveState( 'saved' ); notify( 'Modifications enregistrées' );
				renderEditor(); renderPreview();
				setTimeout( function () { if ( ! dirty ) { saveState( 'clean' ); } }, 2500 );
			} else {
				saveState( 'error', ( res.j && res.j.error && res.j.error.message ) || 'L’enregistrement a échoué' );
				notify( 'L’enregistrement a échoué', 'error' );
			}
		} ).catch( function () {
			saving = false;
			saveState( 'error', 'Erreur réseau : vérifiez votre connexion' );
			notify( 'Erreur réseau', 'error' );
		} );
	}

	// ============================================================ INIT
	function init() {
		var devs = document.getElementById( 'pst-bo-devices' );
		if ( FORCED_DEVICE ) {
			if ( devs ) { devs.hidden = true; }
			var lbl = document.getElementById( 'pst-bo-pvlabel' );
			if ( lbl ) { lbl.textContent = FORCED_DEVICE === 'mobile' ? 'Aperçu mobile' : ( FORCED_DEVICE === 'tablet' ? 'Aperçu tablette' : 'Aperçu' ); }
		} else if ( devs ) {
			Array.prototype.forEach.call( devs.querySelectorAll( 'button' ), function ( b ) {
				b.addEventListener( 'click', function () {
					device = b.getAttribute( 'data-device' );
					Array.prototype.forEach.call( devs.querySelectorAll( 'button' ), function ( x ) { var on = x === b; x.classList.toggle( 'is-active', on ); x.setAttribute( 'aria-pressed', on ? 'true' : 'false' ); } );
					renderPreview();
				} );
			} );
		}
		if ( CFG.page === 'seo' ) {
			if ( devs ) { devs.hidden = true; }
			var r0 = document.getElementById( 'pst-bo-refresh' ); if ( r0 ) { r0.hidden = true; }
			var l0 = document.getElementById( 'pst-bo-pvlabel' ); if ( l0 ) { l0.textContent = 'Aperçu des extraits'; }
		}
		var rf = document.getElementById( 'pst-bo-refresh' ); if ( rf ) { rf.addEventListener( 'click', refreshPreview ); }
		var s1 = document.getElementById( 'pst-bo-save' ); if ( s1 ) { s1.addEventListener( 'click', save ); }
		var s2 = document.getElementById( 'pst-bo-savebar-save' ); if ( s2 ) { s2.addEventListener( 'click', save ); }
		var c = document.getElementById( 'pst-bo-cancel' ); if ( c ) { c.addEventListener( 'click', function () { state = clone( CFG.values || {} ); dirty = false; saveState( 'clean' ); renderEditor(); renderPreview(); notify( 'Modifications annulées' ); } ); }
		window.addEventListener( 'beforeunload', function ( e ) { if ( dirty ) { e.preventDefault(); e.returnValue = ''; } } );
		document.addEventListener( 'keydown', function ( e ) { if ( ( e.ctrlKey || e.metaKey ) && String( e.key ).toLowerCase() === 's' ) { e.preventDefault(); save(); } } );
		var open = document.getElementById( 'pst-bo-pvopen' ); if ( open ) { open.href = window.location.origin + ( FRONT_ROUTES[ CFG.page ] || '/index.html' ); }

		saveState( 'clean' );
		wirePreview();
		renderEditor();
		renderPreview();
	}

	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', init ); } else { init(); }
} )();
