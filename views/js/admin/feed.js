/**
 * Marques de France — BO Product Feed JS
 *
 * Handles:
 *  - Add product to SERVERLIST via AJAX
 *  - Remove product from SERVERLIST via AJAX
 */
( function () {
	'use strict';

	function getAdminUrl() {
		var config = window.mdfcforpsFeed;
		return config && config.adminUrl ? config.adminUrl : '';
	}

	function postAction( adminUrl, params ) {
		var url = adminUrl + '&tab=feed';
		var body = new URLSearchParams( params );

		return fetch( url, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:        body.toString(),
		} )
		.then( function ( res ) { return res.json(); } );
	}

	function init() {
		var adminUrl = getAdminUrl();
		if ( !adminUrl ) return;

		var addBtn = document.getElementById( 'mdf-add-product-btn' );
		var addInput = document.getElementById( 'mdf-add-product-id' );

		if ( addBtn && addInput ) {
			addBtn.addEventListener( 'click', function () {
				var productId = parseInt( addInput.value, 10 );
				if ( !productId || productId <= 0 ) {
					alert( 'Please enter a valid product ID.' );
					return;
				}

				postAction( adminUrl, {
					mdf_add_product: '1',
					product_id:      String( productId ),
				} )
				.then( function ( json ) {
					if ( json.success ) {
						window.location.reload();
					} else {
						alert( json.error || 'Error adding product.' );
					}
				} )
				.catch( function () {
					alert( 'Network error. Please try again.' );
				} );
			} );
		}

		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.mdf-remove-product' );
			if ( !btn ) return;

			var productId = parseInt( btn.getAttribute( 'data-product-id' ), 10 );
			if ( !productId ) return;

			if ( !window.confirm( 'Remove this product from the feed?' ) ) return;

			postAction( adminUrl, {
				mdf_remove_product: '1',
				product_id:         String( productId ),
			} )
			.then( function ( json ) {
				if ( json.success ) {
					var row = btn.closest( 'tr' );
					if ( row ) row.remove();
				} else {
					alert( json.error || 'Error removing product.' );
				}
			} )
			.catch( function () {
				alert( 'Network error. Please try again.' );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
