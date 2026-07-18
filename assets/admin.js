( function ( $ ) {
	'use strict';

	if ( typeof $ === 'undefined' || typeof wc_enhanced_select_params === 'undefined' ) {
		return;
	}

	function currentTaxonomy( $select ) {
		return $select.closest( '.rule-row-grid' ).find( 'select.rule-taxonomy-select' ).val();
	}

	function initTermSearch() {
		$( 'select.wc-tag-discount-term-search' ).each( function () {
			var $select = $( this );

			if ( $select.hasClass( 'enhanced' ) || typeof $.fn.selectWoo === 'undefined' ) {
				return;
			}

			$select.selectWoo( {
				allowClear: true,
				placeholder: $select.data( 'placeholder' ) || '',
				minimumInputLength: 1,
				tags: true,
				escapeMarkup: function ( m ) {
					return m;
				},
				ajax: {
					url: wc_enhanced_select_params.ajax_url,
					dataType: 'json',
					delay: 250,
					data: function ( params ) {
						return {
							term: params.term,
							taxonomy: currentTaxonomy( $select ),
							action: 'woocommerce_json_search_taxonomy_terms',
							security: wc_enhanced_select_params.search_taxonomy_terms_nonce
						};
					},
					processResults: function ( data ) {
						var terms = [];
						$.each( data || {}, function ( id, term ) {
							terms.push( { id: term.slug, text: term.name } );
						} );
						return { results: terms };
					},
					cache: true
				}
			} ).addClass( 'enhanced' );
		} );
	}

	$( function () {
		initTermSearch();
	} );
} )( jQuery );
