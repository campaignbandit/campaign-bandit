/**
 * Frequency (BR-11), dismissal suppression (BR-12) and conversion suppression (BR-13),
 * from the visitor's own history of one campaign on this site.
 */

const DAY_MS = 24 * 60 * 60 * 1000;

/**
 * A campaign's history for this visitor.
 *
 * @typedef {Object} CampaignHistory
 * @property {number|null} lastShowAt    When it last became visible (ms).
 * @property {string|null} lastShowVisit Visit ID of that show.
 * @property {number|null} dismissedAt   When it was last dismissed (ms).
 * @property {number|null} convertedAt   When its goal happened (ms).
 */

/**
 * Evaluate the frequency cap. A show before `showsResetAt` (the publisher ticked
 * "Show again to visitors who already saw it") does not count.
 *
 * @param {{type: string, days?: number}} frequency    Cap: every_view, once_per_visit or every_n_days.
 * @param {CampaignHistory}               history      Visitor history.
 * @param {string}                        visitId      Current visit.
 * @param {number}                        now          Current time (ms).
 * @param {number|null}                   showsResetAt Reset point (ms), if any.
 * @return {{pass: boolean, detail: string}} Result.
 */
export function evaluateFrequency(
	frequency,
	history,
	visitId,
	now,
	showsResetAt
) {
	const lastShow =
		history?.lastShowAt &&
		( ! showsResetAt || history.lastShowAt >= showsResetAt )
			? history.lastShowAt
			: null;

	switch ( frequency?.type ) {
		case 'every_view':
			return { pass: true, detail: 'Shows on every page view' };
		case 'once_per_visit':
			return lastShow && history.lastShowVisit === visitId
				? { pass: false, detail: 'Already shown this visit' }
				: { pass: true, detail: 'Not shown this visit' };
		default: {
			const days = frequency?.days ?? 7;
			return lastShow && now - lastShow < days * DAY_MS
				? { pass: false, detail: `Shown in the last ${ days } days` }
				: {
						pass: true,
						detail: `Not shown in the last ${ days } days`,
					};
		}
	}
}

/**
 * Evaluate dismissal and conversion suppression, including "Hide from visitors who
 * already converted on a campaign with the same offer" (BR-13).
 *
 * @param {{dismissDays?: number, suppressAfterConversion?: boolean}} settings           Campaign settings.
 * @param {CampaignHistory}                                           history            Visitor history.
 * @param {number}                                                    now                Current time (ms).
 * @param {function(string): (number|null)}                           [offerConvertedAt] When the visitor converted on any campaign with this offer.
 * @return {{pass: boolean, detail: string}} Result.
 */
export function evaluateSuppression(
	settings,
	history,
	now,
	offerConvertedAt
) {
	if ( history?.convertedAt && settings?.suppressAfterConversion !== false ) {
		return { pass: false, detail: 'Visitor already converted' };
	}
	if (
		settings?.hideAfterSameOffer &&
		settings?.offer &&
		offerConvertedAt &&
		offerConvertedAt( settings.offer.toLowerCase() )
	) {
		return {
			pass: false,
			detail: `Visitor already converted on offer ${ settings.offer }`,
		};
	}
	const days = settings?.dismissDays ?? 30;
	if (
		history?.dismissedAt &&
		days > 0 &&
		now - history.dismissedAt < days * DAY_MS
	) {
		return { pass: false, detail: `Dismissed in the last ${ days } days` };
	}
	return { pass: true, detail: 'Not suppressed' };
}
