/**
 * Campaign editor sidebar (FR-25, S4): campaign settings and Campaign Bandit's
 * Publish flow. Settings are stored as JSON in the `_campaignbandit_settings` meta;
 * the server normalises them (CampaignSettings).
 */
import { registerPlugin } from '@wordpress/plugins';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEntityProp } from '@wordpress/core-data';
import { dispatch, select, useDispatch, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import {
	createPortal,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	RadioControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { summarise } from './summary';
import { useHeaderSlot } from './header-slot';
import { PreviewLive } from './preview-live';
import { Stage } from './stage';
import { AppearancePanel, PatternDefaults } from './appearance';
import { ConditionBuilder } from './conditions';
import { CopyVariablesPanel } from './copy-variables';
import './button-actions';
import {
	CopyField,
	Field,
	HelpTip,
	InlineNumber,
	OptionsMenu,
	RemoveButton,
	Section,
	Segmented,
	proBadge,
	useOptional,
} from './ui';
import './editor.css';

const POST_TYPE = 'cbandit_campaign';
const config = window.campaignBanditEditor || { locked: {}, plan: 'free' };
const isLocked = ( control ) => Boolean( config.locked?.[ control ] );

/**
 * A label with the plan that unlocks it, when locked (FR-25).
 *
 * @param {string} label   Label.
 * @param {string} control Control ID.
 * @return {string} Label.
 */
const pro = ( label, control ) =>
	isLocked( control )
		? `${ label } (${ __( 'Pro', 'campaign-bandit' ) })`
		: label;

/**
 * Read and write the campaign settings stored in post meta.
 *
 * @return {[Object, (patch: Object) => void, string]} Settings, an updater that merges a patch, and the campaign ID.
 */
function useSettings() {
	const [ meta, setMeta ] = useEntityProp( 'postType', POST_TYPE, 'meta' );
	const [ uid ] = useEntityProp( 'postType', POST_TYPE, 'campaign_uid' );
	let settings = {};
	try {
		settings = JSON.parse( meta?._campaignbandit_settings || '{}' ) || {};
	} catch {
		settings = {};
	}
	const update = ( patch ) =>
		setMeta( {
			...meta,
			_campaignbandit_settings: JSON.stringify( {
				...settings,
				...patch,
			} ),
		} );
	return [ settings, update, uid || '' ];
}

/**
 * The campaign's publish status from the server, refreshed after each save.
 *
 * @param {string} uid Campaign ID.
 * @return {[Object|null, () => void]} Status and a refresh function.
 */
function usePublishStatus( uid ) {
	const [ status, setStatus ] = useState( null );
	const isSaving = useSelect(
		( sel ) => sel( editorStore ).isSavingPost(),
		[]
	);
	const latest = useRef( 0 );
	const refresh = useCallback( () => {
		if ( ! uid ) {
			return;
		}
		// Requests can finish out of order (a save and a publish overlap): keep only the newest.
		const request = ++latest.current;
		apiFetch( {
			path: `/${ config.namespace }/campaigns/${ uid }/status`,
		} )
			.then(
				( result ) => request === latest.current && setStatus( result )
			)
			.catch( () => request === latest.current && setStatus( null ) );
	}, [ uid ] );

	useEffect( () => {
		if ( ! isSaving ) {
			refresh();
		}
	}, [ isSaving, refresh ] );
	return [ status, refresh ];
}

/**
 * Pre-publish checks. They come from the last save; the trigger check follows the
 * sidebar right away, so choosing a trigger clears it before the next save (FR-31, FR-32).
 *
 * @param {Object|null} status   Publish status.
 * @param {Object}      settings Campaign settings.
 * @return {Object[]} Checks.
 */
function publishChecks( status, settings ) {
	const hasTrigger =
		settings.format === 'inline' ||
		TRIGGERS.some( ( tr ) => tr.isSet( settings.triggers || {} ) );
	const checks = ( status?.checks || [] ).filter(
		( c ) => c.id !== 'no_trigger' || ! hasTrigger
	);
	if (
		status &&
		! hasTrigger &&
		! checks.some( ( c ) => c.id === 'no_trigger' )
	) {
		checks.unshift( {
			id: 'no_trigger',
			severity: 'error',
			message: __(
				'Choose a trigger, such as on click, after a delay or on scroll.',
				'campaign-bandit'
			),
		} );
	}
	return checks;
}

const PUBLISH_PANEL = 'campaign-bandit/campaign-bandit-publish';

/**
 * Review & publish in the top bar, beside Save draft (S4). On a hub it saves and
 * opens the Publish screen, where sites are chosen (S5); on a single site it opens
 * the Publish panel, which holds the checks and the Publish button. With check
 * errors it shows their count and stays usable: Publish itself is what blocks.
 */
function ReviewPublish() {
	const [ settings, , uid ] = useSettings();
	const [ status ] = usePublishStatus( uid );
	const [ busy, setBusy ] = useState( false );
	const { savePost } = useDispatch( editorStore );
	const slot = useHeaderSlot();
	if ( ! slot || ! status ) {
		return null;
	}
	// An Editor writes drafts but never publishes, so they ask instead (S4, BR-70).
	if ( ! status.can_publish ) {
		return createPortal( <RequestPublish uid={ uid } />, slot );
	}
	const errors = publishChecks( status, settings ).filter(
		( c ) => c.severity === 'error'
	);

	const review = async () => {
		if ( config.publishScreen ) {
			setBusy( true );
			try {
				await savePost();
			} finally {
				setBusy( false );
			}
			window.location.href =
				config.publishScreen + encodeURIComponent( uid );
			return;
		}
		dispatch( 'core/edit-post' )?.openGeneralSidebar?.(
			'edit-post/document'
		);
		if ( ! select( editorStore ).isEditorPanelOpened( PUBLISH_PANEL ) ) {
			dispatch( editorStore ).toggleEditorPanelOpened( PUBLISH_PANEL );
		}
		// Once the sidebar has drawn the panel, bring it into view and focus Publish.
		window.setTimeout( () => {
			const panel = document.querySelector( '.cbandit-publish-actions' );
			if ( panel ) {
				panel.scrollIntoView( { block: 'nearest' } );
				panel.querySelector( 'button' )?.focus();
			}
		}, 150 );
	};

	const label = __( 'Review & publish', 'campaign-bandit' );
	const count = errors.length
		? sprintf(
				/* translators: %d: number of checks with errors. */
				__( '%d to fix', 'campaign-bandit' ),
				errors.length
			)
		: '';
	return createPortal(
		<Button
			variant="primary"
			className="cbandit-review-publish"
			isBusy={ busy }
			disabled={ busy || ! uid }
			accessibleWhenDisabled
			onClick={ review }
			aria-label={ count ? `${ label } (${ count })` : undefined }
			__next40pxDefaultSize={ false }
			size="compact"
		>
			{ label }
			{ count && (
				<span className="cbandit-review-publish__count">{ count }</span>
			) }
		</Button>,
		slot
	);
}

/**
 * "Request publish" in place of Review & publish, for someone who may not publish
 * (S4, BR-70). Asks the people who can, and says so once it has.
 *
 * @param {Object} props     Props.
 * @param {string} props.uid Campaign ID.
 * @return {Element} Button.
 */
function RequestPublish( { uid } ) {
	const [ busy, setBusy ] = useState( false );
	const [ sent, setSent ] = useState( false );
	const { savePost } = useDispatch( editorStore );
	const { createNotice } = useDispatch( noticesStore );

	const ask = async () => {
		setBusy( true );
		try {
			await savePost();
			const result = await apiFetch( {
				path: `/${ config.namespace }/campaigns/${ uid }/request-publish`,
				method: 'POST',
			} );
			setSent( true );
			createNotice(
				'success',
				result?.notified
					? sprintf(
							/* translators: %d: how many people were emailed. */
							__(
								'Asked %d person who can publish to take a look.',
								'campaign-bandit'
							),
							result.notified
						)
					: __(
							'Your request is on the record, but nobody on this site can publish yet.',
							'campaign-bandit'
						),
				{ type: 'snackbar' }
			);
		} catch ( error ) {
			createNotice(
				'error',
				error?.message ||
					__( 'The request could not be sent.', 'campaign-bandit' ),
				{ type: 'snackbar' }
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<Button
			variant="primary"
			className="cbandit-review-publish"
			isBusy={ busy }
			disabled={ busy || sent || ! uid }
			accessibleWhenDisabled
			onClick={ ask }
			__next40pxDefaultSize={ false }
			size="compact"
		>
			{ sent
				? __( 'Publish requested', 'campaign-bandit' )
				: __( 'Request publish', 'campaign-bandit' ) }
		</Button>
	);
}

/**
 * Publish panel: status, pre-publish checks, Publish, switch off and on (S4).
 */
function PublishPanel() {
	const [ settings, , uid ] = useSettings();
	const [ status, refresh ] = usePublishStatus( uid );
	const [ busy, setBusy ] = useState( false );
	const { savePost } = useDispatch( editorStore );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
	const isDirty = useSelect(
		( sel ) => sel( editorStore ).isEditedPostDirty(),
		[]
	);

	const act = async ( action ) => {
		setBusy( true );
		try {
			if ( action === 'publish' ) {
				await savePost();
			}
			// On a hub, publishing means choosing sites: open the Publish screen (FR-41).
			if ( action === 'publish' && config.publishScreen ) {
				window.location.href =
					config.publishScreen + encodeURIComponent( uid );
				return;
			}
			const result = await apiFetch( {
				path: `/${ config.namespace }/campaigns/${ uid }/${ action }`,
				method: 'POST',
			} );
			createSuccessNotice(
				action === 'publish'
					? sprintf(
							/* translators: %d: version number. */
							__(
								'Published as version %d. It is live on this site.',
								'campaign-bandit'
							),
							result.version
						)
					: __( 'Campaign updated.', 'campaign-bandit' ),
				{ type: 'snackbar' }
			);
		} catch ( error ) {
			createErrorNotice( error.message, { type: 'snackbar' } );
		} finally {
			setBusy( false );
			refresh();
		}
	};

	const checks = publishChecks( status, settings );
	const errors = checks.filter( ( c ) => c.severity === 'error' );
	const live = status?.live;
	const t = settings.triggers || {};

	// What the draft holds that the live version does not (FR-32).
	const names = {
		content: __( 'design', 'campaign-bandit' ),
		form: __( 'form fields', 'campaign-bandit' ),
		rules: __( 'rules', 'campaign-bandit' ),
	};
	const waiting = ( status?.draft_changes || [] )
		.map( ( kind ) => names[ kind ] || kind )
		.join( ', ' );

	let state = {
		label: __( 'Draft', 'campaign-bandit' ),
		tone: 'neutral',
		detail: __( 'Not published yet', 'campaign-bandit' ),
	};
	if ( live ) {
		state = {
			label:
				live.state === 'live'
					? sprintf(
							/* translators: %d: live version number. */
							__( 'Live · v%d', 'campaign-bandit' ),
							live.version
						)
					: sprintf(
							/* translators: %d: version number. */
							__( 'Switched off · v%d', 'campaign-bandit' ),
							live.version
						),
			tone: live.state === 'live' ? 'good' : 'attention',
			// "Changes publish as v2" reads the same whether or not anything is
			// waiting, so it cannot say that what is on screen is not what visitors
			// get. When something is waiting, say so and say what.
			detail: waiting
				? sprintf(
						/* translators: 1: what is not live, such as "rules, design". 2: the live version number. */
						__(
							'Not live yet: %1$s. Visitors see v%2$d.',
							'campaign-bandit'
						),
						waiting,
						live.version
					)
				: sprintf(
						/* translators: %d: next version number. */
						__( 'Changes publish as v%d', 'campaign-bandit' ),
						( status.latest_version || 0 ) + 1
					),
		};
	}

	return (
		<PluginDocumentSettingPanel
			name="campaign-bandit-publish"
			title={ __( 'Publish', 'campaign-bandit' ) }
			initialOpen
		>
			<div className="cbandit-panel">
				{ uid ? (
					<div className="cbandit-publish-state">
						<span
							className={ `cbandit-state cbandit-state--${ state.tone }` }
						>
							{ state.label }
						</span>
						<span className="cbandit-note">{ state.detail }</span>
					</div>
				) : (
					<p className="cbandit-note">
						{ __( 'Save the draft to start.', 'campaign-bandit' ) }
					</p>
				) }

				{ checks.length > 0 && (
					<div>
						<p className="cbandit-rule-label">
							{ __( 'Before you publish', 'campaign-bandit' ) }
						</p>
						<ul className="cbandit-checks">
							{ checks.map( ( check ) => (
								<li
									key={ check.id }
									className={ `cbandit-checks__item cbandit-checks__item--${ check.severity }` }
								>
									{ check.message }
								</li>
							) ) }
						</ul>
					</div>
				) }

				{ status && ! status.can_publish && (
					<p className="cbandit-note">
						{ __(
							'A Manager or Administrator publishes campaigns.',
							'campaign-bandit'
						) }
					</p>
				) }

				{ status?.can_publish && (
					<div className="cbandit-publish-actions">
						<Button
							variant="primary"
							className="cbandit-wide"
							isBusy={ busy }
							disabled={ busy || errors.length > 0 || ! uid }
							accessibleWhenDisabled
							onClick={ () => act( 'publish' ) }
							__next40pxDefaultSize
						>
							{ /* eslint-disable-next-line no-nested-ternary */ }
							{ config.publishScreen
								? __( 'Publish to sites…', 'campaign-bandit' )
								: live
									? __(
											'Publish new version',
											'campaign-bandit'
										)
									: __( 'Publish', 'campaign-bandit' ) }
						</Button>
						{ status?.latest_version > 0 && config.detailScreen && (
							<a
								className="cbandit-note"
								href={
									config.detailScreen +
									encodeURIComponent( uid )
								}
							>
								{ __(
									'Sites, versions and activity',
									'campaign-bandit'
								) }
							</a>
						) }
						{ live && ! config.publishScreen && (
							<Button
								variant="secondary"
								className="cbandit-wide cbandit-quiet"
								disabled={ busy }
								onClick={ () =>
									act(
										live.state === 'live'
											? 'switch-off'
											: 'switch-on'
									)
								}
								__next40pxDefaultSize
							>
								{ live.state === 'live'
									? __( 'Switch off', 'campaign-bandit' )
									: __( 'Switch on', 'campaign-bandit' ) }
							</Button>
						) }
						{ isDirty && errors.length === 0 && (
							<p className="cbandit-note">
								{ __(
									'Unsaved changes are saved when you publish.',
									'campaign-bandit'
								) }
							</p>
						) }
					</div>
				) }

				{ uid &&
					settings.format !== 'inline' &&
					( t.click || t.hover ) && (
						<CopyField
							label={ __( 'Trigger class', 'campaign-bandit' ) }
							help={ __(
								'Add this class to any button or link that should open the campaign.',
								'campaign-bandit'
							) }
							value={ `cbandit-open-${ uid.toLowerCase() }` }
						/>
					) }
				{ uid && settings.format === 'inline' && (
					<CopyField
						label={ __( 'Shortcode', 'campaign-bandit' ) }
						help={ __(
							'Or place it with the Campaign Bandit Inline block.',
							'campaign-bandit'
						) }
						value={ `[campaign_bandit id="${ uid }"]` }
					/>
				) }
			</div>
		</PluginDocumentSettingPanel>
	);
}

/**
 * The ways a campaign opens (BR-15 to BR-17): how to tell one is set, the value
 * that adds it and the value that removes it.
 */
const TRIGGERS = [
	{
		id: 'click',
		control: 'none',
		title: () => __( 'On click', 'campaign-bandit' ),
		isSet: ( t ) => Boolean( t.click ),
		on: { click: true },
		off: { click: false },
	},
	{
		id: 'delay',
		control: 'none',
		title: () => __( 'After a delay', 'campaign-bandit' ),
		isSet: ( t ) =>
			t.delay_seconds !== null && t.delay_seconds !== undefined,
		on: { delay_seconds: 5 },
		off: { delay_seconds: null },
	},
	{
		id: 'scroll',
		control: 'trigger_scroll',
		title: () => __( 'On scroll', 'campaign-bandit' ),
		isSet: ( t ) => Boolean( t.scroll_percent ),
		on: { scroll_percent: 50 },
		off: { scroll_percent: null },
	},
	{
		id: 'exit',
		control: 'trigger_exit_intent',
		title: () => __( 'Exit intent', 'campaign-bandit' ),
		isSet: ( t ) => Boolean( t.exit_intent ),
		on: { exit_intent: true },
		off: { exit_intent: false },
	},
	{
		id: 'hover',
		control: 'trigger_hover',
		title: () => __( 'On hover', 'campaign-bandit' ),
		isSet: ( t ) => Boolean( t.hover ),
		on: { hover: true },
		off: { hover: false },
	},
	{
		id: 'event',
		control: 'trigger_custom_event',
		title: () => __( 'On an event', 'campaign-bandit' ),
		isSet: ( t ) => Boolean( t.custom_event ),
		on: { custom_event: 'campaignbandit:open' },
		off: { custom_event: '' },
	},
];

/**
 * Campaign panel: the summary card, then format, triggers, goal and offer.
 * Always shown: every campaign needs these (S4).
 */
function CampaignPanel() {
	const [ s, update ] = useSettings();
	const t = s.triggers || {};
	const setTrigger = ( patch ) => update( { triggers: { ...t, ...patch } } );
	const triggers = useOptional(
		TRIGGERS.map( ( trigger ) => ( {
			id: trigger.id,
			isSet: trigger.isSet( t ),
			reset: () => setTrigger( trigger.off ),
		} ) )
	);
	const hasTrigger =
		s.format === 'inline' || TRIGGERS.some( ( tr ) => tr.isSet( t ) );
	const available = TRIGGERS.filter( ( tr ) => ! triggers.shown( tr.id ) );

	/**
	 * The body of an added trigger's row.
	 *
	 * @param {string} id Trigger ID.
	 * @return {Object} Row content.
	 */
	const triggerRow = ( id ) => {
		switch ( id ) {
			case 'click':
				return (
					<>
						<span>{ __( 'On click', 'campaign-bandit' ) }</span>
						<HelpTip
							text={ __(
								'Any element with the trigger class, the data-cbandit-open attribute, or a #cbandit- link opens it. Find the class in the Publish panel.',
								'campaign-bandit'
							) }
						/>
					</>
				);
			case 'delay':
				return (
					<>
						<span>{ __( 'After', 'campaign-bandit' ) }</span>
						<InlineNumber
							label={ __( 'Seconds', 'campaign-bandit' ) }
							value={ t.delay_seconds }
							min={ 0 }
							max={ 3600 }
							onChange={ ( delay ) =>
								setTrigger( { delay_seconds: delay } )
							}
						/>
						<span>{ __( 'seconds', 'campaign-bandit' ) }</span>
					</>
				);
			case 'scroll':
				return (
					<>
						<span>{ __( 'At', 'campaign-bandit' ) }</span>
						<InlineNumber
							label={ __(
								'Percent scrolled',
								'campaign-bandit'
							) }
							value={ t.scroll_percent }
							min={ 1 }
							max={ 100 }
							onChange={ ( percent ) =>
								setTrigger( { scroll_percent: percent } )
							}
						/>
						<span>
							{ __( '% of the page', 'campaign-bandit' ) }
						</span>
					</>
				);
			case 'exit':
				return (
					<>
						<span>{ __( 'Exit intent', 'campaign-bandit' ) }</span>
						<HelpTip
							text={ __(
								'Desktop only: when the pointer leaves through the top of the window. Never fires on phones and tablets.',
								'campaign-bandit'
							) }
						/>
					</>
				);
			case 'hover':
				return (
					<>
						<span>{ __( 'On hover', 'campaign-bandit' ) }</span>
						<HelpTip
							text={ __(
								'Hovering or keyboard-focusing a trigger element opens it.',
								'campaign-bandit'
							) }
						/>
					</>
				);
			default:
				return (
					<>
						<span>{ __( 'On event', 'campaign-bandit' ) }</span>
						<input
							className="cbandit-inline-text"
							aria-label={ __( 'Event name', 'campaign-bandit' ) }
							value={ t.custom_event || '' }
							onChange={ ( e ) =>
								setTrigger( {
									custom_event: e.target.value.trim(),
								} )
							}
						/>
						<HelpTip
							text={ __(
								'A JavaScript event fired on the page, for example wc-blocks_added_to_cart.',
								'campaign-bandit'
							) }
						/>
					</>
				);
		}
	};

	const formats = [
		{ value: 'modal', label: __( 'Modal', 'campaign-bandit' ) },
		{ value: 'inline', label: __( 'Inline', 'campaign-bandit' ) },
		{
			value: 'slide_in',
			label: __( 'Slide-in', 'campaign-bandit' ),
			locked: isLocked( 'format_slide_in' ),
		},
		{
			value: 'bar',
			label: __( 'Bar', 'campaign-bandit' ),
			locked: isLocked( 'format_bar' ),
		},
	];

	return (
		<PluginDocumentSettingPanel
			name="campaign-bandit-campaign"
			title={ __( 'Campaign', 'campaign-bandit' ) }
		>
			<div className="cbandit-panel">
				<p className="cbandit-summary" aria-live="polite">
					{ summarise( s ) }
				</p>

				<Section
					title={ __( 'Format', 'campaign-bandit' ) }
					help={ __(
						'Modal: centred over the page. Inline: placed in content with the block or shortcode. Slide-in: in a corner. Bar: a sticky strip at the top or bottom.',
						'campaign-bandit'
					) }
				>
					<Segmented
						label={ __( 'Format', 'campaign-bandit' ) }
						value={ s.format || 'modal' }
						options={ formats }
						onChange={ ( format ) => update( { format } ) }
					/>
					{ s.format === 'slide_in' && (
						<SelectControl
							label={ __( 'Corner', 'campaign-bandit' ) }
							hideLabelFromVision
							value={ s.corner || 'bottom_right' }
							options={ [
								{
									value: 'bottom_right',
									label: __(
										'Bottom right corner',
										'campaign-bandit'
									),
								},
								{
									value: 'bottom_left',
									label: __(
										'Bottom left corner',
										'campaign-bandit'
									),
								},
								{
									value: 'top_right',
									label: __(
										'Top right corner',
										'campaign-bandit'
									),
								},
								{
									value: 'top_left',
									label: __(
										'Top left corner',
										'campaign-bandit'
									),
								},
							] }
							onChange={ ( corner ) => update( { corner } ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					) }
					{ s.format === 'bar' && (
						<SelectControl
							label={ __( 'Position', 'campaign-bandit' ) }
							hideLabelFromVision
							value={ s.bar_position || 'bottom' }
							options={ [
								{
									value: 'bottom',
									label: __(
										'Bottom of the screen',
										'campaign-bandit'
									),
								},
								{
									value: 'top',
									label: __(
										'Top of the screen',
										'campaign-bandit'
									),
								},
							] }
							onChange={ ( position ) =>
								update( { bar_position: position } )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					) }
					{ s.format === 'bar' && s.bar_position === 'top' && (
						<>
							<TextControl
								type="number"
								min={ 0 }
								max={ 300 }
								label={ __(
									'Top offset (px)',
									'campaign-bandit'
								) }
								help={ __(
									'Moves the bar below a sticky site header: enter the header’s height. On a hub, each site can set its own in Site settings.',
									'campaign-bandit'
								) }
								value={ s.appearance?.bar_offset ?? 0 }
								onChange={ ( value ) => {
									const n = parseInt( value, 10 );
									update( {
										appearance: {
											...( s.appearance || {} ),
											bar_offset: Number.isNaN( n )
												? 0
												: Math.max(
														0,
														Math.min( 300, n )
													),
										},
									} );
								} }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
							<p className="cbandit-note cbandit-note--warning">
								{ __(
									'On phones, a bar at the top can cover the site’s header or menu. A bar at the bottom is usually safer.',
									'campaign-bandit'
								) }
							</p>
						</>
					) }
				</Section>

				{ s.format !== 'inline' && (
					<Section
						title={ __( 'Triggers', 'campaign-bandit' ) }
						badge={
							hasTrigger
								? undefined
								: __( 'Required', 'campaign-bandit' )
						}
						tone="required"
						help={ __(
							'Add one or more. Whichever happens first opens the campaign.',
							'campaign-bandit'
						) }
					>
						{ TRIGGERS.filter( ( tr ) =>
							triggers.shown( tr.id )
						).map( ( tr ) => (
							<div className="cbandit-item" key={ tr.id }>
								{ triggerRow( tr.id ) }
								<RemoveButton
									label={ sprintf(
										/* translators: %s: trigger, such as "On click". */
										__( 'Remove “%s”', 'campaign-bandit' ),
										tr.title()
									) }
									onClick={ () => triggers.remove( tr.id ) }
								/>
							</div>
						) ) }
						{ available.length > 0 && (
							<SelectControl
								label={ __(
									'Add a trigger',
									'campaign-bandit'
								) }
								hideLabelFromVision
								value=""
								options={ [
									{
										value: '',
										label: hasTrigger
											? __(
													'Add another trigger…',
													'campaign-bandit'
												)
											: __(
													'Select a trigger…',
													'campaign-bandit'
												),
									},
									...available.map( ( tr ) => ( {
										value: tr.id,
										label: pro( tr.title(), tr.control ),
										disabled: isLocked( tr.control ),
									} ) ),
								] }
								onChange={ ( id ) => {
									const tr = TRIGGERS.find(
										( x ) => x.id === id
									);
									if ( tr ) {
										setTrigger( tr.on );
										triggers.add( tr.id );
									}
								} }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						) }
					</Section>
				) }

				<Section
					title={ __( 'Goal', 'campaign-bandit' ) }
					help={ __(
						'What counts as a conversion. Form: a form in the campaign is submitted. Button: a button marked "Counts as the goal" is clicked or tapped, including Call and Text us buttons.',
						'campaign-bandit'
					) }
				>
					<Segmented
						label={ __( 'Goal', 'campaign-bandit' ) }
						value={ s.goal || 'form' }
						options={ [
							{
								value: 'form',
								label: __(
									'Form submitted',
									'campaign-bandit'
								),
							},
							{
								value: 'button',
								label: __(
									'Button clicked',
									'campaign-bandit'
								),
							},
						] }
						onChange={ ( goal ) => update( { goal } ) }
					/>
				</Section>

				<Field
					label={ __( 'Offer', 'campaign-bandit' ) }
					help={ __(
						'What it offers, such as “Free quote”. Campaigns with the same offer can hide each other after a sign-up.',
						'campaign-bandit'
					) }
				>
					{ ( id ) => (
						<TextControl
							id={ id }
							label={ __( 'Offer', 'campaign-bandit' ) }
							hideLabelFromVision
							placeholder={ __(
								'Free quote',
								'campaign-bandit'
							) }
							value={ s.offer || '' }
							maxLength={ 60 }
							onChange={ ( offer ) => update( { offer } ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					) }
				</Field>
			</div>
		</PluginDocumentSettingPanel>
	);
}

/**
 * Now as a datetime-local value ("YYYY-MM-DDTHH:MM"), plus some days.
 *
 * @param {number} [days] Days to add.
 * @return {string} Local date and time.
 */
const localNow = ( days = 0 ) => {
	const d = new Date( Date.now() + days * 86400000 );
	d.setMinutes( d.getMinutes() - d.getTimezoneOffset() );
	return d.toISOString().slice( 0, 16 );
};

/**
 * One end of the schedule: "Now" or "Forever" until set, then a date field with
 * a clear button (Block Visibility's From / To).
 *
 * @param {Object}                  props          Props.
 * @param {string}                  props.label    "From" or "To".
 * @param {string}                  props.empty    Text while unset: "Now" or "Forever".
 * @param {string|null}             props.value    "Y-m-d H:i", or null.
 * @param {() => string}            props.initial  Value to start from when clicked.
 * @param {boolean}                 props.disabled Locked.
 * @param {(value: string) => void} props.onChange Called with the new value ('' clears).
 */
function DateRow( { label, empty, value, initial, disabled, onChange } ) {
	return (
		<div className="cbandit-date-row">
			<span className="cbandit-date-label">{ label }</span>
			{ value ? (
				<>
					<input
						type="datetime-local"
						className="cbandit-date-input"
						aria-label={ label }
						value={ value.replace( ' ', 'T' ) }
						onChange={ ( e ) => onChange( e.target.value ) }
					/>
					<RemoveButton
						label={ sprintf(
							/* translators: %s: "From" or "To". */
							__( 'Clear “%s”', 'campaign-bandit' ),
							label
						) }
						onClick={ () => onChange( '' ) }
					/>
				</>
			) : (
				<Button
					className="cbandit-date-empty"
					disabled={ disabled }
					accessibleWhenDisabled
					onClick={ () => onChange( initial() ) }
					aria-label={ sprintf(
						/* translators: 1: "From" or "To", 2: "Now" or "Forever". */
						__( '%1$s: %2$s. Choose a date', 'campaign-bandit' ),
						label,
						empty
					) }
				>
					{ empty }
				</Button>
			) }
		</div>
	);
}

/**
 * Display conditions: rule sets, then the default page exclusions (BR-01, BR-06).
 */
function AudiencePanel() {
	const [ s, update ] = useSettings();
	const r = s.rules || {};
	const setRules = ( patch ) => update( { rules: { ...r, ...patch } } );

	return (
		<PluginDocumentSettingPanel
			name="campaign-bandit-audience"
			title={ __( 'Display conditions', 'campaign-bandit' ) }
		>
			<div className="cbandit-panel">
				<Section
					title={ __( 'Rules', 'campaign-bandit' ) }
					help={ __(
						'Pages, URLs, devices and more. With no rules, the campaign can show to everyone, on every page.',
						'campaign-bandit'
					) }
				>
					<ConditionBuilder
						condition={ s.condition }
						onChange={ ( condition ) => update( { condition } ) }
					/>
				</Section>

				<Section
					title={ __( 'Page exclusions', 'campaign-bandit' ) }
					help={ __(
						'The site’s default exclusions. Turn off only if this campaign belongs on those pages.',
						'campaign-bandit'
					) }
				>
					<ToggleControl
						label={ __(
							'Never on login, admin, cart and checkout pages',
							'campaign-bandit'
						) }
						checked={ r.default_excludes !== false }
						onChange={ ( on ) =>
							setRules( { default_excludes: on } )
						}
						__nextHasNoMarginBottom
					/>
				</Section>
			</div>
		</PluginDocumentSettingPanel>
	);
}

/**
 * Days of the week in the site's week order, with short and full names.
 *
 * @return {Array<{day: number, short: string, name: string}>} Days.
 */
const weekDays = () => {
	const days = [
		[ __( 'Su', 'campaign-bandit' ), __( 'Sunday', 'campaign-bandit' ) ],
		[ __( 'Mo', 'campaign-bandit' ), __( 'Monday', 'campaign-bandit' ) ],
		[ __( 'Tu', 'campaign-bandit' ), __( 'Tuesday', 'campaign-bandit' ) ],
		[ __( 'We', 'campaign-bandit' ), __( 'Wednesday', 'campaign-bandit' ) ],
		[ __( 'Th', 'campaign-bandit' ), __( 'Thursday', 'campaign-bandit' ) ],
		[ __( 'Fr', 'campaign-bandit' ), __( 'Friday', 'campaign-bandit' ) ],
		[ __( 'Sa', 'campaign-bandit' ), __( 'Saturday', 'campaign-bandit' ) ],
	].map( ( [ short, name ], day ) => ( { day, short, name } ) );
	const start = config.startOfWeek || 0;
	return [ ...days.slice( start ), ...days.slice( 0, start ) ];
};

/**
 * Date & time (BR-08): schedules joined by "or". Each has From "Now" / To
 * "Forever" and optional days of the week.
 */
function SchedulePanel() {
	const [ s, update ] = useSettings();
	const r = s.rules || {};
	// Drafts saved before BR-08 hold a single schedule.
	const schedules = r.schedules || ( r.schedule ? [ r.schedule ] : [] );
	const locked = isLocked( 'schedule' );
	const setSchedules = ( next ) =>
		update( { rules: { ...r, schedule: undefined, schedules: next } } );
	const setOne = ( index, patch ) =>
		setSchedules(
			schedules.map( ( one, i ) =>
				i === index ? { ...one, ...patch } : one
			)
		);

	return (
		<PluginDocumentSettingPanel
			name="campaign-bandit-schedule"
			title={ __( 'Date & time', 'campaign-bandit' ) }
		>
			<div className="cbandit-panel">
				{ schedules.length === 0 && (
					<p className="cbandit-everyone">
						{ __( 'Any time.', 'campaign-bandit' ) }
						{ locked && (
							<>
								{ ' ' }
								<span className="cbandit-badge">
									{ proBadge() }
								</span>
							</>
						) }
					</p>
				) }
				{ schedules.map( ( one, index ) => (
					<div key={ index } className="cbandit-schedule">
						{ index > 0 && (
							<div
								className="cbandit-connector"
								aria-hidden="true"
							>
								<span>{ __( 'or', 'campaign-bandit' ) }</span>
							</div>
						) }
						<Section
							title={
								schedules.length > 1
									? sprintf(
											/* translators: %d: schedule number. */
											__(
												'Schedule %d',
												'campaign-bandit'
											),
											index + 1
										)
									: __( 'Schedule', 'campaign-bandit' )
							}
							help={ __(
								'In the site’s timezone. With several schedules, the campaign can show while any one applies.',
								'campaign-bandit'
							) }
							actions={
								<RemoveButton
									label={ __(
										'Remove schedule',
										'campaign-bandit'
									) }
									onClick={ () =>
										setSchedules(
											schedules.filter(
												( _, i ) => i !== index
											)
										)
									}
								/>
							}
						>
							<DateRow
								label={ __( 'From', 'campaign-bandit' ) }
								empty={ __( 'Now', 'campaign-bandit' ) }
								value={ one.start }
								initial={ () => localNow() }
								disabled={ locked }
								onChange={ ( start ) =>
									setOne( index, { start } )
								}
							/>
							<DateRow
								label={ __( 'To', 'campaign-bandit' ) }
								empty={ __( 'Forever', 'campaign-bandit' ) }
								value={ one.end }
								initial={ () => localNow( 30 ) }
								disabled={ locked }
								onChange={ ( end ) => setOne( index, { end } ) }
							/>
							<p className="cbandit-rule-label">
								{ ( one.days || [] ).length
									? __( 'On these days', 'campaign-bandit' )
									: __(
											'Every day, or only on…',
											'campaign-bandit'
										) }
							</p>
							<div
								className="cbandit-days"
								role="group"
								aria-label={ __(
									'Days of the week',
									'campaign-bandit'
								) }
							>
								{ weekDays().map( ( { day, short, name } ) => {
									const days = one.days || [];
									const on = days.includes( day );
									return (
										<Button
											key={ day }
											aria-pressed={ on }
											label={ name }
											showTooltip
											disabled={ locked }
											accessibleWhenDisabled
											onClick={ () =>
												setOne( index, {
													days: on
														? days.filter(
																( d ) =>
																	d !== day
															)
														: [
																...days,
																day,
															].sort(),
												} )
											}
										>
											{ short }
										</Button>
									);
								} ) }
							</div>
						</Section>
					</div>
				) ) }
				{ schedules.length > 0 && (
					<ToggleControl
						label={ __(
							'Hide when schedules apply',
							'campaign-bandit'
						) }
						help={
							r.hide_during_schedules
								? __(
										'Shows at any time except during these schedules.',
										'campaign-bandit'
									)
								: undefined
						}
						disabled={ locked }
						checked={ Boolean( r.hide_during_schedules ) }
						onChange={ ( on ) =>
							update( {
								rules: { ...r, hide_during_schedules: on },
							} )
						}
						__nextHasNoMarginBottom
					/>
				) }
				{ schedules.length < 10 && (
					<div className="cbandit-actions">
						<Button
							variant="link"
							disabled={ locked }
							onClick={ () =>
								setSchedules( [
									...schedules,
									{ start: null, end: null, days: [] },
								] )
							}
						>
							{ pro(
								__( 'Add schedule', 'campaign-bandit' ),
								'schedule'
							) }
						</Button>
					</div>
				) }
			</div>
		</PluginDocumentSettingPanel>
	);
}

/**
 * Frequency: how often it shows, hiding after close or sign-up, and the optional same-offer
 * rule and priority (BR-10 to BR-13).
 */
function FrequencyPanel() {
	const [ s, update ] = useSettings();
	const optional = useOptional( [
		{
			id: 'same_offer',
			isSet: Boolean( s.hide_after_same_offer ),
			reset: () => update( { hide_after_same_offer: false } ),
		},
		{
			id: 'priority',
			isSet: ( s.priority ?? 0 ) !== 0,
			reset: () => update( { priority: 0 } ),
		},
	] );
	if ( s.format === 'inline' ) {
		return null;
	}
	const f = s.frequency || { type: 'every_n_days', days: 7 };
	const suppressionLocked = isLocked( 'suppression' );
	const frequencyLocked = isLocked( 'frequency' );

	return (
		<PluginDocumentSettingPanel
			name="campaign-bandit-frequency"
			title={ __( 'Frequency', 'campaign-bandit' ) }
		>
			<div className="cbandit-panel">
				<Section
					title={ __( 'Show at most', 'campaign-bandit' ) }
					badge={ frequencyLocked ? proBadge() : undefined }
					help={ __(
						'Counted per visitor on this site. A visit ends after 30 minutes without a page view.',
						'campaign-bandit'
					) }
					actions={
						<OptionsMenu
							label={ __(
								'Frequency options',
								'campaign-bandit'
							) }
							optional={ optional }
							items={ [
								{
									id: 'same_offer',
									title: __(
										'Hide after the same offer',
										'campaign-bandit'
									),
									locked: suppressionLocked,
								},
								{
									id: 'priority',
									title: __( 'Priority', 'campaign-bandit' ),
									locked: isLocked( 'priority' ),
								},
							] }
						/>
					}
				>
					{ frequencyLocked ? (
						<p className="cbandit-sentence">
							{ __( 'Once every 7 days.', 'campaign-bandit' ) }
						</p>
					) : (
						<div className="cbandit-sentence cbandit-nowrap">
							<SelectControl
								label={ __( 'How often', 'campaign-bandit' ) }
								hideLabelFromVision
								value={ f.type }
								options={ [
									{
										value: 'every_n_days',
										label: __(
											'once every…',
											'campaign-bandit'
										),
									},
									{
										value: 'once_per_visit',
										label: __(
											'once per visit',
											'campaign-bandit'
										),
									},
									{
										value: 'every_view',
										label: __(
											'on every page view',
											'campaign-bandit'
										),
									},
								] }
								onChange={ ( type ) =>
									update( { frequency: { ...f, type } } )
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
							{ f.type === 'every_n_days' && (
								<>
									<InlineNumber
										label={ __(
											'Days',
											'campaign-bandit'
										) }
										value={ f.days ?? 7 }
										min={ 1 }
										max={ 365 }
										onChange={ ( days ) =>
											update( {
												frequency: { ...f, days },
											} )
										}
									/>
									<span>
										{ __( 'days', 'campaign-bandit' ) }
									</span>
								</>
							) }
						</div>
					) }
				</Section>

				<div className="cbandit-item">
					<span>{ __( 'If closed, hide', 'campaign-bandit' ) }</span>
					<InlineNumber
						label={ __(
							'Days hidden after closing',
							'campaign-bandit'
						) }
						value={ s.dismiss_days ?? 30 }
						min={ 0 }
						max={ 365 }
						disabled={ suppressionLocked }
						onChange={ ( days ) =>
							update( { dismiss_days: days } )
						}
					/>
					<span>{ __( 'days', 'campaign-bandit' ) }</span>
					{ suppressionLocked && (
						<span className="cbandit-badge">{ proBadge() }</span>
					) }
					<HelpTip
						text={ __(
							'0 means closing it doesn’t hide it. Closing is the close button, Esc, or clicking outside.',
							'campaign-bandit'
						) }
					/>
				</div>

				<div className="cbandit-item">
					<ToggleControl
						label={ pro(
							__(
								'Never show again after a sign-up',
								'campaign-bandit'
							),
							'suppression'
						) }
						disabled={ suppressionLocked }
						checked={ s.suppress_after_conversion !== false }
						onChange={ ( on ) =>
							update( { suppress_after_conversion: on } )
						}
						__nextHasNoMarginBottom
					/>
				</div>

				{ optional.shown( 'same_offer' ) && (
					<div className="cbandit-item">
						<ToggleControl
							label={ __(
								'Hide after a sign-up for the same offer elsewhere',
								'campaign-bandit'
							) }
							disabled={ ! s.offer }
							checked={ Boolean( s.hide_after_same_offer ) }
							onChange={ ( on ) =>
								update( { hide_after_same_offer: on } )
							}
							__nextHasNoMarginBottom
						/>
						<HelpTip
							text={
								s.offer
									? __(
											'Hides this campaign from visitors who already converted on another campaign with the same offer.',
											'campaign-bandit'
										)
									: __(
											'Set an offer in the Campaign panel first.',
											'campaign-bandit'
										)
							}
						/>
						<RemoveButton
							label={ __(
								'Remove the same-offer rule',
								'campaign-bandit'
							) }
							onClick={ () => optional.remove( 'same_offer' ) }
						/>
					</div>
				) }

				{ optional.shown( 'priority' ) && (
					<div className="cbandit-item">
						<span>{ __( 'Priority', 'campaign-bandit' ) }</span>
						<InlineNumber
							label={ __( 'Priority', 'campaign-bandit' ) }
							value={ s.priority ?? 0 }
							min={ -1000 }
							max={ 1000 }
							onChange={ ( priority ) => update( { priority } ) }
						/>
						<HelpTip
							text={ __(
								'When several popups qualify on a page, only the highest priority one opens. Ties go to the most recently published.',
								'campaign-bandit'
							) }
						/>
						<RemoveButton
							label={ __( 'Remove priority', 'campaign-bandit' ) }
							onClick={ () => optional.remove( 'priority' ) }
						/>
					</div>
				) }
			</div>
		</PluginDocumentSettingPanel>
	);
}

/**
 * Destinations (FR-52): which webhooks receive this campaign's leads. Leads are
 * always stored on the site first (BR-40). Pro and above; Free shows it locked.
 */
/**
 * Notifications (FR-56): who gets the new-lead email for this campaign.
 */
function NotificationsPanel() {
	const [ s, update ] = useSettings();
	const notify = s.notify || {};
	const mode = notify.mode || 'site';
	const [ text, setText ] = useState( ( notify.to || [] ).join( '\n' ) );
	const setTo = ( value ) => {
		setText( value );
		update( {
			notify: {
				mode,
				to: value.split( /[\s,;]+/ ).filter( Boolean ),
			},
		} );
	};

	return (
		<PluginDocumentSettingPanel
			name="campaign-bandit-notifications"
			title={ __( 'Notifications', 'campaign-bandit' ) }
		>
			<div className="cbandit-panel">
				<RadioControl
					label={ __( 'Email new leads to', 'campaign-bandit' ) }
					selected={ mode }
					options={ [
						{
							label: __(
								'The addresses in Settings',
								'campaign-bandit'
							),
							value: 'site',
						},
						{
							label: __( 'These addresses', 'campaign-bandit' ),
							value: 'custom',
						},
						{
							label: __( "Don't send", 'campaign-bandit' ),
							value: 'off',
						},
					] }
					onChange={ ( value ) =>
						update( { notify: { ...notify, mode: value } } )
					}
				/>
				{ mode === 'custom' && (
					<TextareaControl
						label={ __( 'Addresses', 'campaign-bandit' ) }
						help={ __(
							'One per line, up to 10.',
							'campaign-bandit'
						) }
						value={ text }
						onChange={ setTo }
						rows={ 3 }
						__nextHasNoMarginBottom
					/>
				) }
				<p className="cbandit-note">
					{ __(
						'Applies from the next published version. On a hub, a site can have its own addresses on its Site page.',
						'campaign-bandit'
					) }
				</p>
			</div>
		</PluginDocumentSettingPanel>
	);
}

function DestinationsPanel() {
	const [ s, update ] = useSettings();
	const locked = isLocked( 'destinations' );
	const [ list, setList ] = useState( null );
	const chosen = s.destinations || [];

	useEffect( () => {
		if ( locked ) {
			return;
		}
		apiFetch( { path: `/${ config.namespace }/destinations` } )
			.then( setList )
			.catch( () => setList( [] ) );
	}, [ locked ] );

	const toggle = ( id, on ) =>
		update( {
			destinations: on
				? [ ...chosen, id ]
				: chosen.filter( ( x ) => x !== id ),
		} );

	return (
		<PluginDocumentSettingPanel
			name="campaign-bandit-destinations"
			title={ __( 'Destinations', 'campaign-bandit' ) }
		>
			<div className="cbandit-panel">
				{ locked && (
					<p className="cbandit-note">
						{ __(
							'Leads are stored on this site. Pro also sends them to webhooks, such as Zapier or Make.',
							'campaign-bandit'
						) }{ ' ' }
						<span className="cbandit-badge">{ proBadge() }</span>
					</p>
				) }
				{ ! locked && null === list && (
					<p className="cbandit-note">
						{ __( 'Loading destinations…', 'campaign-bandit' ) }
					</p>
				) }
				{ ! locked && null !== list && list.length === 0 && (
					<p className="cbandit-note">
						{ __(
							'No destinations yet. Leads are stored on this site.',
							'campaign-bandit'
						) }{ ' ' }
						{ config.destinationsUrl && (
							<a href={ config.destinationsUrl }>
								{ __( 'Add a webhook', 'campaign-bandit' ) }
							</a>
						) }
					</p>
				) }
				{ ! locked && null !== list && list.length > 0 && (
					<>
						<p className="cbandit-note">
							{ __(
								'Each lead is stored on this site, then sent to the destinations you choose.',
								'campaign-bandit'
							) }
						</p>
						{ list.map( ( d ) => {
							let hint = '';
							if ( ! d.enabled ) {
								hint = __( 'Disabled', 'campaign-bandit' );
							} else if ( d.flagged ) {
								hint = __(
									'Needs attention',
									'campaign-bandit'
								);
							}
							return (
								<div className="cbandit-item" key={ d.id }>
									<ToggleControl
										label={
											hint
												? `${ d.name } (${ hint })`
												: d.name
										}
										checked={ chosen.includes( d.id ) }
										onChange={ ( on ) =>
											toggle( d.id, on )
										}
										__nextHasNoMarginBottom
									/>
								</div>
							);
						} ) }
						{ chosen.length > 0 && (
							<p className="cbandit-note">
								{ __(
									'Changes apply to leads from the next published version.',
									'campaign-bandit'
								) }
							</p>
						) }
					</>
				) }
			</div>
		</PluginDocumentSettingPanel>
	);
}

/**
 * The canvas drawn as the page the campaign appears on (S4).
 */
function CanvasStage() {
	const [ settings, update ] = useSettings();
	return (
		<>
			<Stage settings={ settings } />
			<PatternDefaults
				settings={ settings }
				update={ update }
				patterns={ config.patterns || {} }
			/>
		</>
	);
}

/**
 * Appearance: width, backdrop, edges.
 */
function Appearance() {
	const [ settings, update ] = useSettings();
	return <AppearancePanel settings={ settings } update={ update } />;
}

/**
 * Copy variables (FR-26): the campaign's definitions and their defaults.
 */
function CopyVariables() {
	const [ settings, update ] = useSettings();
	return <CopyVariablesPanel settings={ settings } update={ update } />;
}

/**
 * Preview in the top bar (FR-28), for the campaign being edited.
 */
function PreviewSlot() {
	const [ , , uid ] = useSettings();
	return <PreviewLive uid={ uid } />;
}

/**
 * Show the panels only in the campaign editor.
 */
function CampaignSidebar() {
	const postType = useSelect(
		( sel ) => sel( editorStore ).getCurrentPostType(),
		[]
	);
	if ( postType !== POST_TYPE ) {
		return null;
	}
	return (
		<>
			<CanvasStage />
			<PreviewSlot />
			<ReviewPublish />
			<PublishPanel />
			<CampaignPanel />
			<Appearance />
			<AudiencePanel />
			<CopyVariables />
			<SchedulePanel />
			<FrequencyPanel />
			<NotificationsPanel />
			<DestinationsPanel />
		</>
	);
}

registerPlugin( 'campaign-bandit', { render: CampaignSidebar } );
