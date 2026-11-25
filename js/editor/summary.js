/**
 * The one-sentence summary at the top of the Campaign panel (S4), and plain-language
 * labels for rules. Pure functions, so they are unit-tested without React.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

export const FORMAT_LABELS = {
	modal: () => __( 'Modal', 'campaign-bandit' ),
	slide_in: () => __( 'Slide-in', 'campaign-bandit' ),
	bar: () => __( 'Sticky bar', 'campaign-bandit' ),
	inline: () => __( 'Inline', 'campaign-bandit' ),
};

/**
 * How the campaign opens, as a phrase.
 *
 * @param {Object} triggers Trigger settings.
 * @return {string} Phrase, or '' when no trigger is set.
 */
export function triggerPhrase( triggers = {} ) {
	const parts = [];
	if ( triggers.click ) {
		parts.push( __( 'on click', 'campaign-bandit' ) );
	}
	if (
		triggers.delay_seconds !== null &&
		triggers.delay_seconds !== undefined
	) {
		parts.push(
			sprintf(
				/* translators: %d: seconds. */
				__( 'after %d s', 'campaign-bandit' ),
				triggers.delay_seconds
			)
		);
	}
	if ( triggers.scroll_percent ) {
		parts.push(
			sprintf(
				/* translators: %d: percent of the page. */
				__( 'at %d%% scrolled', 'campaign-bandit' ),
				triggers.scroll_percent
			)
		);
	}
	if ( triggers.exit_intent ) {
		parts.push( __( 'on exit intent', 'campaign-bandit' ) );
	}
	if ( triggers.hover ) {
		parts.push( __( 'on hover', 'campaign-bandit' ) );
	}
	if ( triggers.custom_event ) {
		parts.push(
			sprintf(
				/* translators: %s: event name. */
				__( 'on the “%s” event', 'campaign-bandit' ),
				triggers.custom_event
			)
		);
	}
	return parts.join( ` ${ __( 'or', 'campaign-bandit' ) } ` );
}

/**
 * Display conditions, as a phrase.
 *
 * @param {Object} condition Condition (BR-06).
 * @return {string} Phrase.
 */
export function audiencePhrase( condition ) {
	const rules = ( condition?.groups || [] ).reduce(
		( total, group ) =>
			total +
			( group.rules || [] ).filter( ( rule ) => rule.type ).length,
		0
	);
	if ( rules === 0 ) {
		return __( 'everyone, on every page', 'campaign-bandit' );
	}
	const phrase = sprintf(
		/* translators: %d: number of rules. */
		_n(
			'visitors matching %d rule',
			'visitors matching %d rules',
			rules,
			'campaign-bandit'
		),
		rules
	);
	return condition.invert
		? sprintf(
				/* translators: %s: audience phrase. */
				__( 'everyone except %s', 'campaign-bandit' ),
				phrase
			)
		: phrase;
}

/**
 * How often, as a phrase.
 *
 * @param {Object} settings Campaign settings.
 * @return {string} Phrase.
 */
export function frequencyPhrase( settings ) {
	const f = settings.frequency || { type: 'every_n_days', days: 7 };
	if ( f.type === 'every_view' ) {
		return __( 'every page view', 'campaign-bandit' );
	}
	if ( f.type === 'once_per_visit' ) {
		return __( 'once per visit', 'campaign-bandit' );
	}
	const days = f.days ?? 7;
	if ( days === 1 ) {
		return __( 'once a day', 'campaign-bandit' );
	}
	return sprintf(
		/* translators: %d: number of days (2 or more). */
		_n(
			'once every %d day',
			'once every %d days',
			days,
			'campaign-bandit'
		),
		days
	);
}

/**
 * The whole setup in one sentence.
 *
 * @param {Object} settings Campaign settings.
 * @return {string} Sentence.
 */
export function summarise( settings = {} ) {
	const format = (
		FORMAT_LABELS[ settings.format ] || FORMAT_LABELS.modal
	)();
	const audience = audiencePhrase( settings.condition );
	if ( settings.format === 'inline' ) {
		return sprintf(
			/* translators: 1: format, 2: audience. */
			__(
				'%1$s · shows where it is placed, to %2$s.',
				'campaign-bandit'
			),
			format,
			audience
		);
	}
	const trigger = triggerPhrase( settings.triggers );
	return sprintf(
		/* translators: 1: format, 2: triggers, 3: audience, 4: frequency. */
		__( '%1$s · opens %2$s · for %3$s · at most %4$s.', 'campaign-bandit' ),
		format,
		trigger || __( '(choose a trigger)', 'campaign-bandit' ),
		audience,
		frequencyPhrase( settings )
	);
}
