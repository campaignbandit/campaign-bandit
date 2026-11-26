/**
 * Displays campaigns in their format (FR-22) with the accessibility rules in 05 §6:
 * a modal takes focus, keeps it inside and returns it on close; slide-ins and bars
 * never steal focus; everything closes by keyboard. Panels fade and rise in 200ms;
 * with prefers-reduced-motion they appear without any. A bar pushes the page rather
 * than covering it, and a top bar can sit below a sticky header (FR-22). On phones a
 * modal becomes a bottom sheet.
 */

const FOCUSABLE =
	'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

const CSS = `
.cbandit{box-sizing:border-box;z-index:99999;font:inherit;color:inherit}
.cbandit *,.cbandit-inline,.cbandit-inline *{box-sizing:border-box}
.cbandit-backdrop{position:fixed;inset:0;background:var(--cbandit-backdrop,rgba(0,0,0,.55));animation:cbandit-fade .2s}
.cbandit-modal{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;padding:16px}
.cbandit-panel{position:relative;background:#fff;max-width:var(--cbandit-width,560px);width:100%;max-height:calc(100vh - 32px);overflow-x:hidden;overflow-y:auto;padding:24px;border-radius:0;animation:cbandit-in .2s ease-out}
.cbandit-edge>.cbandit-panel,.cbandit-inline.cbandit-edge{padding:0}
.cbandit-content>:first-child{margin-top:0}.cbandit-content>:last-child{margin-bottom:0}
@media (min-width:601px){.cbandit .wp-block-column.cbandit-band>figure,.cbandit-inline .wp-block-column.cbandit-band>figure{height:100%}
.cbandit .wp-block-column.cbandit-band img,.cbandit-inline .wp-block-column.cbandit-band img{height:100%!important}
.cbandit .wp-block-cover.cbandit-band{align-items:stretch}
.cbandit .wp-block-cover.cbandit-band>.wp-block-cover__inner-container>.wp-block-columns{height:100%;margin:0}}
.cbandit-slide_in{position:fixed;width:min(var(--cbandit-width,360px),calc(100vw - 48px))}
.cbandit-slide_in .cbandit-panel{border:1px solid rgba(0,0,0,.2)}
.cbandit-bottom_right{right:24px;bottom:24px}.cbandit-bottom_left{left:24px;bottom:24px}
.cbandit-top_right{right:24px;top:24px}.cbandit-top_left{left:24px;top:24px}
.cbandit-bar{position:fixed;left:0;right:0}
.cbandit-bar .cbandit-panel{max-width:none;min-height:60px;display:flex;align-items:center;padding:8px 60px 8px 16px}
.cbandit-bar .cbandit-content{width:100%}
.cbandit-bar .cbandit-form-wrap{flex:0 1 420px}
.cbandit-bar-top{top:var(--cbandit-top-offset,0)}.cbandit-bar-bottom{bottom:0}
.cbandit-hp{position:absolute!important;left:-9999px!important}
.cbandit [hidden],.cbandit-inline [hidden]{display:none!important}
.cbandit-after:focus{outline:none}
.wp-block-column:has(>.cbandit-after:not([hidden])){display:flex;flex-direction:column;justify-content:center}
.cbandit-sr{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.cbandit-form{display:flex;flex-direction:row;flex-wrap:wrap;gap:12px 16px;align-items:flex-end}
.cbandit-form>*{flex:1 1 100%}
.cbandit-form>.cbandit-field{flex:1 1 180px}
.cbandit-form--row{gap:12px 0;align-items:flex-start}
.cbandit-form--row .cbandit-field input:not([type=checkbox]):not([type=radio]),.cbandit-form--row>.cbandit-submit{height:48px;min-height:0}
.cbandit-form--row>.cbandit-field{flex:1 1 120px;min-width:0}
.cbandit-form--row>.cbandit-submit{padding-left:20px;padding-right:20px}
.cbandit-form--row>.cbandit-submit{flex:0 0 auto;width:auto}
.cbandit-form--row>.cbandit-field--consent,.cbandit-form--row>.cbandit-field--choice{flex-basis:100%;order:2}
.cbandit-form--row>.cbandit-form-status{flex-basis:100%;order:3}
:where(.cbandit-field input:not([type=checkbox]):not([type=radio]),.cbandit-field select,.cbandit-field textarea){min-height:48px;padding:10px 14px;border:1px solid #8c8f94;border-radius:0;background:#fff;color:#1d2327;font:inherit}
.cbandit-submit{width:100%;min-height:48px;border-radius:0}
.cbandit-required{font-weight:400}
.cbandit-field--consent{font-size:.875em;line-height:1.5}
.cbandit-field{margin:0}
.cbandit-form>.cbandit-field--consent,.cbandit-form>.cbandit-field--choice,.cbandit-form>.cbandit-field--long_text{flex-basis:100%}
fieldset.cbandit-field{border:0;padding:0;min-width:0}
.cbandit-field legend{padding:0;margin-bottom:6px;font-weight:600}
.cbandit-option{display:flex;align-items:center;gap:8px;margin:4px 0}
.cbandit-field--chips .cbandit-options{display:flex;flex-wrap:wrap;gap:8px}
.cbandit-chip{position:relative;margin:0;min-height:44px;padding:8px 18px;border:1px solid #8c8f94;cursor:pointer}
.cbandit-chip input{position:absolute;opacity:0;inset:0;margin:0;cursor:pointer}
.cbandit-chip:has(input:checked){border-color:currentColor;background:rgba(0,0,0,.06);font-weight:600}
.cbandit-chip:has(input:focus-visible){outline:2px solid currentColor;outline-offset:2px}
.cbandit-field textarea,.cbandit-field select{display:block;width:100%}
.cbandit-field:not(.cbandit-field--consent)>label{display:block;margin-bottom:6px;font-weight:600}
.cbandit-field input:not([type=checkbox]):not([type=radio]){display:block;width:100%}
.cbandit-error{margin:4px 0 0;color:#b32d2e}
.cbandit-form-status:empty{display:none}
.cbandit-close{position:absolute;z-index:2;top:12px;right:12px;width:44px;height:44px;padding:0;border:0;background:#fff;color:#1d2327;font-size:26px;line-height:1;cursor:pointer}
.cbandit-bar .cbandit-close{top:50%;right:8px;margin-top:-22px;background:transparent;color:inherit}
.is-style-cbandit-dismiss .wp-block-button__link{background:none!important;color:inherit!important;padding:8px 4px!important;text-decoration:underline;border:0}
.cbandit-styled .cbandit-panel,.cbandit-inline.cbandit-styled{font-family:var(--cbandit-font);color:var(--cbandit-text);background:var(--cbandit-bg);border-radius:var(--cbandit-radius)}
.cbandit-styled :is(h1,h2,h3,h4,h5,h6){font-family:var(--cbandit-heading-font);color:inherit}
.cbandit-styled .cbandit-content a:not(.wp-block-button__link){color:var(--cbandit-accent)}
.cbandit-styled .wp-block-button__link,.cbandit-styled button.cbandit-submit{background:var(--cbandit-accent);color:var(--cbandit-accent-text);border-radius:var(--cbandit-radius);font-family:inherit}
.cbandit-styled .wp-block-button__link:hover,.cbandit-styled .wp-block-button__link:focus-visible,.cbandit-styled button.cbandit-submit:hover,.cbandit-styled button.cbandit-submit:focus-visible{background:var(--cbandit-accent-hover)}
.cbandit-styled .cbandit-field :is(input,select,textarea){border-radius:var(--cbandit-radius);font-family:inherit}
.cbandit-styled .cbandit-close{background:var(--cbandit-bg);color:var(--cbandit-text)}
.cbandit-branded .cbandit-content a:not(.wp-block-button__link){color:var(--cbandit-brand)}
.cbandit-branded .wp-block-button__link,.cbandit-branded button.cbandit-submit{background:var(--cbandit-brand)}
.cbandit-branded .wp-block-button__link:hover,.cbandit-branded .wp-block-button__link:focus-visible,.cbandit-branded button.cbandit-submit:hover,.cbandit-branded button.cbandit-submit:focus-visible{background:var(--cbandit-brand)}
.cbandit-credit{display:block;margin:8px 0 0;font-size:11px;line-height:1.4;opacity:.6}
.cbandit-credit a{color:inherit}
.cbandit-anim-fade .cbandit-panel{animation-name:cbandit-fade}
.cbandit-anim-zoom .cbandit-panel{animation-name:cbandit-zoom}
.cbandit-anim-none .cbandit-panel,.cbandit-anim-none .cbandit-backdrop{animation:none}
@keyframes cbandit-in{from{opacity:0;transform:translateY(12px)}}
@keyframes cbandit-fade{from{opacity:0}}
@keyframes cbandit-zoom{from{opacity:0;transform:scale(.94)}}
@media (prefers-reduced-motion:reduce){.cbandit-panel,.cbandit-backdrop{animation:none}}
@media (min-width:601px){.cbandit-v-top{align-items:flex-start}.cbandit-v-bottom{align-items:flex-end}.cbandit-h-left{justify-content:flex-start}.cbandit-h-right{justify-content:flex-end}}
@media (max-width:600px){
.cbandit-styled .cbandit-content{font-size:calc(1em * var(--cbandit-mobile,1))}
.cbandit-field input,.cbandit-field select,.cbandit-field textarea{font-size:16px}
.cbandit-modal{align-items:flex-end;padding:0}
.cbandit-modal .cbandit-panel{max-width:none;max-height:92vh}
.cbandit-slide_in{left:0;right:0;bottom:0;top:auto;width:100%}
.cbandit-bar .cbandit-panel{min-height:56px}
.cbandit-form--row>.cbandit-submit{flex-basis:100%}
.cbandit-hide-mobile{display:none!important}
.cbandit .cbandit-band img,.cbandit-inline .cbandit-band img{height:var(--cbandit-band,140px)!important;width:100%!important;object-fit:cover}
.cbandit-band--108{--cbandit-band:108px}.cbandit-band--160{--cbandit-band:160px}.cbandit-band--180{--cbandit-band:180px}
.cbandit .wp-block-cover.cbandit-band{min-height:0!important;padding:var(--cbandit-band,160px) 0 0!important;align-items:stretch}
.cbandit .wp-block-cover.cbandit-band>.wp-block-cover__image-background{height:var(--cbandit-band,160px);bottom:auto}
.cbandit .wp-block-cover.cbandit-band>.wp-block-cover__inner-container>*{max-width:none!important;width:100%!important;background:#fff!important}
}
`;

