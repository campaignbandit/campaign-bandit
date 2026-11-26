/**
 * Whether a button counts as the campaign's goal, and how to stop it counting.
 *
 * Split out of button-actions.js, which is JSX, so these can be tested on their own —
 * the same arrangement as condition-rules.js beside conditions.js.
 */

/**
 * Whether this button is the campaign's goal.
 *
 * Two things mark one, because two things always have: the attribute the switch below
 * sets, and the `cbandit-goal` class, which is what the starter patterns carry and what
 * the docs tell people to put on their own markup. `ButtonActions::apply()` and the
 * runtime both accept either, so the switch has to as well — otherwise it reads "off"
 * on a button that is counting conversions.
 *
 * @param {Object} attributes Block attributes.
 * @return {boolean} Whether it counts as the goal.
 */
export function isGoal( attributes ) {
	if ( true === attributes?.cbanditGoal ) {
		return true;
	}
	return GOAL_CLASS.test( String( attributes?.className || '' ) );
}

/**
 * The class list without the goal marker, keeping whatever else is there.
 *
 * @param {string} className Class list.
 * @return {string|undefined} The rest, or undefined when nothing is left.
 */
export function withoutGoalClass( className ) {
	const rest = String( className || '' )
		.split( /\s+/ )
		.filter( ( name ) => name && 'cbandit-goal' !== name )
		.join( ' ' );
	return '' === rest ? undefined : rest;
}

const GOAL_CLASS = /(^|\s)cbandit-goal(\s|$)/;
