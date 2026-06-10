/**
 * Marques de France — BO Dashboard JS
 *
 * Renders the revenue chart using Chart.js (bundled as chart.min.js).
 * Reads window.mdfcforpsAdmin.analyticsData populated by the Smarty template.
 */
( function () {
	'use strict';

	function init() {
		var config   = window.mdfcforpsAdmin;
		var canvas   = document.getElementById( 'mdf-revenue-chart' );

		if ( !config || !canvas ) {
			return;
		}

		var data = config.analyticsData || {};
		var labels    = data.months || [];
		var revenues  = data.revenues || [];
		var salesCounts = data.salesCounts || [];

		if ( typeof Chart === 'undefined' || labels.length === 0 ) {
			return;
		}

		// eslint-disable-next-line no-new
		new Chart( canvas.getContext( '2d' ), {
			type: 'line',
			data: {
				labels: labels,
				datasets: [
					{
						label: 'Revenue (EUR)',
						data:  revenues,
						borderColor: '#051440',
						backgroundColor: 'rgba(5,20,64,0.1)',
						borderWidth: 2,
						fill: true,
						tension: 0.3,
						yAxisID: 'y',
					},
					{
						label: 'Sales',
						data:  salesCounts,
						borderColor: '#ed2e38',
						backgroundColor: 'transparent',
						borderWidth: 2,
						fill: false,
						tension: 0.3,
						yAxisID: 'y1',
					},
				],
			},
			options: {
				responsive: true,
				interaction: { mode: 'index', intersect: false },
				scales: {
					y: {
						type: 'linear',
						position: 'left',
						title: { display: true, text: 'Revenue (EUR)' },
					},
					y1: {
						type: 'linear',
						position: 'right',
						grid: { drawOnChartArea: false },
						title: { display: true, text: 'Sales' },
					},
				},
			},
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
