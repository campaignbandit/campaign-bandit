/**
 * Selection (BR-10): at most one interruptive campaign (modal, slide-in) is armed per
 * page view, and at most one sticky bar; inline campaigns all show where placed.
 */

import { INTERRUPTIVE } from './eligibility.js';

/**
 * Order two deployments: higher priority first, then most recently published,
 * then the lower campaign ID (ULIDs compare as strings).
 *
 * @param {Object} a Deployment.
 * @param {Object} b Deployment.
 * @return {number} Sort order.
 */
export function compareDeployments( a, b ) {
	const priority = ( b.priority ?? 0 ) - ( a.priority ?? 0 );
	if ( priority !== 0 ) {
		return priority;
	}
	const published = ( b.publishedAt ?? 0 ) - ( a.publishedAt ?? 0 );
	if ( published !== 0 ) {
		return published;
	}
	if ( a.campaign === b.campaign ) {
		return 0;
	}
	return a.campaign < b.campaign ? -1 : 1;
}

/**
 * Pick the winner of one group and record the others as outranked.
 *
 * @param {Object[]} group Eligible deployments of one kind.
 * @return {{winner: Object|null, outranked: Array<{deployment: Object, by: Object}>}} Result.
 */
function pickOne( group ) {
	if ( group.length === 0 ) {
		return { winner: null, outranked: [] };
	}
	const [ winner, ...rest ] = [ ...group ].sort( compareDeployments );
	return {
		winner,
		outranked: rest.map( ( deployment ) => ( { deployment, by: winner } ) ),
	};
}

/**
 * Select what to arm on this page view from the eligible deployments.
 *
 * @param {Object[]} eligible Eligible deployments.
 * @return {{interruptive: Object|null, bar: Object|null, inline: Object[], outranked: Array<{deployment: Object, by: Object}>}} Selection.
 */
export function select( eligible ) {
	const interruptive = pickOne(
		eligible.filter( ( d ) => INTERRUPTIVE.includes( d.format ) )
	);
	const bar = pickOne( eligible.filter( ( d ) => d.format === 'bar' ) );

	return {
		interruptive: interruptive.winner,
		bar: bar.winner,
		inline: eligible.filter( ( d ) => d.format === 'inline' ),
		outranked: [ ...interruptive.outranked, ...bar.outranked ],
	};
}
