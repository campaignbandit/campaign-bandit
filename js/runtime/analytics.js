/**
 * Analytics events (FR-62): tell the site's own Tag Manager or Google tag when a
 * campaign is shown, closed or converts. Campaign Bandit makes no request itself, and
 * events never carry form answers or consent. DOM events fire for developers whatever
 * the setting.
 */

/**
 * Report one event.
 *
 * @param {Window} win        Window.
 * @param {string} mode       'off', 'datalayer' or 'both'.
 * @param {string} type       'view', 'close' or 'conversion'.
 * @param {Object} deployment Manifest entry.
 */
export function track( win, mode, type, deployment ) {
	const detail = {
		campaign_id: deployment.campaign,
		campaign_name: deployment.name || '',
		campaign_version: deployment.version,
		campaign_format: deployment.format,
		offer: deployment.offer || '',
	};
	const name = 'campaign_bandit_' + type;
	try {
		if ( mode === 'datalayer' || mode === 'both' ) {
			win.dataLayer = win.dataLayer || [];
			win.dataLayer.push( { event: name, ...detail } );
			if ( mode === 'both' && typeof win.gtag === 'function' ) {
				win.gtag( 'event', name, detail );
			}
		}
		win.document.dispatchEvent(
			new win.CustomEvent( 'cbandit:' + type, { detail } )
		);
	} catch {
		// Analytics never breaks the page.
	}
}
