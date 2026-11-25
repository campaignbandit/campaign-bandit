/**
 * Appearance (part of the M4 Appearance panel, pulled forward for the starter
 * patterns): the frame's width, the backdrop behind a modal, and whether content runs
 * to the frame's edges. Also applies a starter pattern's appearance when one is
 * inserted, once, and registers the "No thanks" button style.
 */
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { registerBlockStyle } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	ColorPalette,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { Field, proBadge } from './ui';

const config = window.campaignBanditEditor || { locked: {} };
import { DEFAULT_WIDTHS, patternOf, patternPatch } from './appearance-rules';

/**
 * Black, white and a few dark tones that suit a backdrop.
 */
const BACKDROPS = [
	{ name: __( 'Black', 'campaign-bandit' ), color: '#000000' },
	{ name: __( 'Charcoal', 'campaign-bandit' ), color: '#111412' },
	{ name: __( 'Slate', 'campaign-bandit' ), color: '#06181c' },
	{ name: __( 'Warm grey', 'campaign-bandit' ), color: '#14110e' },
	{ name: __( 'White', 'campaign-bandit' ), color: '#ffffff' },
];

registerBlockStyle( 'core/button', {
	name: 'cbandit-dismiss',
	label: __( 'Closes the campaign', 'campaign-bandit' ),
} );

/**
 * Closing (FR-22): whether a click outside a modal closes it, on computers and tablets
 * and on phones. Pro and above; Free uses the defaults.
 *
 * @param {Object}                  props          Props.
 * @param {Object}                  props.settings Settings.
 * @param {(patch: Object) => void} props.update   Updater.
 * @return {Element} Controls.
 */
function Closing( { settings, update } ) {
	const locked = Boolean( config.locked?.close_behaviour );
	const rule = settings.backdrop_close || {};
	const set = ( patch ) =>
		update( { backdrop_close: { ...rule, ...patch } } );
	return (
		<div className="cbandit-setting">
			<p className="cbandit-rule-label">
				{ __( 'Clicking outside closes it', 'campaign-bandit' ) }
				{ locked && (
					<span className="cbandit-badge">{ proBadge() }</span>
				) }
			</p>
			<ToggleControl
				label={ __( 'On computers and tablets', 'campaign-bandit' ) }
				checked={ rule.desktop !== false }
				disabled={ locked }
				onChange={ ( desktop ) => set( { desktop } ) }
				__nextHasNoMarginBottom
			/>
			<ToggleControl
				label={ __( 'On phones', 'campaign-bandit' ) }
				help={ __(
					'Off by default: taps outside a phone sheet are often accidental. The close button and Esc always work, and on phones visitors can swipe it down.',
					'campaign-bandit'
				) }
				checked={ rule.mobile === true }
				disabled={ locked }
				onChange={ ( mobile ) => set( { mobile } ) }
				__nextHasNoMarginBottom
			/>
		</div>
	);
}

/**
 * Where a modal sits on larger screens: a 3 × 3 grid of visible choices. Phones always
 * get the bottom sheet.
 *
 * @param {Object}                  props          Props.
 * @param {string}                  props.value    Position.
 * @param {(value: string) => void} props.onChange Called with the new position.
 * @return {Element} Grid.
 */
function Position( { value, onChange } ) {
	const cells = [
		[ 'top_left', __( 'Top left', 'campaign-bandit' ) ],
		[ 'top', __( 'Top', 'campaign-bandit' ) ],
		[ 'top_right', __( 'Top right', 'campaign-bandit' ) ],
		[ 'left', __( 'Left', 'campaign-bandit' ) ],
		[ 'center', __( 'Centre', 'campaign-bandit' ) ],
		[ 'right', __( 'Right', 'campaign-bandit' ) ],
		[ 'bottom_left', __( 'Bottom left', 'campaign-bandit' ) ],
		[ 'bottom', __( 'Bottom', 'campaign-bandit' ) ],
		[ 'bottom_right', __( 'Bottom right', 'campaign-bandit' ) ],
	];
	return (
		<div className="cbandit-setting">
			<p className="cbandit-rule-label" id="cbandit-position-label">
				{ __( 'Position', 'campaign-bandit' ) }
			</p>
			<div
				className="cbandit-position-grid"
				role="group"
				aria-labelledby="cbandit-position-label"
			>
				{ cells.map( ( [ key, label ] ) => (
					<button
						key={ key }
						type="button"
						className="cbandit-position-grid__cell"
						aria-pressed={ value === key }
						aria-label={ label }
						title={ label }
						onClick={ () => onChange( key ) }
					/>
				) ) }
			</div>
			<p className="cbandit-note">
				{ __(
					'On larger screens. On phones a modal opens as a sheet from the bottom.',
					'campaign-bandit'
				) }
			</p>
		</div>
	);
}