let styled = false;

/**
 * Tag a shown campaign so its form knows what it belongs to and when it appeared
 * (the 2-second spam rule, BR-46).
 *
 * @param {Element} el         Host element.
 * @param {Object}  deployment Manifest entry.
 */
function mark( el, deployment ) {
	el.setAttribute( 'data-cbandit-campaign', deployment.campaign );
	el.setAttribute( 'data-cbandit-version', String( deployment.version ) );
	el.setAttribute( 'data-cbandit-shown', String( Date.now() ) );
}

/**
 * Add the stylesheet once.
 *
 * @param {Document} doc Document.
 */
function addStyles( doc ) {
	if ( ! styled ) {
		const style = doc.createElement( 'style' );
		style.textContent = CSS;
		doc.head.appendChild( style );
		styled = true;
	}
}

/**
 * Give the surface an accessible name: its first heading, or the campaign name.
 *
 * @param {Element} surface    Element with the dialog or region role.
 * @param {Element} content    Content container.
 * @param {Object}  deployment Manifest entry.
 */
function label( surface, content, deployment ) {
	const heading = content.querySelector( 'h1,h2,h3,h4,h5,h6' );
	if ( heading ) {
		heading.id =
			heading.id || 'cbandit-title-' + deployment.campaign.toLowerCase();
		surface.setAttribute( 'aria-labelledby', heading.id );
	} else {
		surface.setAttribute( 'aria-label', deployment.name || 'Campaign' );
	}
}

