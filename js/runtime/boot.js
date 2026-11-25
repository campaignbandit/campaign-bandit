/**
 * Runtime start-up (decision 0009): load the manifest, decide what qualifies on this
 * page view, arm triggers, and show campaigns. Any failure leaves the page as if
 * Campaign Bandit were not installed (05 §5).
 */

import { createStore } from './state.js';
import { deviceFor, nextVisit } from './rules/visitor.js';
import { effectiveParams, parseQuery } from './rules/params.js';
import { evaluate, INTERRUPTIVE } from './rules/eligibility.js';
import { select } from './rules/selection.js';
import { explain } from './rules/explanation.js';
import {
	afterExitIntent,
	afterScroll,
	afterVisibleTime,
	clickTarget,
	onCustomEvent,
} from './triggers.js';
import { dbipCredit, show } from './render.js';
import { attachForms } from './forms.js';
import { track } from './analytics.js';
import { marketingConsent, onConsentChange, sourceAllowed } from './consent.js';
import { forLead, nextSource, referrerDomain } from './rules/attribution.js';
import { previewToken, runPreview } from './preview.js';

/**
 * First path segment, used for metric breakdowns (BR-15).
 *
 * @param {string} path Page path.
 * @return {string} Page group, such as `/blog`, or `/`.
 */
export function pageGroup( path ) {
	const first = String( path || '/' )
		.toLowerCase()
		.split( '/' )
		.find( Boolean );
	return first ? '/' + first : '/';
}

/**
 * Read storage without throwing (blocked in some private modes).
 *
 * @param {Window} win  Window.
 * @param {string} name 'localStorage' or 'sessionStorage'.
 * @return {Storage|null} Storage.
 */
function storage( win, name ) {
	try {
		return win[ name ];
	} catch {
		return null;
	}
}

/**
 * Start the runtime.
 *
 * @param {Object} config          Inline configuration from PHP.
 * @param {string} config.base     Package folder URL (static delivery).
 * @param {string} config.rest     REST base for campaign-bandit/v1, with trailing slash.
 * @param {string} config.delivery 'files' or 'rest'.
 * @param {Window} [win]           Window.
 * @return {Promise<Object|undefined>} Internal handle, for tests.
 */
export async function start( config, win = window ) {
	try {
		return await run( config, win );
	} catch {
		return undefined;
	}
}

/**
 * The runtime itself.
 *
 * @param {Object} config Configuration.
 * @param {Window} win    Window.
 * @return {Promise<Object>} Internal handle.
 */
