/**
 * Back-office Postelio — interactions minimales des écrans serveur (aucun framework) :
 * confirmation des actions sensibles (`data-bo-confirm` sur un <form> ou un lien).
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
		}
	}, true );
} )();
