/**
 * Cookie consent (BR-48), read in the visitor's browser from the site's own consent
 * tool. Only the lead source waits for marketing consent; campaigns show either way.
 */

/**
 * Whether the visitor has given marketing consent: true or false, or null when no
 * consent tool can be read on the page (yet).
 *
 * @param {Window} win Window.
 * @return {boolean|null} Consent.
 */
export function marketingConsent( win ) {
	try {
		// The WP Consent API (Complianz, CookieYes and others support it).
		if ( typeof win.wp_has_consent === 'function' ) {
			return Boolean( win.wp_has_consent( 'marketing' ) );
		}
		if ( typeof win.cmplz_has_consent === 'function' ) {
			return Boolean( win.cmplz_has_consent( 'marketing' ) );
		}
		if ( win.Cookiebot?.consent ) {
			return Boolean( win.Cookiebot.consent.marketing );
		}
		if ( typeof win.getCkyConsent === 'function' ) {
			return Boolean( win.getCkyConsent()?.categories?.advertisement );
		}
	} catch {
		// A consent tool that fails to answer counts as no answer.
	}
	return null;
}

/**
 * Whether the lead source may be kept (BR-48).
 *
 * - never: always.
 * - always: only with marketing consent.
 * - auto (default): with a consent tool (on the page, or one the site has that hasn't
 *   answered yet), only with marketing consent; without one, always.
 *
 * @param {{mode?: string, tool?: string}} setting Site setting and its consent tool.
 * @param {Window}                         win     Window.
 * @return {boolean} Allowed.
 */
export function sourceAllowed( setting, win ) {
	const mode = setting?.mode || 'auto';
	if ( mode === 'never' ) {
		return true;
	}
	const given = marketingConsent( win );
	if ( mode === 'always' ) {
		return given === true;
	}
	return given === null ? ! setting?.tool : given;
}

/**
 * Call back when the visitor's consent may have changed: the WP Consent API's event
 * and the consent tools' own.
 *
 * @param {Window}     win      Window.
 * @param {() => void} callback Callback.
 */
export function onConsentChange( win, callback ) {
	[
		[ win.document, 'wp_listen_for_consent_change' ],
		[ win.document, 'cmplz_status_change' ],
		[ win, 'CookiebotOnConsentReady' ],
		[ win, 'CookiebotOnAccept' ],
		[ win.document, 'cookieyes_consent_update' ],
	].forEach( ( [ target, name ] ) =>
		target?.addEventListener?.( name, callback )
	);
}