async function run( config, win ) {
	// A preview link (FR-28): show that campaign alone, and nothing else on this page.
	const session = storage( win, 'sessionStorage' );
	const token = previewToken( win, session );
	if ( token ) {
		const result = await runPreview( config, win, token, session );
		if ( result.preview ) {
			return result;
		}
	}
	const doc = win.document;
	const files = config.delivery !== 'rest';
	const store = createStore( storage( win, 'localStorage' ) );
	const now = Date.now();
	const currentParams = parseQuery( win.location.search );
	const visit = nextVisit( store.visit(), now, currentParams, () =>
		Math.random().toString( 36 ).slice( 2, 12 )
	);
	store.setVisit( visit );
	// Where this browser came from, for the lead's source (BR-47), once the site's
	// cookie consent allows it (BR-48).
	const consent = config.consent || {};
	const recordSource = () =>
		store.setSource(
			nextSource( store.source(), {
				newVisit: visit.isNew,
				params: effectiveParams( visit.params, currentParams ),
				referrer: referrerDomain( doc.referrer, win.location.hostname ),
				path: win.location.pathname,
				now: Date.now(),
			} )
		);
	if ( sourceAllowed( consent, win ) ) {
		recordSource();
	} else {
		// A "no" forgets what was kept; a consent tool that hasn't answered yet doesn't.
		if ( marketingConsent( win ) === false && store.source() ) {
			store.setSource( null );
		}
		let recorded = false;
		onConsentChange( win, () => {
			if ( ! recorded && sourceAllowed( consent, win ) ) {
				recorded = true;
				recordSource();
			}
		} );
	}

	const response = await win.fetch(
		files
			? config.base + 'manifest.json'
			: config.rest + 'package/manifest',
		{ cache: 'no-cache', credentials: 'omit' }
	);
	const manifest = await response.json();
	// Hub campaigns on a staging site come inline, for logged-in editors only (BR-33).
	const preview = config.preview || {};
	const deployments = [
		...( manifest.deployments || [] ),
		...( preview.deployments || [] ),
	];
	if ( deployments.length === 0 ) {
		return { opened: [] };
	}

	// The visitor's country, only when a campaign asks for one (BR-04).
	const countryResult = await country( config, win, deployments );
	const device = deviceFor( win.innerWidth );
	const group = pageGroup( win.location.pathname );
	const context = {
		now,
		path: win.location.pathname,
		params: effectiveParams( visit.params, currentParams ),
		device,
		country: countryResult.code,
		visitId: visit.id,
		defaultExclude: manifest.site?.defaultExclude || [],
		site: {
			timezone: manifest.site?.timezone || '',
			utcOffset: manifest.site?.utcOffset || 0,
		},
		page: win.campaignBanditPage || {},
		history: store.history,
		offerConvertedAt: store.offerConvertedAt,
	};

	const opened = [];
	const explanations = {};
	let interruptiveOpen = null;
	let interruptiveUsed = false;

	// DB-IP's licence asks for a link back where its results are used (decision 0027).
	const credit = ( deployment, element ) => {
		if (
			countryResult.source === 'database' &&
			usesCountry( deployment ) &&
			element
		) {
			dbipCredit( element, config.messages?.dbip, doc );
		}
	};

	const beacon = ( deployment, type ) => {
		if ( type === 'view' ) {
			track( win, config.analytics, 'view', deployment );
		}
		const body = JSON.stringify( {
			campaign: deployment.campaign,
			version: deployment.version,
			type,
			device,
			pageGroup: group,
		} );
		try {
			win.navigator.sendBeacon?.( config.rest + 'events', body );
		} catch {
			// Counting is best effort; never break the page.
		}
	};

	const content = async ( deployment ) => {
		if ( preview.content?.[ deployment.content ] !== undefined ) {
			return preview.content[ deployment.content ];
		}
		const url = files
			? config.base + deployment.content
			: `${ config.rest }package/content/${ deployment.campaign }/${ deployment.version }`;
		const res = await win.fetch( url, { credentials: 'omit' } );
		return ( await res.json() ).html || '';
	};

	const handlers = ( deployment ) => ( {
		onClose: () => {
			store.record( deployment.campaign, 'dismiss', Date.now() );
			track( win, config.analytics, 'close', deployment );
			if ( INTERRUPTIVE.includes( deployment.format ) ) {
				interruptiveOpen = null;
			}
		},
		onGoal: () => convert( deployment ),
	} );

	const convert = ( deployment, counted = false ) => {
		track( win, config.analytics, 'conversion', deployment );
		store.record(
			deployment.campaign,
			'convert',
			Date.now(),
			visit.id,
			deployment.offer
		);
		// Form conversions are counted by the server when the lead is stored.
		if ( ! counted ) {
			beacon( deployment, 'conversion' );
		}
	};

	const open = async ( deployment, trigger, result, outranked ) => {
		const interruptive = INTERRUPTIVE.includes( deployment.format );
		if ( interruptive && interruptiveOpen ) {
			if ( interruptiveOpen.campaign === deployment.campaign ) {
				return;
			}
			// A click replaces the open campaign; that close is not a dismissal (BR-16).
			interruptiveOpen.handle.close( 'replaced' );
			interruptiveOpen = null;
		}
		const html = await content( deployment );
		const handle = show(
			deployment,
			html,
			handlers( deployment ),
			null,
			doc
		);
		credit( deployment, handle.element );
		if ( interruptive ) {
			interruptiveOpen = { campaign: deployment.campaign, handle };
			interruptiveUsed = true;
		}
		store.record( deployment.campaign, 'show', Date.now(), visit.id );
		explanations[ deployment.campaign ] = explain(
			deployment,
			result.checks,
			{ type: trigger, at: Date.now() },
			outranked
		);
		beacon( deployment, 'view' );
		opened.push( deployment.campaign );
	};

	const results = new Map();
	const eligible = [];
	for ( const deployment of deployments ) {
		const result = evaluate( deployment, context );
		results.set( deployment.campaign, result );
		if ( result.eligible ) {
			eligible.push( deployment );
		}
	}
	const selection = select( eligible );

	// Inline: fill placeholders for qualifying campaigns, hide the others.
	doc.querySelectorAll( '[data-cbandit-campaign]' ).forEach( ( el ) => {
		const id = el.getAttribute( 'data-cbandit-campaign' ).toUpperCase();
		const deployment = selection.inline.find( ( d ) => d.campaign === id );
		if ( ! deployment ) {
			el.hidden = true;
			return;
		}
		explanations[ id ] = explain(
			deployment,
			results.get( id ).checks,
			{ type: 'inline', at: Date.now() },
			[]
		);
		// The server already rendered this version into the slot; only fetch if it changed.
		const rendered =
			Number( el.getAttribute( 'data-cbandit-version' ) ) ===
				deployment.version && el.innerHTML.trim() !== '';
		( rendered ? Promise.resolve( el.innerHTML ) : content( deployment ) )
			.then( ( html ) => {
				const handle = show(
					deployment,
					html,
					handlers( deployment ),
					el,
					doc
				);
				credit( deployment, handle.element );
			} )
			.catch( () => {} );
		if ( typeof win.IntersectionObserver === 'function' ) {
			// A view counts once half of the inline campaign is visible (BR-15).
			const observer = new win.IntersectionObserver(
				( entries ) => {
					if ( entries.some( ( e ) => e.intersectionRatio >= 0.5 ) ) {
						observer.disconnect();
						store.record( id, 'show', Date.now(), visit.id );
						beacon( deployment, 'view' );
					}
				},
				{ threshold: 0.5 }
			);
			observer.observe( el );
		}
	} );

	// Interruptive and bar: arm the selected campaign's triggers.
	for ( const deployment of [ selection.interruptive, selection.bar ] ) {
		if ( ! deployment ) {
			continue;
		}
		const fire = ( type ) => {
			cancels.forEach( ( cancel ) => cancel() );
			if (
				INTERRUPTIVE.includes( deployment.format ) &&
				interruptiveUsed
			) {
				return;
			}
			open(
				deployment,
				type,
				results.get( deployment.campaign ),
				selection.outranked
			).catch( () => {} );
		};
		const cancels = [];
		if (
			deployment.triggers?.delaySeconds !== null &&
			deployment.triggers?.delaySeconds !== undefined
		) {
			cancels.push(
				afterVisibleTime(
					deployment.triggers.delaySeconds,
					() => fire( 'time' ),
					doc
				)
			);
		}
		if ( deployment.triggers?.scrollPercent ) {
			cancels.push(
				afterScroll(
					deployment.triggers.scrollPercent,
					() => fire( 'scroll' ),
					win
				)
			);
		}
		if ( deployment.triggers?.exitIntent ) {
			cancels.push( afterExitIntent( () => fire( 'exit_intent' ), win ) );
		}
		if ( deployment.triggers?.customEvent ) {
			cancels.push(
				onCustomEvent(
					deployment.triggers.customEvent,
					() => fire( 'custom_event' ),
					win
				)
			);
		}
	}

	// Click and hover: the visitor's own action on that campaign's element, so it
	// opens even when capped or dismissed, if it is live and targeted here (BR-16).
	const visitorOpens = ( event, kind ) => {
		const id = clickTarget( event.target );
		const deployment =
			id &&
			deployments.find(
				( d ) =>
					d.campaign === id &&
					( kind === 'click' ? d.triggers?.click : d.triggers?.hover )
			);
		if ( ! deployment ) {
			return;
		}
		const result = evaluate(
			deployment,
			{ ...context, now: Date.now() },
			{ byClick: true }
		);
		if ( result.eligible ) {
			if ( kind === 'click' ) {
				event.preventDefault();
			}
			open( deployment, kind, result, [] ).catch( () => {} );
		}
	};
	doc.addEventListener( 'click', ( event ) =>
		visitorOpens( event, 'click' )
	);
	doc.addEventListener( 'mouseover', ( event ) =>
		visitorOpens( event, 'hover' )
	);
	doc.addEventListener( 'focusin', ( event ) =>
		visitorOpens( event, 'hover' )
	);

	// Forms report a successful submission with this event (FR-23).
	win.addEventListener( 'campaignbandit:converted', ( event ) => {
		const deployment = deployments.find(
			( d ) => d.campaign === event.detail?.campaign
		);
		if ( deployment ) {
			convert( deployment );
		}
	} );

	attachForms( {
		doc,
		win,
		config,
		context: () => ( {
			params: context.params,
			device,
			pageGroup: group,
			explanation: ( id ) => explanations[ id ] || null,
			source: () =>
				sourceAllowed( consent, win ) ? forLead( store.source() ) : {},
		} ),
		onSuccess: ( id ) => {
			const deployment = deployments.find( ( d ) => d.campaign === id );
			if ( deployment ) {
				convert( deployment, true );
			}
		},
	} );

	win.campaignBandit = {
		explanation: ( campaign ) => explanations[ campaign ] || null,
		visitParams: () => context.params,
	};

	return { opened, selection, explanations };
}

