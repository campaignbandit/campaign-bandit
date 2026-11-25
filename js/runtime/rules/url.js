/**
 * URL rules (BR-01): patterns match the path, `*` matches any characters
 * (within and across segments), matching ignores a trailing slash and case.
 * No include patterns means every page; an exclude match always wins.
 */

/**
 * Normalise a path: lowercase, leading slash, no trailing slash (except "/").
 *
 * @param {string} path Path or pattern.
 * @return {string} Normalised path.
 */
export function normalisePath( path ) {
	let value = String( path || '' )
		.trim()
		.toLowerCase();
	if ( ! value.startsWith( '/' ) ) {
		value = '/' + value;
	}
	if ( value.length > 1 && value.endsWith( '/' ) ) {
		value = value.replace( /\/+$/, '' ) || '/';
	}
	return value;
}

/**
 * Whether a path matches one pattern.
 *
 * @param {string} pattern Pattern such as `/blog/*`.
 * @param {string} path    Page path.
 * @return {boolean} Match.
 */
export function matchesPattern( pattern, path ) {
	const source = normalisePath( pattern )
		.split( '*' )
		.map( ( part ) => part.replace( /[.+?^${}()|[\]\\]/g, '\\$&' ) )
		.join( '.*' );
	return new RegExp( '^' + source + '$' ).test( normalisePath( path ) );
}

/**
 * Evaluate a campaign's URL rules for a path.
 *
 * @param {{include?: string[], exclude?: string[]}} rules            Campaign URL rules.
 * @param {string}                                   path             Page path.
 * @param {string[]}                                 [defaultExclude] Site default exclusions (BR-01), when the campaign keeps them.
 * @return {{pass: boolean, detail: string}} Result.
 */
export function evaluateUrl( rules, path, defaultExclude = [] ) {
	const include = rules?.include || [];
	const exclude = [ ...( rules?.exclude || [] ), ...defaultExclude ];

	const excluded = exclude.find( ( pattern ) =>
		matchesPattern( pattern, path )
	);
	if ( excluded ) {
		return { pass: false, detail: `Excluded by ${ excluded }` };
	}
	if ( include.length === 0 ) {
		return { pass: true, detail: 'All pages' };
	}
	const included = include.find( ( pattern ) =>
		matchesPattern( pattern, path )
	);
	return included
		? { pass: true, detail: `Included by ${ included }` }
		: { pass: false, detail: 'Not in included pages' };
}
