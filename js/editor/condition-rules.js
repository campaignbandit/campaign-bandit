/**
 * Rules about rules, without any markup, so they can be tested on their own — the same
 * split as appearance-rules.js beside appearance.js.
 */

/**
 * Whether a rule has nothing to act on yet: a URL path with no path, a parameter with
 * no name, a list of values that is empty, a saved condition not chosen.
 *
 * `Condition::normalise()` drops a rule like this when the draft is saved, and a
 * dropped rule widens the audience instead of narrowing it — drop the only rule a
 * campaign has and "show on this URL" quietly becomes "show on every page". By the time
 * it reaches the server the rule is already gone, so the warning has to happen here.
 *
 * These cases mirror what that method refuses to keep; if it changes, this should too.
 *
 * @param {Object} rule Rule.
 * @return {boolean} Whether it will be ignored.
 */
export function ruleIsIncomplete( rule ) {
	const value = rule?.value;
	const filled = ( list ) =>
		( Array.isArray( list ) ? list : [] ).some( ( v ) =>
			String( v ?? '' ).trim()
		);

	switch ( rule?.type ) {
		case 'path':
			return ! String( value ?? '' ).trim();
		case 'param':
			return ! String( rule.name ?? '' ).trim();
		case 'country':
		case 'device':
		case 'page':
			return ! filled( value );
		case 'condition':
			return ! String( rule.id ?? '' ).trim();
		case '':
		case undefined:
			return true;
		default:
			// A type this doesn't know: better to say nothing than to warn wrongly.
			return false;
	}
}
