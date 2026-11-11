/**
 * Ad Network stats charts: a two-series line chart (served, viewed) with a crosshair tooltip,
 * and a bar chart (clicks) with per-bar tooltips. Plain SVG, no libraries. The same numbers are
 * in the "Show as a table" view under the charts.
 */
( function () {
	'use strict';

	var SVG = 'http://www.w3.org/2000/svg';
	var COLORS = { serves: 'var(--adn-series-1)', views: 'var(--adn-series-2)', clicks: 'var(--adn-series-1)' };
	var PAD = { top: 12, right: 72, bottom: 28, left: 48 };

	var dataEl = document.getElementById( 'adn-chart-data' );
	var data = dataEl ? JSON.parse( dataEl.textContent ) : null;
	var fmt = new Intl.NumberFormat();

	// Show the custom date inputs only for "Custom…".
	var range = document.getElementById( 'adn-range' );
	var custom = document.querySelector( '.adn-custom-range' );
	if ( range && custom ) {
		range.addEventListener( 'change', function () {
			custom.hidden = 'custom' !== range.value;
		} );
	}

	if ( ! data || ! data.days || ! data.days.length ) {
		return;
	}

	function el( name, attrs, parent ) {
		var node = document.createElementNS( SVG, name );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			node.setAttribute( k, attrs[ k ] );
		} );
		if ( parent ) {
			parent.appendChild( node );
		}
		return node;
	}

	/** Round axis maximum and step: 1, 2 or 5 × 10^n. */
	function niceScale( max ) {
		if ( max <= 0 ) {
			return { max: 4, step: 1 };
		}
		var rough = max / 4;
		var pow = Math.pow( 10, Math.floor( Math.log10( rough ) ) );
		var step = [ 1, 2, 5, 10 ].map( function ( m ) {
			return m * pow;
		} ).filter( function ( s ) {
			return s >= rough;
		} )[ 0 ];
		step = Math.max( 1, step );
		return { max: Math.ceil( max / step ) * step, step: step };
	}

	function shortDate( day ) {
		var d = new Date( day + 'T12:00:00' );
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
	}

	function tooltip( host ) {
		var tip = document.createElement( 'div' );
		tip.className = 'adn-tooltip';
		tip.hidden = true;
		host.appendChild( tip );
		return tip;
	}

	function showTip( tip, host, x, y, day, rows ) {
		tip.textContent = '';
		var head = document.createElement( 'div' );
		head.className = 'adn-tooltip__day';
		head.textContent = shortDate( day );
		tip.appendChild( head );
		rows.forEach( function ( row ) {
			var line = document.createElement( 'div' );
			line.className = 'adn-tooltip__row';
			if ( row.color ) {
				var key = document.createElement( 'span' );
				key.className = 'adn-tooltip__key';
				key.style.background = row.color;
				line.appendChild( key );
			}
			var value = document.createElement( 'strong' );
			value.textContent = fmt.format( row.value );
			line.appendChild( value );
			var label = document.createElement( 'span' );
			label.textContent = ' ' + row.label;
			line.appendChild( label );
			tip.appendChild( line );
		} );
		tip.hidden = false;
		var w = host.clientWidth;
		var left = x + 12;
		if ( left + tip.offsetWidth > w ) {
			left = x - tip.offsetWidth - 12;
		}
		tip.style.left = Math.max( 0, left ) + 'px';
		tip.style.top = Math.max( 0, y ) + 'px';
	}

	function frame( host, series ) {
		host.textContent = '';
		var width = Math.max( 280, host.clientWidth );
		var height = host.clientHeight || 240;
		var svg = el( 'svg', { width: width, height: height, viewBox: '0 0 ' + width + ' ' + height, role: 'img' }, host );
		var max = 0;
		series.forEach( function ( key ) {
			data[ key ].forEach( function ( v ) {
				max = Math.max( max, v );
			} );
		} );
		var scale = niceScale( max );
		var plotW = width - PAD.left - PAD.right;
		var plotH = height - PAD.top - PAD.bottom;
		var y = function ( v ) {
			return PAD.top + plotH - ( v / scale.max ) * plotH;
		};

		// Recessive grid and y labels.
		for ( var v = 0; v <= scale.max; v += scale.step ) {
			el( 'line', { x1: PAD.left, x2: PAD.left + plotW, y1: y( v ), y2: y( v ), class: 0 === v ? 'adn-axis' : 'adn-grid' }, svg );
			var t = el( 'text', { x: PAD.left - 8, y: y( v ) + 4, 'text-anchor': 'end', class: 'adn-tick' }, svg );
			t.textContent = fmt.format( v );
		}

		// About six date labels, never crowded.
		var n = data.days.length;
		var every = Math.max( 1, Math.ceil( n / Math.max( 2, Math.floor( plotW / 80 ) ) ) );
		return { svg: svg, width: width, height: height, plotW: plotW, plotH: plotH, y: y, n: n, every: every };
	}

	function xLabels( f, x ) {
		data.days.forEach( function ( day, i ) {
			if ( i % f.every === 0 || i === f.n - 1 && f.n > 1 && ( f.n - 1 ) % f.every > f.every / 2 ) {
				var t = el( 'text', { x: x( i ), y: f.height - 8, 'text-anchor': 'middle', class: 'adn-tick' }, f.svg );
				t.textContent = shortDate( day );
			}
		} );
	}

	function lineChart( host, series ) {
		var f = frame( host, series );
		var x = function ( i ) {
			return PAD.left + ( f.n > 1 ? ( i / ( f.n - 1 ) ) * f.plotW : f.plotW / 2 );
		};
		xLabels( f, x );

		series.forEach( function ( key ) {
			var d = data[ key ].map( function ( v, i ) {
				return ( i ? 'L' : 'M' ) + x( i ).toFixed( 1 ) + ' ' + f.y( v ).toFixed( 1 );
			} ).join( ' ' );
			el( 'path', { d: d, fill: 'none', stroke: COLORS[ key ], 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, f.svg );
		} );

		// Direct labels at the line ends, nudged apart if they'd collide.
		var ends = series.map( function ( key ) {
			return { key: key, y: f.y( data[ key ][ f.n - 1 ] ) };
		} ).sort( function ( a, b ) {
			return a.y - b.y;
		} );
		for ( var i = 1; i < ends.length; i++ ) {
			if ( ends[ i ].y - ends[ i - 1 ].y < 14 ) {
				ends[ i ].y = ends[ i - 1 ].y + 14;
			}
		}
		ends.forEach( function ( end ) {
			var t = el( 'text', { x: PAD.left + f.plotW + 8, y: end.y + 4, class: 'adn-direct-label' }, f.svg );
			t.textContent = data.labels[ end.key ];
		} );

		// Crosshair: snaps to the nearest day; the tooltip lists every series.
		var cross = el( 'line', { y1: PAD.top, y2: PAD.top + f.plotH, class: 'adn-crosshair', visibility: 'hidden' }, f.svg );
		var dots = series.map( function ( key ) {
			return el( 'circle', { r: 4, fill: COLORS[ key ], class: 'adn-dot', visibility: 'hidden' }, f.svg );
		} );
		var hit = el( 'rect', { x: PAD.left, y: PAD.top, width: f.plotW, height: f.plotH, fill: 'transparent' }, f.svg );
		var tip = tooltip( host );

		function move( clientX ) {
			var box = f.svg.getBoundingClientRect();
			var rel = ( clientX - box.left - PAD.left ) / f.plotW;
			var i = Math.round( Math.min( 1, Math.max( 0, rel ) ) * ( f.n - 1 ) );
			var cx = x( i );
			cross.setAttribute( 'x1', cx );
			cross.setAttribute( 'x2', cx );
			cross.setAttribute( 'visibility', 'visible' );
			series.forEach( function ( key, s ) {
				dots[ s ].setAttribute( 'cx', cx );
				dots[ s ].setAttribute( 'cy', f.y( data[ key ][ i ] ) );
				dots[ s ].setAttribute( 'visibility', 'visible' );
			} );
			showTip( tip, host, cx, PAD.top, data.days[ i ], series.map( function ( key ) {
				return { label: data.labels[ key ], value: data[ key ][ i ], color: COLORS[ key ] };
			} ) );
		}
		function hide() {
			cross.setAttribute( 'visibility', 'hidden' );
			dots.forEach( function ( d ) {
				d.setAttribute( 'visibility', 'hidden' );
			} );
			tip.hidden = true;
		}
		hit.addEventListener( 'pointermove', function ( e ) {
			move( e.clientX );
		} );
		hit.addEventListener( 'pointerleave', hide );

		// Legend (line keys) for the two series, above the chart, built once.
		if ( host.previousElementSibling && host.previousElementSibling.classList.contains( 'adn-legend' ) ) {
			return;
		}
		var legend = document.createElement( 'div' );
		legend.className = 'adn-legend';
		series.forEach( function ( key ) {
			var item = document.createElement( 'span' );
			var swatch = document.createElement( 'span' );
			swatch.className = 'adn-legend__line';
			swatch.style.background = COLORS[ key ];
			item.appendChild( swatch );
			item.appendChild( document.createTextNode( data.labels[ key ] ) );
			legend.appendChild( item );
		} );
		host.parentNode.insertBefore( legend, host );
	}

	function barChart( host, key ) {
		var f = frame( host, [ key ] );
		var slot = f.plotW / f.n;
		var barW = Math.max( 2, Math.min( 24, slot - 2 ) );
		var x = function ( i ) {
			return PAD.left + slot * i + slot / 2;
		};
		xLabels( f, x );
		var tip = tooltip( host );

		data[ key ].forEach( function ( v, i ) {
			var top = f.y( v );
			var h = PAD.top + f.plotH - top;
			var g = el( 'g', { class: 'adn-bar', tabindex: '0' }, f.svg );
			// The whole column is the hit target, not just the painted bar.
			el( 'rect', { x: PAD.left + slot * i, y: PAD.top, width: slot, height: f.plotH, fill: 'transparent' }, g );
			if ( v > 0 ) {
				var r = Math.min( 4, barW / 2, h );
				var x0 = x( i ) - barW / 2;
				var base = PAD.top + f.plotH;
				el( 'path', {
					d: 'M' + x0 + ' ' + base + 'V' + ( top + r ) + 'Q' + x0 + ' ' + top + ' ' + ( x0 + r ) + ' ' + top +
						'H' + ( x0 + barW - r ) + 'Q' + ( x0 + barW ) + ' ' + top + ' ' + ( x0 + barW ) + ' ' + ( top + r ) + 'V' + base + 'Z',
					fill: COLORS[ key ],
					class: 'adn-bar__mark',
				}, g );
			}
			var show = function () {
				showTip( tip, host, x( i ), Math.max( PAD.top, top - 40 ), data.days[ i ], [ { label: data.labels[ key ], value: v } ] );
			};
			g.addEventListener( 'pointerenter', show );
			g.addEventListener( 'focus', show );
			g.addEventListener( 'pointerleave', function () {
				tip.hidden = true;
			} );
			g.addEventListener( 'blur', function () {
				tip.hidden = true;
			} );
		} );
	}

	function render() {
		document.querySelectorAll( '[data-adn-chart]' ).forEach( function ( host ) {
			var series = host.getAttribute( 'data-series' ).split( ',' );
			if ( 'bar' === host.getAttribute( 'data-adn-chart' ) ) {
				barChart( host, series[ 0 ] );
			} else {
				lineChart( host, series );
			}
		} );
	}

	var timer = null;
	window.addEventListener( 'resize', function () {
		clearTimeout( timer );
		timer = setTimeout( render, 150 );
	} );
	render();
}() );
