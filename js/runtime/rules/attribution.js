/**
 * Lead source (BR-47): where this browser came from, kept on this site only and sent
 * only with a form submission. UTM values, referrer domain, landing path, click IDs,
 * a visit count and the last 10 page paths. No personal data, no query strings.
 */

export const UTM_PARTS = [ 'source', 'medium', 'campaign', 'term', 'content' ];
export const CLICK_IDS = [ 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid' ];
export const SOURCE_TTL_MS = 90 * 24 * 60 * 60 * 1000;
const MAX_PAGES = 10;

const clip = ( value ) => String( value ?? '' ).slice( 0, 200 );

/**
 * The referring site's domain, or '' for direct and same-site visits.
 *
 * @param {string} referrer document.referrer.
 * @param {string} host     This site's host name.
 * @return {string} Domain without "www.".
 */
export function referrerDomain( referrer, host ) {
	try {
		const name = new URL( referrer ).hostname.toLowerCase();
		const self = String( host || '' ).toLowerCase();
		return name && name !== self
			? name.replace( /^www\./, '' ).slice( 0, 200 )
			: '';
	} catch {
		return '';
	}
}

/**
 * One visit's source.
 *
 * @param {Object<string,string>} params   This page's URL parameters.
 * @param {string}                referrer Referring domain.
 * @param {string}                path     Landing path.
 * @param {number}                now      Time (ms).
 * @return {Object} Touch.
 */
function touch( params, referrer, path, now ) {
	const utm = {};
	UTM_PARTS.forEach( ( part ) => {
		if ( params[ 'utm_' + part ] ) {
			utm[ part ] = clip( params[ 'utm_' + part ] );
		}
	} );
	return { utm, referrer, page: clip( path || '/' ), at: now };
}

/**
 * The stored source after this page view.
 *
 * @param {Object|null} stored        Stored source, or null.
 * @param {Object}      page          This page view.
 * @param {boolean}     page.newVisit Whether it starts a visit (BR-02).
 * @param {Object}      page.params   This page's URL parameters.
 * @param {string}      page.referrer Referring domain ('' for direct or same site).
 * @param {string}      page.path     Path, without the query string.
 * @param {number}      page.now      Time (ms).
 * @return {Object} Source to store.
 */
export function nextSource(
	stored,
	{ newVisit, params, referrer, path, now }
) {
	const fresh =
		stored && stored.last && now - ( stored.seen || 0 ) <= SOURCE_TTL_MS;
	const source = fresh ? { ...stored } : { visits: 0, pages: [], ids: {} };
	if ( newVisit || ! fresh ) {
		source.last = touch( params || {}, referrer || '', path, now );
		source.visits = ( source.visits || 0 ) + 1;
		source.first = source.first || source.last;
	}
	const ids = { ...source.ids };
	CLICK_IDS.forEach( ( name ) => {
		if ( params?.[ name ] ) {
			ids[ name ] = clip( params[ name ] );
		}
	} );
	source.ids = ids;
	const pages = [ ...( source.pages || [] ) ];
	const here = clip( path || '/' );
	if ( pages[ pages.length - 1 ] !== here ) {
		pages.push( here );
	}
	source.pages = pages.slice( -MAX_PAGES );
	source.seen = now;
	return source;
}

/**
 * The source as sent with a lead (BR-44 `source`).
 *
 * @param {Object|null} source Stored source.
 * @return {Object} Payload shape, or {} when nothing is recorded.
 */
export function forLead( source ) {
	if ( ! source?.last ) {
		return {};
	}
	const visit = ( t ) => ( {
		utm: t.utm || {},
		referrer: t.referrer || '',
		landing_page: t.page || '/',
		at: new Date( t.at || 0 ).toISOString(),
	} );
	return {
		first: visit( source.first || source.last ),
		last: visit( source.last ),
		click_ids: source.ids || {},
		visits: source.visits || 1,
		pages: source.pages || [],
	};
}
