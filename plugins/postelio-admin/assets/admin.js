/**
 * Back-office Postelio — interactions minimales (aucun framework).
 * Confirmation des actions sensibles (attribut data-pst-confirm sur un <form> ou un lien).
 */
( function () {
	'use strict';
	document.addEventListener( 'submit', function ( e ) {
		var f = e.target;
		if ( f && f.matches && f.matches( '[data-pst-confirm]' ) ) {
			if ( ! window.confirm( f.getAttribute( 'data-pst-confirm' ) ) ) {
				e.preventDefault();
			}
		}
	}, true );
	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( 'a[data-pst-confirm]' ) : null;
		if ( a && ! window.confirm( a.getAttribute( 'data-pst-confirm' ) ) ) {
			e.preventDefault();
		}
	}, true );
} )();
