/**
 * URL parameter rules (BR-02): name + operator + value. Names match exactly; values
 * ignore case. Parameters from the visit's landing page count for the whole visit.
 */

/**
 * Parameters that apply on this page: the landing page's, overridden by this page's.
 *
 * @param {Object<string,string>} landing Parameters remembered for the visit.
 * @param {Object<string,string>} current Parameters on this page.
 * @return {Object<string,string>} Effective parameters.
 */
export function effectiveParams( landing, current ) {
	return { ...( landing || {} ), ...( current || {} ) };
}

/**
 * Whether one rule matches.
 *
 * @param {{name: string, op: string, value?: string}} rule   Rule.
 * @param {Object<string,string>}                      params Effective parameters.
 * @return {boolean} Match.
 */
export function matchesParam( rule, params ) {
	const present = Object.prototype.hasOwnProperty.call( params, rule.name );
	const actual = present ? String( params[ rule.name ] ).toLowerCase() : '';
	const expected = String( rule.value ?? '' ).toLowerCase();

	switch ( rule.op ) {
		case 'equals':
			return present && actual === expected;
		case 'not_equals':
			return ! present || actual !== expected;
		case 'contains':
			return present && actual.includes( expected );
		case 'not_contains':
			return ! present || ! actual.includes( expected );
		case 'starts_with':
			return present && actual.startsWith( expected );
		case 'ends_with':
			return present && actual.endsWith( expected );
		case 'exists':
			return present;
		case 'not_exists':
			return ! present;
		default:
			return false;
	}
}

/**
 * Evaluate every parameter rule (AND).
 *
 * @param {Array<{name: string, op: string, value?: string}>} rules  Rules.
 * @param {Object<string,string>}                             params Effective parameters.
 * @return {{pass: boolean, detail: string}} Result.
 */
export function evaluateParams( rules, params ) {
	const list = rules || [];
	if ( list.length === 0 ) {
		return { pass: true, detail: 'No parameter rules' };
	}
	const failing = list.find( ( rule ) => ! matchesParam( rule, params ) );
	return failing
		? { pass: false, detail: `Parameter ${ failing.name } did not match` }
		: { pass: true, detail: 'All parameter rules matched' };
}

/**
 * Parse a query string into an object (first value wins).
 *
 * @param {string} search Query string, with or without `?`.
 * @return {Object<string,string>} Parameters.
 */
export function parseQuery( search ) {
	const out = {};
	new URLSearchParams( search || '' ).forEach( ( value, name ) => {
		if ( ! ( name in out ) ) {
			out[ name ] = value;
		}
	} );
	return out;
}