/**
 * The visitor's country, asked once per visit and only when a rule needs it (BR-04).
 *
 * @param {Object}   config      Configuration.
 * @param {Window}   win         Window.
 * @param {Object[]} deployments Manifest entries.
 * @return {Promise<string>} Country code or 'unknown'.
 */
async function country( config, win, deployments ) {
	const none = { code: 'unknown', source: 'none' };
	if ( ! deployments.some( usesCountry ) ) {
		return none;
	}
	const session = storage( win, 'sessionStorage' );
	const cached = session?.getItem( 'campaignbandit:country' );
	if ( cached ) {
		const [ code, source ] = cached.split( '|' );
		return { code, source: source || 'none' };
	}
	try {
		const res = await win.fetch( config.rest + 'country', {
			credentials: 'omit',
		} );
		const body = await res.json();
		const result = {
			code: body.country || 'unknown',
			source: body.source || 'none',
		};
		session?.setItem(
			'campaignbandit:country',
			`${ result.code }|${ result.source }`
		);
		return result;
	} catch {
		return none;
	}
}

/**
 * Whether a campaign's rules ask for the visitor's country.
 *
 * @param {Object} deployment Manifest entry.
 * @return {boolean} Whether it does.
 */
function usesCountry( deployment ) {
	return JSON.stringify( deployment.condition || {} ).includes(
		'"type":"country"'
	);
}
