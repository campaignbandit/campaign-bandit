/**
 * Does a deployment qualify on this page view? Checks run in BR order and the
 * first failing check is the reason recorded (BR-14).
 */

import { evaluateUrl } from './url.js';
import { evaluateSchedules } from './visitor.js';
import { evaluateCondition } from './conditions.js';
import { evaluateFrequency, evaluateSuppression } from './history.js';

export const INTERRUPTIVE = [ 'modal', 'slide_in' ];

/**
 * Page view context.
 *
 * @typedef {Object} PageContext
 * @property {number}                                now                Current time (ms).
 * @property {string}                                path               Page path.
 * @property {Object<string,string>}                 params             Effective URL parameters (BR-02).
 * @property {string}                                device             mobile, tablet or desktop.
 * @property {string}                                country            Country code or 'unknown'.
 * @property {string}                                visitId            Current visit ID.
 * @property {string[]}                              defaultExclude     Site default exclusions (BR-01).
 * @property {{timezone: string, utcOffset: number}} [site]             Site timezone, for days of the week (BR-08).
 * @property {function(string): Object}              history            Visitor history for a campaign ID.
 * @property {function(string): ?number}             [offerConvertedAt] When the visitor converted on an offer (lowercase).
 * @property {Object}                                page               Page facts printed by the server (decision 0011).
 */

/**
 * Evaluate one deployment.
 *
 * @param {Object}      deployment        Manifest entry (decision 0009).
 * @param {PageContext} context           Page view context.
 * @param {Object}      [options]         Options.
 * @param {boolean}     [options.byClick] Opened by the visitor's click: skip suppression and frequency (BR-16).
 * @return {{eligible: boolean, reason: string|null, checks: Array<{check: string, pass: boolean, detail: string}>, converted: boolean}} Result.
 */
export function evaluate( deployment, context, { byClick = false } = {} ) {
	const rules = deployment.rules || {};
	const history = context.history( deployment.campaign ) || {};
	const inline = deployment.format === 'inline';
	const checks = [];

	const steps = [
		[
			'live',
			() =>
				deployment.state === 'live'
					? { pass: true, detail: 'Live' }
					: {
							pass: false,
							detail: `Deployment is ${ deployment.state }`,
						},
		],
		[
			'schedule',
			() =>
				evaluateSchedules(
					// Packages written before BR-08 carry a single schedule.
					rules.schedules ||
						( rules.schedule ? [ rules.schedule ] : [] ),
					context.now,
					context.site,
					rules.hideDuringSchedules === true
				),
		],
		[
			'site_exclusions',
			() =>
				evaluateUrl(
					{},
					context.path,
					rules.defaultExcludes === false
						? []
						: context.defaultExclude
				),
		],
		[
			'conditions',
			() => evaluateCondition( deployment.condition, context ),
		],
	];

	// Inline campaigns ignore suppression and frequency (BR-10); clicks do too (BR-16).
	if ( ! byClick && ! inline ) {
		steps.push(
			[
				'suppression',
				() =>
					evaluateSuppression(
						deployment,
						history,
						context.now,
						context.offerConvertedAt
					),
			],
			[
				'frequency',
				() =>
					evaluateFrequency(
						deployment.frequency,
						history,
						context.visitId,
						context.now,
						deployment.showsResetAt
					),
			]
		);
	}

	for ( const [ check, run ] of steps ) {
		const result = run();
		checks.push( { check, ...result } );
		if ( ! result.pass ) {
			return {
				eligible: false,
				reason: result.detail,
				checks,
				converted: false,
			};
		}
	}

	return {
		eligible: true,
		reason: null,
		checks,
		converted: inline && Boolean( history.convertedAt ),
	};
}