/**
 * Buttons that close the campaign (FR-23): the Close action, or the "No thanks" style.
 */
const CLOSERS = '.is-style-cbandit-dismiss a,[data-cbandit-action="close"]';

/**
 * Put a site's own number into Call and Text us links (BR-21), keeping any message.
 *
 * @param {Element} content Campaign content.
 * @param {string}  phone   The site's number, or ''.
 */
export function sitePhone( content, phone ) {
	if ( ! phone ) {
		return;
	}
	content
		.querySelectorAll(
			'[data-cbandit-action="call"],[data-cbandit-action="text"]'
		)
		.forEach( ( link ) => {
			const href = link.getAttribute( 'href' ) || '';
			link.setAttribute(
				'href',
				href.replace( /^(tel:|sms:)[^?]*/, '$1' + phone )
			);
		} );
}

/**
 * Whether a click on the backdrop closes the campaign (FR-22): by default yes on
 * computers and tablets, no on phones (BR-03: narrower than 768 px).
 *
 * @param {Object} deployment Manifest entry.
 * @param {Window} win        Window.
 * @return {boolean} Closes.
 */
export function backdropCloses( deployment, win ) {
	const rule = deployment.backdropClose || {};
	return ( win?.innerWidth || 1024 ) < 768
		? rule.mobile === true
		: rule.desktop !== false;
}

