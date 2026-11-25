/**
 * Visitor state, kept in this site's localStorage (decision 0009). Holds the
 * current visit, each campaign's history, offer conversions and the lead source
 * (BR-47). No personal data.
 */

export const STORAGE_KEY = 'campaignbandit:v1';

/**
 * Create a state store over a Storage object.
 *
 * @param {Storage|null} storage localStorage, or null when unavailable (private mode, blocked).
 * @return {Object} Store.
 */
export function createStore( storage ) {
	let data = { visit: null, campaigns: {}, offers: {} };
	try {
		const saved = storage && JSON.parse( storage.getItem( STORAGE_KEY ) );
		if ( saved && typeof saved === 'object' ) {
			data = {
				visit: saved.visit || null,
				campaigns: saved.campaigns || {},
				offers: saved.offers || {},
			};
			if ( saved.source ) {
				data.source = saved.source;
			}
		}
	} catch {
		// Unreadable state starts fresh: the worst case is a campaign showing again.
	}

	const save = () => {
		try {
			storage?.setItem( STORAGE_KEY, JSON.stringify( data ) );
		} catch {
			// Storage full or blocked: keep working in memory for this page view.
		}
	};

	return {
		visit: () => data.visit,
		setVisit( visit ) {
			data.visit = {
				id: visit.id,
				lastSeen: visit.lastSeen,
				params: visit.params,
			};
			save();
		},
		source: () => data.source || null,
		setSource( source ) {
			data.source = source;
			save();
		},
		history: ( campaign ) => data.campaigns[ campaign ] || {},
		offerConvertedAt: ( offer ) => data.offers[ offer ] ?? null,
		record( campaign, event, now, visitId, offer ) {
			const entry = { ...( data.campaigns[ campaign ] || {} ) };
			if ( event === 'show' ) {
				entry.lastShowAt = now;
				entry.lastShowVisit = visitId;
			} else if ( event === 'dismiss' ) {
				entry.dismissedAt = now;
			} else if ( event === 'convert' ) {
				entry.convertedAt = now;
				if ( offer ) {
					data.offers[ offer.toLowerCase() ] = now;
				}
			}
			data.campaigns[ campaign ] = entry;
			save();
		},
	};
}