/**
 * How the campaign appears. With reduced motion set, it appears without any (05 §6).
 *
 * @param {Object}                  props          Props.
 * @param {string}                  props.value    Animation.
 * @param {(value: string) => void} props.onChange Called with the new animation.
 * @return {Element} Choices.
 */
function Animation( { value, onChange } ) {
	const choices = [
		[ 'rise', __( 'Rise', 'campaign-bandit' ) ],
		[ 'fade', __( 'Fade', 'campaign-bandit' ) ],
		[ 'zoom', __( 'Zoom', 'campaign-bandit' ) ],
		[ 'none', __( 'None', 'campaign-bandit' ) ],
	];
	return (
		<div className="cbandit-setting">
			<p className="cbandit-rule-label" id="cbandit-animation-label">
				{ __( 'How it appears', 'campaign-bandit' ) }
			</p>
			<div
				className="cbandit-choices"
				role="group"
				aria-labelledby="cbandit-animation-label"
			>
				{ choices.map( ( [ key, label ] ) => (
					<button
						key={ key }
						type="button"
						className="cbandit-choice"
						aria-pressed={ value === key }
						onClick={ () => onChange( key ) }
					>
						{ label }
					</button>
				) ) }
			</div>
			<p className="cbandit-note">
				{ __(
					'Visitors who ask their device for reduced motion see it appear without any.',
					'campaign-bandit'
				) }
			</p>
		</div>
	);
}

/**
 * Campaign styles (FR-27): off, the campaign takes each site's theme fonts and colours;
 * on, it uses its own and looks the same on every site. Pro and above.
 *
 * @param {Object}                  props          Props.
 * @param {Object}                  props.settings Settings.
 * @param {(patch: Object) => void} props.update   Updater.
 * @return {Element} Controls.
 */