/**
 * Swipe down to close on a phone (FR-22): the sheet follows the finger and closes as a
 * dismissal past a third of its height; a shorter swipe springs back. With reduced
 * motion it doesn't follow the finger. Only while the sheet's content is scrolled to
 * the top, so scrolling the content still works.
 *
 * @param {Element}    panel   The sheet.
 * @param {Window}     win     Window.
 * @param {() => void} onClose Close as a dismissal.
 */
export function swipeToClose( panel, win, onClose ) {
	const phone = () => win?.matchMedia?.( '(max-width:600px)' ).matches;
	const still = () =>
		win?.matchMedia?.( '(prefers-reduced-motion:reduce)' ).matches;
	let startY = null;
	let dy = 0;
	panel.addEventListener(
		'touchstart',
		( event ) => {
			startY =
				phone() && panel.scrollTop <= 0
					? event.touches[ 0 ].clientY
					: null;
			dy = 0;
		},
		{ passive: true }
	);
	panel.addEventListener(
		'touchmove',
		( event ) => {
			if ( startY === null ) {
				return;
			}
			dy = Math.max( 0, event.touches[ 0 ].clientY - startY );
			if ( ! still() ) {
				panel.style.transition = 'none';
				panel.style.transform = `translateY(${ dy }px)`;
			}
		},
		{ passive: true }
	);
	panel.addEventListener( 'touchend', () => {
		if ( startY === null ) {
			return;
		}
		startY = null;
		if ( dy > panel.offsetHeight / 3 ) {
			onClose();
			return;
		}
		panel.style.transition = 'transform .2s ease-out';
		panel.style.transform = '';
	} );
}

/**
 * DB-IP's credit, on a campaign whose country rule the DB-IP database decided. Its
 * licence asks for a link back on pages that use its results (decision 0027).
 *
 * @param {Element}  el    The campaign's element.
 * @param {string}   text  Translated credit text, with %s for the link.
 * @param {Document} [doc] Document.
 */
export function dbipCredit( el, text, doc = document ) {
	const content = el.querySelector( '.cbandit-content' ) || el;
	if ( content.querySelector( '.cbandit-credit' ) ) {
		return;
	}
	const line = doc.createElement( 'small' );
	line.className = 'cbandit-credit';
	const link = doc.createElement( 'a' );
	link.href = 'https://db-ip.com';
	link.rel = 'noopener nofollow';
	link.target = '_blank';
	link.textContent = 'DB-IP';
	const [ before, after ] = String( text || 'IP geolocation by %s' ).split(
		'%s'
	);
	line.append( before ?? '', link, after ?? '' );
	content.append( line );
}

/**
 * Where a modal sits on larger screens (9-point position): row and column classes.
 * Phones always get the bottom sheet.
 *
 * @param {string} position One of top_left, top, top_right, left, center, right, bottom_left, bottom, bottom_right.
 * @return {string[]} Classes.
 */
export function positionClasses( position = 'center' ) {
	const [ first, second ] = String( position || 'center' ).split( '_' );
	const vertical = [ 'top', 'bottom' ].includes( first ) ? first : 'center';
	const horizontal = [ 'left', 'right' ].includes( second || first )
		? second || first
		: 'center';
	const out = [];
	if ( vertical !== 'center' ) {
		out.push( 'cbandit-v-' + vertical );
	}
	if ( horizontal !== 'center' ) {
		out.push( 'cbandit-h-' + horizontal );
	}
	return out;
}

/**
 * Stop the page scrolling, without the page shifting as the scrollbar goes. Returns
 * the function that puts it back.
 *
 * @param {Document} doc Document.
 * @return {() => void} Unlock.
 */
