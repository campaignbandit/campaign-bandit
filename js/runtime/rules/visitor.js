/**
 * Visitor-side facts: visits (BR-02), device (BR-03), country (BR-04, BR-05), schedule.
 */

export const VISIT_TIMEOUT_MS = 30 * 60 * 1000;

/**
 * Continue the current visit or start a new one (a visit ends after 30 minutes without a page view).
 *
 * @param {{id: string, lastSeen: number, params: Object}|null} visit         Stored visit.
 * @param {number}                                              now           Current time (ms).
 * @param {Object<string,string>}                               currentParams Parameters on this page.
 * @param {function(): string}                                  newId         Creates a visit ID.
 * @return {{id: string, lastSeen: number, params: Object, isNew: boolean}} Visit.
 */
export function nextVisit( visit, now, currentParams, newId ) {
	if ( visit && now - visit.lastSeen <= VISIT_TIMEOUT_MS ) {
		return { ...visit, lastSeen: now, isNew: false };
	}
	return {
		id: newId(),
		lastSeen: now,
		params: { ...currentParams },
		isNew: true,
	};
}

/**
 * Device class from the viewport width (BR-03).
 *
 * @param {number} width Viewport width in CSS pixels.
 * @return {'mobile'|'tablet'|'desktop'} Device.
 */
export function deviceFor( width ) {
	if ( width < 768 ) {
		return 'mobile';
	}
	return width < 1024 ? 'tablet' : 'desktop';
}

/**
 * Evaluate the device rule. No devices listed means every device.
 *
 * @param {string[]} devices Allowed devices.
 * @param {string}   device  Visitor's device.
 * @return {{pass: boolean, detail: string}} Result.
 */
export function evaluateDevice( devices, device ) {
	if ( ! devices || devices.length === 0 ) {
		return { pass: true, detail: 'All devices' };
	}
	return devices.includes( device )
		? { pass: true, detail: `Device ${ device }` }
		: { pass: false, detail: `Device ${ device } not targeted` };
}

/**
 * Evaluate the country rule (BR-05): `unknown` never matches an include or an exclude.
 *
 * @param {{mode: 'include'|'exclude', countries: string[]}|null} rule    Country rule.
 * @param {string}                                                country Two-letter country code, or 'unknown'.
 * @return {{pass: boolean, detail: string}} Result.
 */
export function evaluateCountry( rule, country ) {
	if ( ! rule || ! rule.countries || rule.countries.length === 0 ) {
		return { pass: true, detail: 'All countries' };
	}
	if ( ! country || country === 'unknown' ) {
		return {
			pass: rule.mode === 'exclude',
			detail: 'Country unknown',
		};
	}
	const listed = rule.countries
		.map( ( code ) => code.toUpperCase() )
		.includes( country.toUpperCase() );
	const pass = rule.mode === 'exclude' ? ! listed : listed;
	return {
		pass,
		detail: pass
			? `Country ${ country }`
			: `Country ${ country } not targeted`,
	};
}

const WEEKDAYS = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];

/**
 * The day of the week (0 = Sunday) at a moment, in the site's timezone (BR-08).
 *
 * @param {number}                                  now  Current time (ms).
 * @param {{timezone?: string, utcOffset?: number}} site Site timezone name and offset (minutes).
 * @return {number} Day of the week.
 */
export function siteWeekday( now, site = {} ) {
	const name = site.timezone || '';
	// Fixed offsets ("+02:00") and missing names use the published offset.
	if ( name && ! /^[+-]/.test( name ) ) {
		try {
			const short = new Intl.DateTimeFormat( 'en-US', {
				timeZone: name,
				weekday: 'short',
			} ).format( now );
			const day = WEEKDAYS.indexOf( short );
			if ( day >= 0 ) {
				return day;
			}
		} catch {
			// Unknown timezone in this browser: fall back to the offset.
		}
	}
	return new Date( now + ( site.utcOffset || 0 ) * 60000 ).getUTCDay();
}

/**
 * Evaluate one schedule. Times are UTC milliseconds, already converted from the
 * site's timezone when the package was written (decision 0009).
 *
 * @param {{start?: number|null, end?: number|null, days?: number[]}|null} schedule Schedule.
 * @param {number}                                                         now      Current time (ms).
 * @param {{timezone?: string, utcOffset?: number}}                        [site]   Site timezone.
 * @return {{pass: boolean, detail: string}} Result.
 */
export function evaluateSchedule( schedule, now, site = {} ) {
	if ( schedule?.start && now < schedule.start ) {
		return { pass: false, detail: 'Before schedule start' };
	}
	if ( schedule?.end && now >= schedule.end ) {
		return { pass: false, detail: 'After schedule end' };
	}
	const days = schedule?.days || [];
	if ( days.length && ! days.includes( siteWeekday( now, site ) ) ) {
		return { pass: false, detail: 'Not on a scheduled day' };
	}
	return { pass: true, detail: schedule ? 'Within schedule' : 'No schedule' };
}

/**
 * Evaluate a campaign's schedules (BR-08): none means any time; otherwise any one
 * applying is enough. When none applies, the first one's reason is reported.
 * With `hide`, the schedules are blackout periods instead.
 *
 * @param {Array<Object>|undefined}                 schedules Schedules.
 * @param {number}                                  now       Current time (ms).
 * @param {{timezone?: string, utcOffset?: number}} [site]    Site timezone.
 * @param {boolean}                                 [hide]    Hide when schedules apply.
 * @return {{pass: boolean, detail: string}} Result.
 */
export function evaluateSchedules( schedules, now, site = {}, hide = false ) {
	if ( ! schedules?.length ) {
		return { pass: true, detail: 'No schedule' };
	}
	const results = schedules.map( ( s ) => evaluateSchedule( s, now, site ) );
	const met = results.findIndex( ( r ) => r.pass );
	if ( hide ) {
		return met >= 0
			? {
					pass: false,
					detail:
						schedules.length > 1
							? `Hidden during schedule ${ met + 1 }`
							: 'Hidden during schedule',
				}
			: { pass: true, detail: 'Outside hidden schedules' };
	}
	if ( met >= 0 ) {
		return {
			pass: true,
			detail:
				schedules.length > 1
					? `Within schedule ${ met + 1 }`
					: 'Within schedule',
		};
	}
	return {
		pass: false,
		detail:
			schedules.length > 1
				? 'Outside every schedule'
				: results[ 0 ].detail,
	};
}
