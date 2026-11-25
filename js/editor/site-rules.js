/**
 * Page rules that carry across sites (decision 0021). No WordPress dependencies, so
 * the rules are unit-tested.
 */

/**
 * Whether a rule names content a client site may not have: a parent page, a category
 * or tag, a custom post type, or a URL path. Front page, search, Page, Post and the
 * like work on every site.
 *
 * @param {Object} rule Rule.
 * @return {boolean} Whether to mark it on a hub.
 */
export function dependsOnSite( rule ) {
	if ( rule.type === 'path' ) {
		return true;
	}
	if ( rule.type !== 'page' ) {
		return false;
	}
	return ( rule.value || [] ).some( ( value ) => {
		const [ kind, type ] = String( value ).split( ':' );
		return (
			kind === 'term' ||
			kind === 'child_of' ||
			( kind === 'singular' &&
				!! type &&
				! [ 'post', 'page' ].includes( type ) )
		);
	} );
}

/**
 * A page's path from its link, relative to the site's home, lowercased, without a
 * trailing slash: `/services`. The same path a site reports for the page.
 *
 * @param {string|undefined} link     Page link.
 * @param {string}           homePath The site's home path, such as `/shop`, or ''.
 * @return {string} Path, or '' without a link.
 */
export function pagePath( link, homePath = '' ) {
	if ( ! link ) {
		return '';
	}
	try {
		const home = homePath.toLowerCase();
		let path = new URL( link ).pathname.toLowerCase().replace( /\/+$/, '' );
		if ( home && path.startsWith( home ) ) {
			path = path.slice( home.length );
		}
		return path || '/';
	} catch {
		return '';
	}
}