export function lockScroll( doc ) {
	const html = doc.documentElement;
	const body = doc.body;
	const win = doc.defaultView;
	const gap = win ? win.innerWidth - html.clientWidth : 0;
	const before = {
		overflow: html.style.overflow,
		padding: body.style.paddingRight,
	};
	html.style.overflow = 'hidden';
	if ( gap > 0 ) {
		const current =
			parseFloat( win.getComputedStyle( body ).paddingRight ) || 0;
		body.style.paddingRight = current + gap + 'px';
	}
	return () => {
		html.style.overflow = before.overflow;
		body.style.paddingRight = before.padding;
	};
}

/**
 * Campaign styles (FR-27): the campaign's own fonts and colours as CSS variables, so
 * it looks the same on every site. Without them it inherits the theme.
 *
 * @param {Element} el         Campaign element.
 * @param {Object}  deployment Manifest entry.
 */
export function applyStyles( el, deployment ) {
	if ( deployment.brand ) {
		// A site's brand colour (BR-21): buttons and links, campaign styles or not.
		el.classList.add( 'cbandit-branded' );
		el.style.setProperty( '--cbandit-brand', String( deployment.brand ) );
	}
	if ( ! deployment.styles ) {
		return;
	}
	el.classList.add( 'cbandit-styled' );
	Object.entries( deployment.styles ).forEach( ( [ name, value ] ) => {
		if ( name.startsWith( '--cbandit-' ) ) {
			el.style.setProperty( name, String( value ) );
		}
	} );
}

/**
 * Show a campaign.
 *
 * @param {Object}                   deployment       Manifest entry.
 * @param {string}                   html             Rendered content.
 * @param {Object}                   handlers         Callbacks.
 * @param {(reason: string) => void} handlers.onClose Called with 'dismiss' when the visitor closes it.
 * @param {() => void}               handlers.onGoal  Called when a goal button is clicked.
 * @param {Element}                  [placeholder]    Inline placeholder to fill.
 * @param {Document}                 [doc]            Document.
 * @return {{close: (reason?: string) => void, element: Element}} Handle.
 */
