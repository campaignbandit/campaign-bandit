/**
 * Campaign detail (S12): draws each daily chart at its real width, so lines and labels
 * keep their size on any screen; the four figures switch between measures; a point
 * shows its day and value on hover or keyboard focus.
 */
( function () {
	const results = document.querySelector( '.cbandit-results' );
	if ( ! results ) {
		return;
	}

	const NS = 'http://www.w3.org/2000/svg';
	const HEIGHT = 220;
	const PAD = { top: 10, right: 12, bottom: 26, left: 48 };

	function el( name, attrs, parent ) {
		const node = document.createElementNS( NS, name );
		Object.keys( attrs ).forEach( function ( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );
		if ( parent ) {
			parent.appendChild( node );
		}
		return node;
	}

	// One line through the values; a missing day starts a new line.
	function path( points ) {
		let d = '';
		let move = true;
		points.forEach( function ( p ) {
			if ( ! p ) {
				move = true;
				return;
			}
			d +=
				( move ? 'M' : 'L' ) +
				p.x.toFixed( 1 ) +
				' ' +
				p.y.toFixed( 1 ) +
				' ';
			move = false;
		} );
		return d.trim();
	}

	function draw( plot ) {
		const width = plot.clientWidth;
		if ( ! width ) {
			return;
		}
		const data = JSON.parse( plot.getAttribute( 'data-chart' ) );
		const n = data.values.length;
		const inner = width - PAD.left - PAD.right;
		const tall = HEIGHT - PAD.top - PAD.bottom;
		const x = function ( i ) {
			return PAD.left + ( n > 1 ? ( i / ( n - 1 ) ) * inner : inner / 2 );
		};
		const y = function ( v ) {
			return PAD.top + tall - ( data.max > 0 ? v / data.max : 0 ) * tall;
		};
		const points = function ( values ) {
			return values.map( function ( v, i ) {
				return v === null ? null : { x: x( i ), y: y( v ) };
			} );
		};

		plot.textContent = '';
		const svg = el(
			'svg',
			{
				width,
				height: HEIGHT,
				viewBox: '0 0 ' + width + ' ' + HEIGHT,
				'aria-hidden': 'true',
				focusable: 'false',
			},
			plot
		);

		data.ticks.forEach( function ( label, i ) {
			const ty = PAD.top + tall * ( 1 - i / 4 );
			el(
				'line',
				{
					class: 'cbandit-chart__grid',
					x1: PAD.left,
					x2: width - PAD.right,
					y1: ty,
					y2: ty,
				},
				svg
			);
			el(
				'text',
				{
					class: 'cbandit-chart__tick',
					x: PAD.left - 8,
					y: ty + 4,
					'text-anchor': 'end',
				},
				svg
			).textContent = label;
		} );
		data.labels.forEach( function ( label ) {
			el(
				'text',
				{
					class: 'cbandit-chart__tick',
					x: x( label.i ),
					y: HEIGHT - 6,
					'text-anchor': 'middle',
				},
				svg
			).textContent = label.text;
		} );
		data.markers.forEach( function ( marker ) {
			const mx = x( marker.i );
			el(
				'line',
				{
					class: 'cbandit-chart__marker',
					x1: mx,
					x2: mx,
					y1: PAD.top,
					y2: PAD.top + tall,
				},
				svg
			);
		} );
		if ( data.previous ) {
			el(
				'path',
				{
					class: 'cbandit-chart__line is-previous',
					d: path( points( data.previous ) ),
				},
				svg
			);
		}
		const now = points( data.values );
		el( 'path', { class: 'cbandit-chart__line', d: path( now ) }, svg );

		// Points are buttons for keyboard users; their labels say the day and value.
		now.forEach( function ( p, i ) {
			if ( ! p ) {
				return;
			}
			const dot = document.createElement( 'button' );
			dot.type = 'button';
			dot.className = 'cbandit-chart__point';
			dot.style.left = p.x + 'px';
			dot.style.top = p.y + 'px';
			dot.setAttribute( 'aria-label', data.tips[ i ] );
			dot.setAttribute( 'data-tip', data.tips[ i ] );
			plot.appendChild( dot );
		} );
		data.markers.forEach( function ( marker ) {
			const tag = document.createElement( 'a' );
			tag.className = 'cbandit-chart__tag';
			tag.href = marker.url;
			tag.textContent = marker.label;
			tag.style.left = x( marker.i ) + 'px';
			tag.style.top = PAD.top + 'px';
			plot.appendChild( tag );
		} );
	}

	const plots = results.querySelectorAll( '.cbandit-chart__plot' );
	function drawVisible() {
		plots.forEach( function ( plot ) {
			if ( ! plot.closest( '[hidden]' ) ) {
				draw( plot );
			}
		} );
	}
	drawVisible();
	let pending = 0;
	window.addEventListener( 'resize', function () {
		window.cancelAnimationFrame( pending );
		pending = window.requestAnimationFrame( drawVisible );
	} );

	const figures = results.querySelectorAll( '.cbandit-figure' );
	const charts = results.querySelectorAll( '.cbandit-chart__measure' );
	figures.forEach( function ( figure ) {
		figure.addEventListener( 'click', function () {
			const measure = figure.getAttribute( 'data-measure' );
			figures.forEach( function ( other ) {
				other.setAttribute(
					'aria-pressed',
					other === figure ? 'true' : 'false'
				);
			} );
			charts.forEach( function ( chart ) {
				chart.hidden = chart.getAttribute( 'data-measure' ) !== measure;
			} );
			drawVisible();
		} );
	} );

	const tip = document.createElement( 'div' );
	tip.className = 'cbandit-chart__tip';
	tip.hidden = true;
	document.body.appendChild( tip );
	function show( dot ) {
		const box = dot.getBoundingClientRect();
		tip.textContent = dot.getAttribute( 'data-tip' );
		tip.hidden = false;
		tip.style.left = box.left + box.width / 2 + window.scrollX + 'px';
		tip.style.top = box.top + window.scrollY - 8 + 'px';
	}
	function hide() {
		tip.hidden = true;
	}
	[ 'mouseover', 'focusin' ].forEach( function ( type ) {
		results.addEventListener( type, function ( event ) {
			const dot =
				event.target.closest &&
				event.target.closest( '.cbandit-chart__point' );
			if ( dot ) {
				show( dot );
			}
		} );
	} );
	[ 'mouseout', 'focusout' ].forEach( function ( type ) {
		results.addEventListener( type, function ( event ) {
			if (
				event.target.closest &&
				event.target.closest( '.cbandit-chart__point' )
			) {
				hide();
			}
		} );
	} );
} )();
