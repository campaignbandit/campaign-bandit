/**
 * Conditions (BR-06, decision 0011): groups of sentence-style rules combined with
 * all (AND) or any (OR), optionally inverted. Saved conditions arrive already
 * copied into the version (BR-07), as rules of type `condition`.
 */

import { matchesPattern, normalisePath } from './url.js';
import { matchesParam } from './params.js';

/**
 * What the runtime knows about this page view.
 *
 * @typedef {Object} Facts
 * @property {string}                path    Page path.
 * @property {Object<string,string>} params  Effective URL parameters (BR-02).
 * @property {string}                device  mobile, tablet or desktop.
 * @property {string}                country Two-letter code or 'unknown'.
 * @property {Object}                page    Page facts printed by the server.
 */

/**
 * Evaluate a page rule value against the page facts (BR-01).
 *
 * @param {string} value Rule value, such as `front`, `singular:post`, `term:category:news`, `child_of:12`.
 * @param {Object} page  Page facts.
 * @return {boolean} Whether the page is that.
 */
export function pageIs( value, page = {} ) {
	// One lookup table: every case reads `kind`, several read `arg`.
	/* eslint-disable @wordpress/no-unused-vars-before-return */
	const [ kind, ...rest ] = String( value ).split( ':' );
	const arg = rest.join( ':' );
	/* eslint-enable @wordpress/no-unused-vars-before-return */
	switch ( kind ) {
		case 'front':
			return Boolean( page.front );
		case 'posts_page':
			return Boolean( page.postsPage );
		case 'archive':
			return Boolean( page.archive );
		case 'search':
			return Boolean( page.search );
		case '404':
			return Boolean( page.notFound );
		case 'singular':
			return (
				Boolean( page.singular ) && ( ! arg || page.postType === arg )
			);
		case 'term':
			return ( page.terms || [] ).includes( arg );
		case 'child_of':
			// By path (decision 0021), or by ID for rules made before.
			return arg.startsWith( '/' )
				? ( page.ancestorPaths || [] ).includes( arg )
				: ( page.ancestors || [] ).map( String ).includes( arg );
		default:
			return false;
	}
}

/**
 * Evaluate a URL path rule (BR-01).
 *
 * @param {string} op    Operator.
 * @param {string} value Path or pattern.
 * @param {string} path  Page path.
 * @return {boolean} Match.
 */
export function pathMatches( op, value, path ) {
	/* eslint-disable @wordpress/no-unused-vars-before-return */
	const actual = normalisePath( path );
	const expected = normalisePath( value );
	const raw = String( value || '' ).toLowerCase();
	/* eslint-enable @wordpress/no-unused-vars-before-return */
	switch ( op ) {
		case 'is':
			return actual === expected;
		case 'is_not':
			return actual !== expected;
		case 'starts_with':
			return actual.startsWith( expected );
		case 'contains':
			return actual.includes( raw );
		case 'not_contains':
			return ! actual.includes( raw );
		case 'matches':
			return matchesPattern( value, path );
		default:
			return false;
	}
}

/**
 * Evaluate one rule.
 *
 * @param {Object} rule  Rule: type, op, value (and name for URL parameters).
 * @param {Facts}  facts Page view facts.
 * @return {{pass: boolean, unknownCountry?: boolean}} Result.
 */
export function evaluateRule( rule, facts ) {
	const list = Array.isArray( rule.value ) ? rule.value : [ rule.value ];
	switch ( rule.type ) {
		case 'page': {
			const hit = list.some( ( v ) => pageIs( v, facts.page ) );
			return { pass: rule.op === 'is_not' ? ! hit : hit };
		}
		case 'path':
			return { pass: pathMatches( rule.op, rule.value, facts.path ) };
		case 'param':
			return { pass: matchesParam( rule, facts.params || {} ) };
		case 'device': {
			const hit = list.includes( facts.device );
			return { pass: rule.op === 'is_not' ? ! hit : hit };
		}
		case 'country': {
			// `unknown` never matches either way (BR-05).
			if ( ! facts.country || facts.country === 'unknown' ) {
				return { pass: rule.op === 'is_not', unknownCountry: true };
			}
			const hit = list
				.map( ( c ) => String( c ).toUpperCase() )
				.includes( facts.country.toUpperCase() );
			return { pass: rule.op === 'is_not' ? ! hit : hit };
		}
		case 'condition':
			return { pass: evaluateCondition( rule.condition, facts ).pass };
		default:
			return { pass: false };
	}
}

/**
 * A rule as a short sentence, for the display explanation (BR-14).
 *
 * @param {Object} rule Rule.
 * @return {string} Sentence.
 */
export function describeRule( rule ) {
	const op = String( rule.op || '' ).replace( /_/g, ' ' );
	const value = Array.isArray( rule.value )
		? rule.value.join( ', ' )
		: ( rule.value ?? '' );
	switch ( rule.type ) {
		case 'param':
			return `URL parameter ${ rule.name } ${ op } ${ value }`.trim();
		case 'path':
			return `URL path ${ op } ${ value }`;
		case 'condition':
			return `Saved condition ${ rule.name || '' }`.trim();
		default:
			return `${
				rule.type.charAt( 0 ).toUpperCase() + rule.type.slice( 1 )
			} ${ op } ${ value }`;
	}
}

/**
 * Evaluate a group of rules.
 *
 * @param {{logic: string, rules: Object[]}} group Group.
 * @param {Facts}                            facts Facts.
 * @return {{pass: boolean, failed: Object|null, unknownCountry: boolean}} Result.
 */
function evaluateGroup( group, facts ) {
	const rules = group.rules || [];
	let unknownCountry = false;
	let failed = null;
	let anyPass = false;
	for ( const rule of rules ) {
		const result = evaluateRule( rule, facts );
		unknownCountry = unknownCountry || Boolean( result.unknownCountry );
		if ( result.pass ) {
			anyPass = true;
		} else if ( ! failed ) {
			failed = rule;
		}
	}
	const pass =
		rules.length === 0 || ( group.logic === 'any' ? anyPass : ! failed );
	return { pass, failed: pass ? null : failed, unknownCountry };
}

/**
 * Evaluate a whole condition (BR-06).
 *
 * @param {{logic?: string, invert?: boolean, groups?: Object[]}|null} condition Condition.
 * @param {Facts}                                                      facts     Facts.
 * @return {{pass: boolean, detail: string}} Result, with a sentence for the explanation.
 */
export function evaluateCondition( condition, facts ) {
	const groups = ( condition?.groups || [] ).filter(
		( g ) => ( g.rules || [] ).length > 0
	);
	if ( groups.length === 0 ) {
		return { pass: true, detail: 'Everyone, on every page' };
	}

	const results = groups.map( ( g ) => evaluateGroup( g, facts ) );
	// Rule sets combine with OR unless the condition says "all" (BR-06).
	const met =
		condition.logic === 'all'
			? results.every( ( r ) => r.pass )
			: results.some( ( r ) => r.pass );
	const pass = condition.invert ? ! met : met;
	const unknown = results.some( ( r ) => r.unknownCountry );

	if ( pass ) {
		return {
			pass,
			detail: unknown
				? 'Conditions met (country unknown)'
				: 'Conditions met',
		};
	}
	if ( condition.invert ) {
		return { pass, detail: 'Inverted condition was met' };
	}
	const firstFailed = results.find( ( r ) => r.failed )?.failed;
	let detail = firstFailed
		? `Not met: ${ describeRule( firstFailed ) }`
		: 'Conditions not met';
	if ( unknown ) {
		detail += ' (country unknown)';
	}
	return { pass, detail };
}
