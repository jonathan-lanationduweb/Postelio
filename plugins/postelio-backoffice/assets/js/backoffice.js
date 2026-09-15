/**
 * Back-office Postelio — interactions minimales des écrans serveur (aucun framework) :
 *  - confirmation des actions sensibles (`data-bo-confirm` sur un <form> ou un lien) ;
 *  - menus contextuels `<details class="bo-menu">` : un seul ouvert à la fois, fermeture au clic
 *    extérieur et à Échap.
 */
( function () {
	'use strict';

	document.addEventListener( 'submit', function ( e ) {
		var f = e.target;
		if ( f && f.matches && f.matches( '[data-bo-confirm]' ) && ! window.confirm( f.getAttribute( 'data-bo-confirm' ) ) ) {
			e.preventDefault();
		}
	}, true );

	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( 'a[data-bo-confirm]' ) : null;
		if ( a && ! window.confirm( a.getAttribute( 'data-bo-confirm' ) ) ) {
			e.preventDefault();
			return;
		}
		var inMenu = e.target.closest ? e.target.closest( 'details.bo-menu' ) : null;
		document.querySelectorAll( 'details.bo-menu[open]' ).forEach( function ( d ) {
			if ( d !== inMenu ) { d.removeAttribute( 'open' ); }
		} );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			document.querySelectorAll( 'details.bo-menu[open]' ).forEach( function ( d ) { d.removeAttribute( 'open' ); } );
		}
	} );
} )();
