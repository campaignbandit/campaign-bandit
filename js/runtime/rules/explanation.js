/**
 * Display explanation record (BR-14): every check passed, the trigger that fired
 * and when, and the campaigns this one outranked. Attached to leads.
 */

/**
 * Build the explanation for a campaign that was shown.
 *
 * @param {Object}                                  deployment Shown deployment.
 * @param {Array<{check: string, detail: string}>}  checks     Checks it passed.
 * @param {{type: string, at: number}}              trigger    Trigger that opened it.
 * @param {Array<{deployment: Object, by: Object}>} outranked  Outranked deployments from selection.
 * @return {Object} Explanation record.
 */
export function explain( deployment, checks, trigger, outranked ) {
	return {
		campaign: deployment.campaign,
		version: deployment.version,
		deployment: deployment.deployment,
		checks: checks.map( ( { check, detail } ) => ( { check, detail } ) ),
		trigger: {
			type: trigger.type,
			label: trigger.type === 'click' ? 'Opened by click' : trigger.type,
			at: new Date( trigger.at ).toISOString(),
		},
		outranked: outranked
			.filter( ( entry ) => entry.by.campaign === deployment.campaign )
			.map( ( entry ) => ( {
				campaign: entry.deployment.campaign,
				name: entry.deployment.name,
			} ) ),
	};
}