export function show(
	deployment,
	html,
	{ onClose, onGoal },
	placeholder = null,
	doc = document
) {
	addStyles( doc );
	const format = deployment.format;
	const content = doc.createElement( 'div' );
	content.className = 'cbandit-content';
	content.innerHTML = html;
	sitePhone( content, deployment.phone );

	content.addEventListener( 'click', ( event ) => {
		if ( event.target.closest?.( '.cbandit-goal,[data-cbandit-goal]' ) ) {
			onGoal();
		}
		// A Close button ("No thanks"): closes it as a dismissal (BR-12). After the
		// form is sent it simply closes: that is not a dismissal.
		const dismiss = event.target.closest?.( CLOSERS );
		if ( format !== 'inline' && dismiss ) {
			event.preventDefault();
			close(
				dismiss.closest( '[data-cbandit-after]' ) ? 'done' : 'dismiss'
			);
		}
	} );
	content
		.querySelectorAll( CLOSERS )
		.forEach( ( link ) => link.setAttribute( 'role', 'button' ) );

	if ( format === 'inline' ) {
		const host = placeholder || doc.createElement( 'div' );
		host.classList.add( 'cbandit-inline' );
		applyStyles( host, deployment );
		host.classList.toggle( 'cbandit-edge', Boolean( deployment.edge ) );
		mark( host, deployment );
		host.replaceChildren( content );
		return { element: host, close: () => {} };
	}

	const root = doc.createElement( 'div' );
	root.className =
		'cbandit cbandit-' +
		format +
		( deployment.edge ? ' cbandit-edge' : '' );
	if ( deployment.width ) {
		root.style.setProperty( '--cbandit-width', deployment.width + 'px' );
	}
	if ( deployment.backdrop ) {
		root.style.setProperty( '--cbandit-backdrop', deployment.backdrop );
	}
	root.dataset.campaign = deployment.campaign;
	mark( root, deployment );
	applyStyles( root, deployment );
	root.classList.add( 'cbandit-anim-' + ( deployment.animation || 'rise' ) );
	if ( format === 'modal' ) {
		positionClasses( deployment.position ).forEach( ( name ) =>
			root.classList.add( name )
		);
	}
	const panel = doc.createElement( 'div' );
	panel.className = 'cbandit-panel';
	const closeButton = doc.createElement( 'button' );
	closeButton.type = 'button';
	closeButton.className = 'cbandit-close';
	closeButton.setAttribute( 'aria-label', 'Close' );
	closeButton.textContent = '\u00d7';
	// Content first, so a modal's focus lands on the first form field, not the close button.
	panel.append( content, closeButton );

	let surface = panel;
	if ( format === 'modal' ) {
		const backdrop = doc.createElement( 'div' );
		backdrop.className = 'cbandit-backdrop';
		backdrop.addEventListener( 'click', () => {
			if ( backdropCloses( deployment, doc.defaultView ) ) {
				close( 'dismiss' );
			}
		} );
		root.append( backdrop, panel );
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-modal', 'true' );
		panel.tabIndex = -1;
	} else if ( format === 'slide_in' ) {
		root.classList.add(
			'cbandit-' + ( deployment.corner || 'bottom_right' )
		);
		root.append( panel );
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-modal', 'false' );
	} else {
		root.classList.add(
			'cbandit-bar-' +
				( deployment.barPosition === 'top' ? 'top' : 'bottom' )
		);
		root.append( panel );
		panel.setAttribute( 'role', 'region' );
		surface = panel;
	}
	label( surface, content, deployment );

	const opener = doc.activeElement;
	let closed = false;

	const onKey = ( event ) => {
		if ( event.key === 'Escape' ) {
			close( 'dismiss' );
		} else if ( event.key === 'Tab' && format === 'modal' ) {
			const items = [ ...panel.querySelectorAll( FOCUSABLE ) ];
			if ( items.length === 0 ) {
				event.preventDefault();
				return;
			}
			const first = items[ 0 ];
			const last = items[ items.length - 1 ];
			if ( event.shiftKey && doc.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && doc.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}
	};

	// A modal can stop the page behind it scrolling while it is open.
	const unlock =
		format === 'modal' && deployment.scrollLock
			? lockScroll( doc )
			: () => {};

	function close( reason ) {
		if ( closed ) {
			return;
		}
		closed = true;
		doc.removeEventListener( 'keydown', onKey );
		root.remove();
		unlock();
		push( '' );
		if ( format === 'modal' && opener && opener.focus ) {
			opener.focus();
		}
		if ( reason === 'dismiss' ) {
			onClose( 'dismiss' );
		}
	}

	// A bar pushes the page instead of covering it.
	const side =
		deployment.barPosition === 'top' ? 'paddingTop' : 'paddingBottom';
	const before = doc.documentElement.style[ side ];
	function push( value ) {
		if ( format === 'bar' ) {
			doc.documentElement.style[ side ] = value || before;
		}
	}

	closeButton.addEventListener( 'click', () => close( 'dismiss' ) );
	doc.addEventListener( 'keydown', onKey );
	if ( format === 'modal' || format === 'slide_in' ) {
		swipeToClose( panel, doc.defaultView, () => close( 'dismiss' ) );
	}
	doc.body.appendChild( root );
	// The frame takes the colours of the campaign's outer block, so a coloured design
	// fills its frame and the close button matches its text.
	// (Read once it is on the page.)
	const outer = content.firstElementChild;
	const colors = outer && doc.defaultView?.getComputedStyle?.( outer );
	if (
		colors &&
		! /^(transparent|rgba\(0, 0, 0, 0\))$/.test( colors.backgroundColor )
	) {
		panel.style.backgroundColor = colors.backgroundColor;
		panel.style.color = colors.color;
	}

	// A top bar sits below the WordPress toolbar for logged-in users, and below a
	// sticky site header by its own offset (FR-22).
	const toolbar = doc.getElementById( 'wpadminbar' );
	const offset =
		( toolbar?.offsetHeight || 0 ) +
		( Number( deployment.barOffset ) || 0 );
	if ( format === 'bar' && deployment.barPosition === 'top' && offset > 0 ) {
		root.style.setProperty( '--cbandit-top-offset', offset + 'px' );
	}
	push( root.offsetHeight ? root.offsetHeight + 'px' : '' );

	if ( format === 'modal' ) {
		( panel.querySelector( FOCUSABLE ) || panel ).focus();
	}

	return { element: root, close };
}