function CampaignStyles( { settings, update } ) {
	const locked = Boolean( config.locked?.campaign_styles );
	const styles = settings.styles || {};
	const set = ( patch ) => update( { styles: { ...styles, ...patch } } );
	const fonts = [
		{ value: 'sans', label: __( 'System sans-serif', 'campaign-bandit' ) },
		{ value: 'serif', label: __( 'System serif', 'campaign-bandit' ) },
		{ value: 'mono', label: __( 'System monospace', 'campaign-bandit' ) },
		...( config.themeFonts || [] ).map( ( f ) => ( {
			value: f.fontFamily,
			label: f.name,
		} ) ),
	];
	const palette = config.palette || [];
	const colour = ( key, label ) => (
		<Field label={ label }>
			{ () => (
				<ColorPalette
					colors={ palette }
					value={ styles[ key ] || undefined }
					onChange={ ( value ) => set( { [ key ]: value || '' } ) }
					disableCustomColors={ false }
					clearable
				/>
			) }
		</Field>
	);

	return (
		<div className="cbandit-setting cbandit-styles">
			<ToggleControl
				label={
					locked
						? `${ __(
								'Use campaign styles',
								'campaign-bandit'
							) } (${ proBadge() })`
						: __( 'Use campaign styles', 'campaign-bandit' )
				}
				help={ __(
					'Off: the campaign takes each site’s theme fonts and colours. On: it uses its own and looks the same on every site. No font files are loaded.',
					'campaign-bandit'
				) }
				checked={ Boolean( styles.enabled ) }
				disabled={ locked }
				onChange={ ( enabled ) => set( { enabled } ) }
				__nextHasNoMarginBottom
			/>
			{ styles.enabled && ! locked && (
				<>
					<SelectControl
						label={ __( 'Font', 'campaign-bandit' ) }
						value={ styles.font || 'sans' }
						options={ fonts }
						onChange={ ( font ) => set( { font } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Headings font', 'campaign-bandit' ) }
						value={ styles.heading_font || '' }
						options={ [
							{
								value: '',
								label: __(
									'Same as the text',
									'campaign-bandit'
								),
							},
							...fonts,
						] }
						onChange={ ( headingFont ) =>
							set( { heading_font: headingFont } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					{ colour( 'text', __( 'Text', 'campaign-bandit' ) ) }
					{ colour(
						'background',
						__( 'Background', 'campaign-bandit' )
					) }
					{ colour(
						'accent',
						__( 'Buttons and links', 'campaign-bandit' )
					) }
					{ colour(
						'accent_text',
						__( 'Button text', 'campaign-bandit' )
					) }
					{ colour(
						'accent_hover',
						__( 'Buttons on hover', 'campaign-bandit' )
					) }
					<RangeControl
						label={ __( 'Corner radius (px)', 'campaign-bandit' ) }
						value={ Number( styles.radius ) || 0 }
						min={ 0 }
						max={ 32 }
						onChange={ ( radius ) =>
							set( { radius: radius ?? 0 } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<RangeControl
						label={ __(
							'Text size on phones (%)',
							'campaign-bandit'
						) }
						help={ __(
							'Smaller text fits more on a phone; larger is easier to read.',
							'campaign-bandit'
						) }
						value={ Number( styles.mobile_scale ) || 100 }
						min={ 80 }
						max={ 120 }
						onChange={ ( scale ) =>
							set( { mobile_scale: scale ?? 100 } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<p className="cbandit-note">
						{ __(
							'Colours set on a block still win: these are the campaign’s defaults.',
							'campaign-bandit'
						) }
					</p>
				</>
			) }
		</div>
	);
}

/**
 * Apply a starter's appearance and format when it is inserted.
 *
 * @param {Object}                  props          Props.
 * @param {Object}                  props.settings Settings.
 * @param {(patch: Object) => void} props.update   Updater.
 * @param {Object}                  props.patterns Pattern defaults by slug.
 * @return {null} Renders nothing.
 */
export function PatternDefaults( { settings, update, patterns } ) {
	const slug = useSelect(
		( select ) => patternOf( select( blockEditorStore ).getBlocks() ),
		[]
	);
	const patch = patternPatch( slug, settings, patterns );
	useEffect( () => {
		if ( patch ) {
			update( patch );
		}
		// The patch is derived from the slug and the stored pattern; apply it once.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ slug, settings.pattern ] );
	return null;
}

/**
 * The Appearance panel.
 *
 * @param {Object}                  props          Props.
 * @param {Object}                  props.settings Settings.
 * @param {(patch: Object) => void} props.update   Updater.
 */
export function AppearancePanel( { settings, update } ) {
	const format = settings.format || 'modal';
	const appearance = settings.appearance || {};
	const set = ( patch ) =>
		update( { appearance: { ...appearance, ...patch } } );
	const opacity = appearance.backdrop_opacity ?? 55;

	return (
		<PluginDocumentSettingPanel
			name="campaign-bandit-appearance"
			title={ __( 'Appearance', 'campaign-bandit' ) }
		>
			<div className="cbandit-panel">
				{ ( format === 'modal' || format === 'slide_in' ) && (
					<Field
						label={ __( 'Width', 'campaign-bandit' ) }
						help={ __(
							'In pixels. Empty uses the format’s own width. On phones it is always the full width.',
							'campaign-bandit'
						) }
					>
						{ ( id ) => (
							<TextControl
								id={ id }
								type="number"
								min={ 240 }
								max={ 1200 }
								placeholder={ String(
									DEFAULT_WIDTHS[ format ]
								) }
								value={ appearance.width ?? '' }
								onChange={ ( value ) => {
									const n = parseInt( value, 10 );
									set( {
										width: Number.isNaN( n ) ? null : n,
									} );
								} }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						) }
					</Field>
				) }
				{ format === 'modal' && (
					<Field
						label={ __( 'Backdrop', 'campaign-bandit' ) }
						help={ __(
							'One flat colour behind the modal. Clicking it closes the modal.',
							'campaign-bandit'
						) }
					>
						{ () => (
							<>
								<ColorPalette
									colors={ BACKDROPS }
									value={ appearance.backdrop || '#000000' }
									onChange={ ( backdrop ) =>
										set( { backdrop: backdrop || '' } )
									}
									disableCustomColors={ false }
									clearable={ false }
								/>
								<RangeControl
									label={ __(
										'Opacity (%)',
										'campaign-bandit'
									) }
									value={ opacity }
									min={ 0 }
									max={ 90 }
									onChange={ ( value ) =>
										set( {
											backdrop_opacity: value ?? 55,
										} )
									}
									__nextHasNoMarginBottom
									__next40pxDefaultSize
								/>
							</>
						) }
					</Field>
				) }
				<ToggleControl
					label={ __(
						'Content touches the edges',
						'campaign-bandit'
					) }
					help={ __(
						'For layouts with images that run to the edge. The content then adds its own spacing.',
						'campaign-bandit'
					) }
					checked={ Boolean( appearance.edge ) }
					onChange={ ( edge ) => set( { edge } ) }
					__nextHasNoMarginBottom
				/>
				{ format === 'modal' && (
					<Position
						value={ appearance.position || 'center' }
						onChange={ ( position ) => set( { position } ) }
					/>
				) }
				{ format !== 'inline' && (
					<Animation
						value={ appearance.animation || 'rise' }
						onChange={ ( animation ) => set( { animation } ) }
					/>
				) }
				{ format === 'modal' && (
					<ToggleControl
						label={ __(
							'Stop the page scrolling while it is open',
							'campaign-bandit'
						) }
						help={ __(
							'Visitors can still scroll inside the modal.',
							'campaign-bandit'
						) }
						checked={ Boolean( appearance.scroll_lock ) }
						onChange={ ( scrollLock ) =>
							set( { scroll_lock: scrollLock } )
						}
						__nextHasNoMarginBottom
					/>
				) }
				{ format === 'modal' && (
					<Closing settings={ settings } update={ update } />
				) }
				<CampaignStyles settings={ settings } update={ update } />
			</div>
		</PluginDocumentSettingPanel>
	);
}
