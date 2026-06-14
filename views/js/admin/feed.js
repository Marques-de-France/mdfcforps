/**
 * Marques de France — BO Product Feed JS
 *
 * Features:
 *  - Manage mode panel: searchable, paginated product table with checkboxes
 *  - Per-row toggle (add/remove from feed) with optimistic UI
 *  - Select-all visible rows via bulk endpoints
 *  - Remove product from current-feed table (existing behaviour)
 */
( function () {
	'use strict';

	// -----------------------------------------------------------------------
	// State
	// -----------------------------------------------------------------------

	var state = {
		search:   '',
		page:     1,
		perPage:  10,
		products: [],
		total:    0,
		loading:  false,
	};

	var searchTimer = null;
	var adminUrl    = '';
	var i18n        = {};

	function t( key, fallback ) {
		if ( i18n && typeof i18n[ key ] === 'string' && i18n[ key ].length > 0 ) {
			return i18n[ key ];
		}

		return fallback;
	}

	function tf( key, fallback, vars ) {
		var text = t( key, fallback );
		if ( !vars ) {
			return text;
		}

		Object.keys( vars ).forEach( function ( varKey ) {
			text = text.replace( new RegExp( '\\{' + varKey + '\\}', 'g' ), String( vars[ varKey ] ) );
		} );

		return text;
	}

	// -----------------------------------------------------------------------
	// HTTP helpers
	// -----------------------------------------------------------------------

	function postAction( params ) {
		var config = window.mdfcforpsFeed;
		var url    = config && config.toggleUrl ? config.toggleUrl : '';
		var body = new URLSearchParams( params );

		return fetch( url, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:        body.toString(),
		} ).then( function ( r ) { return r.json(); } );
	}

	/** POST with FormData to support product_ids[] array fields */
	function postFormData( params ) {
		var config = window.mdfcforpsFeed;
		var url    = config && config.bulkUrl ? config.bulkUrl : '';
		var form = new FormData();

		Object.keys( params ).forEach( function ( k ) {
			var v = params[ k ];
			if ( Array.isArray( v ) ) {
				v.forEach( function ( item ) { form.append( k + '[]', item ); } );
			} else {
				form.append( k, v );
			}
		} );

		return fetch( url, {
			method:      'POST',
			credentials: 'same-origin',
			body:        form,
		} ).then( function ( r ) { return r.json(); } );
	}

	// -----------------------------------------------------------------------
	// Fetch products for manage panel
	// -----------------------------------------------------------------------

	function fetchProducts() {
		if ( state.loading ) return;
		state.loading = true;
		setSpinner( true );

		postAction( {
			mdf_search_products: '1',
			search:              state.search,
			page:                String( state.page ),
			per_page:            String( state.perPage ),
		} )
		.then( function ( json ) {
			state.products = json.products || [];
			state.total    = json.total    || 0;
			renderProductTable();
			renderPagination();
		} )
		.catch( function () {
			var tbody = document.getElementById( 'mdf-manage-tbody' );
			if ( tbody ) {
				tbody.innerHTML =
					'<tr><td colspan="8" class="text-center text-danger py-3">' + t( 'errorLoadingProducts', 'Error loading products.' ) + '</td></tr>';
			}
		} )
		.then( function () {
			// always runs (polyfill for .finally)
			state.loading = false;
			setSpinner( false );
		} );
	}

	// -----------------------------------------------------------------------
	// Render helpers
	// -----------------------------------------------------------------------

	function setSpinner( show ) {
		var el = document.getElementById( 'mdf-manage-spinner' );
		if ( el ) el.style.display = show ? 'block' : 'none';
	}

	function escHtml( str ) {
		var d = document.createElement( 'div' );
		d.appendChild( document.createTextNode( str || '' ) );
		return d.innerHTML;
	}

	function renderProductTable() {
		var tbody = document.getElementById( 'mdf-manage-tbody' );
		if ( !tbody ) return;

		if ( !state.products.length ) {
			tbody.innerHTML =
				'<tr><td colspan="8" class="text-center text-muted py-3">' +
				( state.search ? t( 'noProductsFound', 'No products found.' ) : t( 'noProductsInCatalog', 'No products in your catalog.' ) ) +
				'</td></tr>';
			updateSelectAll();
			return;
		}

		var rows = state.products.map( function ( p ) {
			var checked  = p.in_feed ? ' checked' : '';
			var imgHtml  = p.image
				? '<img src="' + escHtml( p.image ) + '" width="40" height="40"'
				  + ' style="object-fit:cover;border-radius:3px;" loading="lazy">'
				: '<span style="display:inline-block;width:40px;height:40px;background:#eee;border-radius:3px;"></span>';

			return '<tr data-product-id="' + p.id + '" data-in-feed="' + ( p.in_feed ? '1' : '0' ) + '">'
				+ '<td class="text-center align-middle">'
				+ '<input type="checkbox" class="mdf-manage-cb"' + checked + '>'
				+ '</td>'
				+ '<td class="align-middle">' + imgHtml + '</td>'
				+ '<td class="align-middle">'
				+ escHtml( p.name )
				+ '<br><small class="text-muted">#' + p.id + '</small>'
				+ '</td>'
				+ '<td class="align-middle">' + escHtml( p.brand || '' ) + '</td>'
				+ '<td class="align-middle text-muted">' + escHtml( p.reference ) + '</td>'
				+ '<td class="align-middle">' + escHtml( p.availability || '' ) + '</td>'
				+ '<td class="align-middle">' + escHtml( p.price ) + '</td>'
				+ '<td class="align-middle">' + escHtml( p.status || '' ) + '</td>'
				+ '</tr>';
		} );

		tbody.innerHTML = rows.join( '' );
		updateSelectAll();
	}

	function renderPagination() {
		var container  = document.getElementById( 'mdf-manage-pagination' );
		if ( !container ) return;

		var totalPages = Math.ceil( state.total / state.perPage ) || 1;
		if ( totalPages <= 1 ) {
			container.innerHTML =
				'<small class="text-muted">' + tf( 'productsCount', '{count} product(s)', { count: state.total } ) + '</small>';
			return;
		}

		var items = '';

		items += '<li class="page-item' + ( state.page <= 1 ? ' disabled' : '' ) + '">'
			+ '<a class="page-link" href="#" data-page="' + ( state.page - 1 ) + '">&laquo;</a></li>';

		var from = Math.max( 1, state.page - 3 );
		var to   = Math.min( totalPages, state.page + 3 );
		for ( var i = from; i <= to; i++ ) {
			items += '<li class="page-item' + ( i === state.page ? ' active' : '' ) + '">'
				+ '<a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
		}

		items += '<li class="page-item' + ( state.page >= totalPages ? ' disabled' : '' ) + '">'
			+ '<a class="page-link" href="#" data-page="' + ( state.page + 1 ) + '">&raquo;</a></li>';

		container.innerHTML =
			'<ul class="pagination pagination-sm mb-0">' + items + '</ul>'
			+ '<small class="text-muted ml-3">' + tf( 'pageSummary', 'Page {page} of {total} - {count} products', {
				page: state.page,
				total: totalPages,
				count: state.total,
			} ) + '</small>';
	}

	function updateSelectAll() {
		var selectAll = document.getElementById( 'mdf-manage-select-all' );
		if ( !selectAll ) return;

		var all     = document.querySelectorAll( '#mdf-manage-tbody .mdf-manage-cb' );
		var checked = document.querySelectorAll( '#mdf-manage-tbody .mdf-manage-cb:checked' );

		selectAll.checked       = all.length > 0 && checked.length === all.length;
		selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
	}

	// -----------------------------------------------------------------------
	// Open / Close panel
	// -----------------------------------------------------------------------

	function openPanel() {
		var panel   = document.getElementById( 'mdf-manage-panel' );
		var openBtn = document.getElementById( 'mdf-open-manage-btn' );
		if ( panel )   panel.style.display   = 'block';
		if ( openBtn ) openBtn.style.display  = 'none';

		state.page   = 1;
		state.search = '';

		var searchInput = document.getElementById( 'mdf-manage-search' );
		if ( searchInput ) searchInput.value = '';

		fetchProducts();
	}

	function closePanel() {
		var panel   = document.getElementById( 'mdf-manage-panel' );
		var openBtn = document.getElementById( 'mdf-open-manage-btn' );
		if ( panel )   panel.style.display   = 'none';
		if ( openBtn ) openBtn.style.display  = '';

		// Reload page so current-feed table reflects changes
		window.location.reload();
	}

	// -----------------------------------------------------------------------
	// Toggle single row
	// -----------------------------------------------------------------------

	function toggleProduct( cb ) {
		// Data attributes may be on the checkbox (PS Grid) or on the row (legacy table)
		var row       = cb.closest( 'tr' );
		var productId = cb.getAttribute( 'data-product-id' )
			|| ( row && row.getAttribute( 'data-product-id' ) );
		var inFeed    = ( cb.getAttribute( 'data-in-feed' )
			|| ( row && row.getAttribute( 'data-in-feed' ) ) ) === '1';
		var params = {
			action:     inFeed ? 'remove' : 'add',
			product_id: productId,
		};

		cb.disabled = true;

		postAction( params )
		.then( function ( json ) {
			if ( json.success ) {
				var nowInFeed = !inFeed;
				cb.setAttribute( 'data-in-feed', nowInFeed ? '1' : '0' );
				if ( row ) row.setAttribute( 'data-in-feed', nowInFeed ? '1' : '0' );
				cb.checked = nowInFeed;
				state.products.forEach( function ( p ) {
					if ( String( p.id ) === String( productId ) ) p.in_feed = nowInFeed;
				} );
			} else {
				cb.checked = inFeed; // revert
			}
		} )
		.catch( function () { cb.checked = inFeed; } )
		.then( function () {
			cb.disabled = false;
			updateSelectAll();
		} );
	}

	// -----------------------------------------------------------------------
	// Select-all: bulk toggle
	// -----------------------------------------------------------------------

	function toggleSelectAll( checked ) {
		var rows     = document.querySelectorAll( '#mdf-manage-tbody tr[data-product-id]' );
		var toAdd    = [];
		var toRemove = [];

		rows.forEach( function ( row ) {
			var pid    = row.getAttribute( 'data-product-id' );
			var inFeed = row.getAttribute( 'data-in-feed' ) === '1';
			if ( checked  && !inFeed ) toAdd.push( pid );
			if ( !checked && inFeed  ) toRemove.push( pid );
		} );

		var promises = [];

		if ( toAdd.length ) {
			promises.push(
				postFormData( { action: 'add', product_ids: toAdd } )
				.then( function () {
					toAdd.forEach( function ( pid ) {
						var r  = document.querySelector( '#mdf-manage-tbody tr[data-product-id="' + pid + '"]' );
						if ( r ) {
							r.setAttribute( 'data-in-feed', '1' );
							var c = r.querySelector( '.mdf-manage-cb' );
							if ( c ) c.checked = true;
						}
						state.products.forEach( function ( p ) {
							if ( String( p.id ) === pid ) p.in_feed = true;
						} );
					} );
				} )
			);
		}

		if ( toRemove.length ) {
			promises.push(
				postFormData( { action: 'remove', product_ids: toRemove } )
				.then( function () {
					toRemove.forEach( function ( pid ) {
						var r  = document.querySelector( '#mdf-manage-tbody tr[data-product-id="' + pid + '"]' );
						if ( r ) {
							r.setAttribute( 'data-in-feed', '0' );
							var c = r.querySelector( '.mdf-manage-cb' );
							if ( c ) c.checked = false;
						}
						state.products.forEach( function ( p ) {
							if ( String( p.id ) === pid ) p.in_feed = false;
						} );
					} );
				} )
			);
		}

		Promise.all( promises ).then( updateSelectAll );
	}

	// -----------------------------------------------------------------------
	// Event binding
	// -----------------------------------------------------------------------

	function init() {
		var config = window.mdfcforpsFeed;
		adminUrl = ( config && config.adminUrl ) ? config.adminUrl : '';
		i18n = ( config && config.i18n ) ? config.i18n : {};

		// ---- PS Grid listeners (always active, no adminUrl needed) --------

		// Checkbox toggle — PS Grid manage panel (document-level delegation)
		document.addEventListener( 'change', function ( e ) {
			var cb = e.target;
			if ( !cb.classList.contains( 'mdf-manage-cb' ) ) return;
			// Skip if handled by legacy tbody listener
			var legacyTbody = document.getElementById( 'mdf-manage-tbody' );
			if ( legacyTbody && legacyTbody.contains( cb ) ) return;
			toggleProduct( cb );
		} );

		// Remove-product button (PS Grid or legacy feed table)
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.mdf-remove-product' );
			if ( !btn ) return;
			var productId = parseInt( btn.getAttribute( 'data-product-id' ), 10 );
			if ( !productId ) return;
			if ( !window.confirm( t( 'confirmRemoveProduct', 'Remove this product from the feed?' ) ) ) return;
			postAction( { action: 'remove', product_id: String( productId ) } )
			.then( function ( json ) {
				if ( json.success ) {
					var row = btn.closest( 'tr' );
					if ( row ) row.remove();
				} else {
					alert( json.error || t( 'errorRemovingProduct', 'Error removing product.' ) );
				}
			} )
			.catch( function () { alert( t( 'networkErrorRetry', 'Network error. Please try again.' ) ); } );
		} );

		// ---- Legacy manage panel listeners (only when adminUrl is available) --
		if ( !adminUrl ) return;

		// Open panel button
		var openBtn = document.getElementById( 'mdf-open-manage-btn' );
		if ( openBtn ) {
			openBtn.addEventListener( 'click', openPanel );
		}

		// Done / close button
		var doneBtn = document.getElementById( 'mdf-manage-done-btn' );
		if ( doneBtn ) {
			doneBtn.addEventListener( 'click', closePanel );
		}

		// Search input (debounced 300ms)
		var searchInput = document.getElementById( 'mdf-manage-search' );
		if ( searchInput ) {
			searchInput.addEventListener( 'input', function () {
				clearTimeout( searchTimer );
				searchTimer = setTimeout( function () {
					state.search = searchInput.value.trim();
					state.page   = 1;
					fetchProducts();
				}, 300 );
			} );
		}

		// Clear search button
		var clearBtn = document.getElementById( 'mdf-manage-search-clear' );
		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				if ( searchInput ) searchInput.value = '';
				state.search = '';
				state.page   = 1;
				fetchProducts();
			} );
		}

		// Select-all checkbox
		var selectAll = document.getElementById( 'mdf-manage-select-all' );
		if ( selectAll ) {
			selectAll.addEventListener( 'change', function () {
				toggleSelectAll( this.checked );
			} );
		}

		// Pagination (delegated)
		var pagination = document.getElementById( 'mdf-manage-pagination' );
		if ( pagination ) {
			pagination.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var link = e.target.closest( '[data-page]' );
				if ( !link ) return;
				var p     = parseInt( link.getAttribute( 'data-page' ), 10 );
				var total = Math.ceil( state.total / state.perPage ) || 1;
				if ( p < 1 || p > total ) return;
				state.page = p;
				fetchProducts();
			} );
		}

		// Row checkbox toggle — legacy manage panel (tbody scoped)
		var tbody = document.getElementById( 'mdf-manage-tbody' );
		if ( tbody ) {
			tbody.addEventListener( 'change', function ( e ) {
				if ( !e.target.classList.contains( 'mdf-manage-cb' ) ) return;
				toggleProduct( e.target );
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

