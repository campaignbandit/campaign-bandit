/**
 * "Explain this page" (FR-34, decision 0023): each campaign on a site, and whether it
 * would show on a page for a first-time visitor with a chosen device, country and URL
 * parameters. It runs the visitor runtime's own rules, so the answer is what a
 * visitor would get. No real visitor's history is used.
 */

import { evaluate } from '../runtime/rules/eligibility.js';
import { select } from '../runtime/rules/selection.js';

/**
 * Explain a page.
 *
 * @param {Object}   manifest             The site's manifest (deployments and site).
 * @param {Object}   visit                The visit to judge.
 * @param {string}   visit.path           Page path.
 * @param {Object}   visit.params         URL parameters.
 * @param {string}   visit.device         mobile, tablet or desktop.
 * @param {string}   visit.country        Country code, or 'unknown'.
 * @param {Object}   visit.page           Page facts (decision 0011).
 * @param {number}   visit.now            Time to judge at (ms).
 * @param {string[]} [visit.inlinePlaced] Inline campaigns placed on the page.
 * @return {Array<Object>} One row per campaign, the ones that would show first.
 */
export function explainPage( manifest, visit ) {
	const deployments = manifest.deployments || [];
	const context = {
		now: visit.now,
		path: visit.path,
		params: visit.params || {},
		device: visit.device,
		country: visit.country || 'unknown',
		visitId: 'explain',
		defaultExclude: manifest.site?.defaultExclude || [],
		site: {
			timezone: manifest.site?.timezone || '',
			utcOffset: manifest.site?.utcOffset || 0,
		},
		page: visit.page || {},
		// A first-time visitor: nothing seen, dismissed or converted (FR-34).
		history: () => ( {} ),
		offerConvertedAt: () => null,
	};

	const results = new Map();
	const eligible = [];
	deployments.forEach( ( deployment ) => {
		const result = evaluate( deployment, context );
		results.set( deployment.campaign, result );
		if ( result.eligible ) {
			eligible.push( deployment );
		}
	} );
	const chosen = select( eligible );
	const outranked = new Map(
		chosen.outranked.map( ( entry ) => [
			entry.deployment.campaign,
			entry.by,
		] )
	);

	const rows = deployments.map( ( deployment ) => {
		const result = results.get( deployment.campaign );
		const by = outranked.get( deployment.campaign );
		let outcome = 'shows';
		let reason = '';
		if ( ! result.eligible ) {
			outcome = 'no';
			reason = result.reason || '';
		} else if ( by ) {
			outcome = 'outranked';
			reason = by.name || by.campaign;
		} else if (
			deployment.format === 'inline' &&
			visit.inlinePlaced &&
			! visit.inlinePlaced.includes( deployment.campaign )
		) {
			outcome = 'not_placed';
		}
		return {
			campaign: deployment.campaign,
			name: deployment.name || deployment.campaign,
			format: deployment.format,
			version: deployment.version,
			outcome,
			reason,
			checks: result.checks,
			opens: opens( deployment ),
		};
	} );
	const order = { shows: 0, not_placed: 1, outranked: 2, no: 3 };
	return rows.sort( ( a, b ) => order[ a.outcome ] - order[ b.outcome ] );
}

/**
 * When a campaign that qualifies opens: its triggers, in short codes the screen words.
 *
 * @param {Object} deployment Deployment.
 * @return {Array<{type: string, value?: (number|string)}>} Triggers.
 */
export function opens( deployment ) {
	if ( deployment.format === 'inline' ) {
		return [ { type: 'inline' } ];
	}
	const t = deployment.triggers || {};
	const out = [];
	if ( t.delaySeconds !== null && t.delaySeconds !== undefined ) {
		out.push( { type: 'time', value: t.delaySeconds } );
	}
	if ( t.scrollPercent ) {
		out.push( { type: 'scroll', value: t.scrollPercent } );
	}
	if ( t.exitIntent ) {
		out.push( { type: 'exit_intent' } );
	}
	if ( t.customEvent ) {
		out.push( { type: 'custom_event', value: t.customEvent } );
	}
	if ( t.click ) {
		out.push( { type: 'click' } );
	}
	if ( t.hover ) {
		out.push( { type: 'hover' } );
	}
	return out;
}

/**
 * URL parameters from a query string ("utm_source=google&ref=x"), with or without "?".
 *
 * @param {string} query Query string.
 * @return {Object<string,string>} Parameters.
 */
export function paramsFrom( query ) {
	const out = {};
	new URLSearchParams( String( query || '' ).replace( /^\?/, '' ) ).forEach(
		( value, key ) => {
			out[ key ] = value;
		}
	);
	return out;
}
