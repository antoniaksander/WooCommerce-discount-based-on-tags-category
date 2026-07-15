( function () {
	document.addEventListener( 'click', function ( event ) {
		if ( event.target.id === 'add-rule-row' ) {
			var table = document.querySelector( '#wc-tag-discount-rules-table tbody' );
			var lastRow = table.querySelector( '.rule-row:last-child' );
			var newRow = lastRow.cloneNode( true );

			newRow.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.value = '';
			} );

			table.appendChild( newRow );
		}

		if ( event.target.classList.contains( 'remove-rule-row' ) ) {
			var rows = document.querySelectorAll( '.rule-row' );
			if ( rows.length > 1 ) {
				event.target.closest( '.rule-row' ).remove();
			}
		}
	} );
} )();
