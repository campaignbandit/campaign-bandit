/**
 * Triggers (FR-31): time on the visible page, scroll depth, and click.
 * Whichever armed trigger happens first opens the campaign.
 */

/**
 * Call back after `seconds` of visible time; time on a hidden tab does not count.
 *
 * @param {number}     seconds  Delay.
 * @param {() => void} callback Called once.
 * @param {Document}   doc      Document.
 * @return {() => void} Cancel.
 */
export function afterVisibleTime( seconds, callback, doc = document ) {
	let remaining = seconds * 1000;
	let startedAt = null;
	let timer = null;

	const start = () => {
		if ( timer === null && doc.visibilityState !== 'hidden' ) {
			startedAt = Date.now();
			timer = setTimeout( fire, remaining );
		}
	};
	const pause = () => {
		if ( timer !== null ) {
			clearTimeout( timer );
			timer = null;
			remaining -= Date.now() - startedAt;
		}
	};
	const onVisibility = () =>
		doc.visibilityState === 'hidden' ? pause() : start();
	const cancel = () => {
		pause();
		doc.removeEventListener( 'visibilitychange', onVisibility );
	};
	function fire() {
		cancel();
		callback();
	}

	doc.addEventListener( 'visibilitychange', onVisibility );
	start();
	return cancel;
}

/**
 * Call back once the visitor has scrolled past `percent` of the page height.
 *
 * @param {number}     percent  Scroll depth, 1 to 100.
 * @param {() => void} callback Called once.
 * @param {Window}     win      Window.
 * @return {() => void} Cancel.
 */
export function afterScroll( percent, callback, win = window ) {
	const check = () => {
		const doc = win.document.documentElement;
		const scrollable = doc.scrollHeight - win.innerHeight;
		const depth =
			scrollable <= 0 ? 100 : ( win.scrollY / scrollable ) * 100;
		if ( depth >= percent ) {
			cancel();
			callback();
		}
	};
	const cancel = () => win.removeEventListener( 'scroll', check );
	win.addEventListener( 'scroll', check, { passive: true } );
	check();
	return cancel;
}

/**
 * Call back once when, on a device with a fine pointer (desktop), the pointer leaves
 * the page through the top edge: exit intent (FR-31). Never fires on touch devices.
 *
 * @param {() => void} callback Called once.
 * @param {Window}     win      Window.
 * @return {() => void} Cancel.
 */
export function afterExitIntent( callback, win = window ) {
	const fine = win.matchMedia?.( '(pointer: fine)' )?.matches;
	if ( ! fine ) {
		return () => {};
	}
	const onOut = ( event ) => {
		if ( ! event.relatedTarget && event.clientY <= 0 ) {
			cancel();
			callback();
		}
	};
	const cancel = () => win.document.removeEventListener( 'mouseout', onOut );
	win.document.addEventListener( 'mouseout', onOut );
	return cancel;
}

/**
 * Call back once when a custom event with this name fires on the page (FR-31),
 * on the window or the document, for example a shop's "added to cart" event.
 *
 * @param {string}     name     Event name.
 * @param {() => void} callback Called once.
 * @param {Window}     win      Window.
 * @return {() => void} Cancel.
 */
export function onCustomEvent( name, callback, win = window ) {
	const handler = () => {
		cancel();
		callback();
	};
	const cancel = () => {
		win.removeEventListener( name, handler );
		win.document.removeEventListener( name, handler );
	};
	win.addEventListener( name, handler );
	win.document.addEventListener( name, handler );
	return cancel;
}

/**
 * The campaign an element opens, if any: an element with the class
 * `cbandit-open-<campaign id>`, the attribute `data-cbandit-open="<campaign id>"`,
 * or a link to `#cbandit-<campaign id>`.
 *
 * @param {Element} target Element the visitor clicked, hovered or focused.
 * @return {string|null} Campaign ID (uppercase), or null.
 */
export function clickTarget( target ) {
	const el = target?.closest?.(
		'[class*="cbandit-open-"], [data-cbandit-open], a[href*="#cbandit-"]'
	);
	if ( ! el ) {
		return null;
	}
	const fromAttribute = el.getAttribute( 'data-cbandit-open' );
	if ( fromAttribute && /^[0-9a-z]{26}$/i.test( fromAttribute ) ) {
		return fromAttribute.toUpperCase();
	}
	const fromClass = /(?:^|\s)cbandit-open-([0-9a-z]{26})(?:\s|$)/i.exec(
		el.className || ''
	);
	if ( fromClass ) {
		return fromClass[ 1 ].toUpperCase();
	}
	const fromHref = /#cbandit-([0-9a-z]{26})$/i.exec(
		el.getAttribute( 'href' ) || ''
	);
	return fromHref ? fromHref[ 1 ].toUpperCase() : null;
}
