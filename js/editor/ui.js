/**
 * Small building blocks for the campaign sidebar: section headers with hover help
 * and actions, a segmented switch, an optional-settings (⋮) menu, and
 * compact number inputs used inside sentences ("Hide for [30] days").
 */
import {
	Button,
	DropdownMenu,
	MenuGroup,
	MenuItem,
} from '@wordpress/components';
import { useCopyToClipboard, useInstanceId } from '@wordpress/compose';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * An ⓘ button that shows help on hover and keyboard focus (and reads it to screen readers).
 *
 * @param {Object} props      Props.
 * @param {string} props.text Help text.
 */
export function HelpTip( { text } ) {
	return (
		<Button
			className="cbandit-help"
			icon="info-outline"
			label={ text }
			showTooltip
			size="small"
		/>
	);
}

/**
 * A setting with its label row (label + optional hover help) above the control.
 * The control receives `id`, and keeps its own accessible label.
 *
 * @param {Object}                 props          Props.
 * @param {string}                 props.label    Visible label.
 * @param {string}                 [props.help]   Hover help.
 * @param {string}                 [props.badge]  Badge such as "Pro" or "Required".
 * @param {(id: string) => Object} props.children Renders the control.
 */
export function Field( { label, help, badge, children } ) {
	const id = useInstanceId( Field, 'cbandit-field' );
	return (
		<div className="cbandit-setting">
			<div className="cbandit-label-row">
				<label htmlFor={ id }>{ label }</label>
				{ badge && <span className="cbandit-badge">{ badge }</span> }
				{ help && <HelpTip text={ help } /> }
			</div>
			{ children( id ) }
		</div>
	);
}

/**
 * A compact number input for use inside a sentence.
 *
 * @param {Object}                  props            Props.
 * @param {string}                  props.label      Accessible label.
 * @param {number|null}             props.value      Value.
 * @param {number}                  props.min        Minimum.
 * @param {number}                  props.max        Maximum.
 * @param {(value: number) => void} props.onChange   Called with a clamped number.
 * @param {boolean}                 [props.disabled] Disabled.
 */
export function InlineNumber( { label, value, min, max, onChange, disabled } ) {
	return (
		<input
			type="number"
			className="cbandit-inline-number"
			aria-label={ label }
			min={ min }
			max={ max }
			value={ value ?? '' }
			disabled={ disabled }
			onChange={ ( event ) => {
				const n = parseInt( event.target.value, 10 );
				onChange(
					Number.isNaN( n )
						? min
						: Math.min( max, Math.max( min, n ) )
				);
			} }
		/>
	);
}

/**
 * A section: an uppercase title with optional badge and hover help, actions on the
 * right (a + or ⋮ menu, or a remove button), and its controls below.
 *
 * @param {Object} props            Props.
 * @param {string} props.title      Title.
 * @param {string} [props.help]     Hover help.
 * @param {string} [props.badge]    Badge such as "Pro" or "Required".
 * @param {string} [props.tone]     Badge tone: "required" highlights it.
 * @param {Object} [props.actions]  Controls on the right of the title.
 * @param {Object} [props.children] Section body.
 */
export function Section( { title, help, badge, tone, actions, children } ) {
	const id = useInstanceId( Section, 'cbandit-section' );
	return (
		<div className="cbandit-setting" role="group" aria-labelledby={ id }>
			<div className="cbandit-label-row">
				<span id={ id } className="cbandit-section-title">
					{ title }
				</span>
				{ badge && (
					<span
						className={ `cbandit-badge${
							tone === 'required'
								? ' cbandit-badge--required'
								: ''
						}` }
					>
						{ badge }
					</span>
				) }
				{ help && <HelpTip text={ help } /> }
				{ actions && (
					<span className="cbandit-section-actions">{ actions }</span>
				) }
			</div>
			{ children }
		</div>
	);
}

/**
 * A segmented switch for a few short choices (format, goal). Locked choices stay
 * visible, disabled, with the plan in their tooltip (FR-25).
 *
 * @param {Object}                  props          Props.
 * @param {string}                  props.label    Accessible group label.
 * @param {string}                  props.value    Selected value.
 * @param {Array<Object>}           props.options  { value, label, locked? }.
 * @param {(value: string) => void} props.onChange Called with the new value.
 */
export function Segmented( { label, value, options, onChange } ) {
	return (
		<div className="cbandit-segmented" role="group" aria-label={ label }>
			{ options.map( ( option ) => (
				<Button
					key={ option.value }
					aria-pressed={ value === option.value }
					disabled={ option.locked }
					accessibleWhenDisabled
					label={
						option.locked
							? sprintf(
									/* translators: %s: choice, such as "Slide-in". */
									__( '%s (needs Pro)', 'campaign-bandit' ),
									option.label
								)
							: undefined
					}
					showTooltip={ Boolean( option.locked ) }
					onClick={ () => onChange( option.value ) }
				>
					{ option.label }
				</Button>
			) ) }
		</div>
	);
}

/**
 * A remove (×) button for an added item.
 *
 * @param {Object}     props         Props.
 * @param {string}     props.label   Accessible label, such as "Remove dates".
 * @param {() => void} props.onClick Called on click.
 */
export function RemoveButton( { label, onClick } ) {
	return (
		<Button
			className="cbandit-remove"
			icon="no-alt"
			label={ label }
			size="small"
			onClick={ onClick }
		/>
	);
}

/**
 * Settings that stay hidden until added (or until they differ from their default),
 * like core's tools panels. Removing one resets it to its default.
 *
 * @param {Array<{id: string, isSet: boolean, reset: () => void}>} items Optional items.
 * @return {{shown: (id: string) => boolean, add: (id: string) => void, remove: (id: string) => void}} Helpers.
 */
export function useOptional( items ) {
	const [ added, setAdded ] = useState( [] );
	const find = ( id ) => items.find( ( item ) => item.id === id );
	return {
		shown: ( id ) => added.includes( id ) || Boolean( find( id )?.isSet ),
		add: ( id ) => setAdded( ( list ) => [ ...list, id ] ),
		remove: ( id ) => {
			find( id )?.reset();
			setAdded( ( list ) => list.filter( ( x ) => x !== id ) );
		},
	};
}

/**
 * A ⋮ menu listing optional settings, ticked when shown (Block Visibility's and
 * core's tools-panel pattern).
 *
 * @param {Object}        props          Props.
 * @param {string}        props.label    Accessible label.
 * @param {Array<Object>} props.items    { id, title, locked? }.
 * @param {Object}        props.optional Helpers from useOptional.
 */
export function OptionsMenu( { label, items, optional } ) {
	return (
		<DropdownMenu
			icon="ellipsis"
			label={ label }
			toggleProps={ { size: 'small' } }
		>
			{ () => (
				<MenuGroup
					label={ __( 'Optional settings', 'campaign-bandit' ) }
				>
					{ items.map( ( item ) => {
						const shown = optional.shown( item.id );
						return (
							<MenuItem
								key={ item.id }
								role="menuitemcheckbox"
								isSelected={ shown }
								icon={ shown ? 'yes' : undefined }
								disabled={ item.locked }
								onClick={ () =>
									shown
										? optional.remove( item.id )
										: optional.add( item.id )
								}
							>
								{ item.locked
									? `${ item.title } (${ proBadge() })`
									: item.title }
							</MenuItem>
						);
					} ) }
				</MenuGroup>
			) }
		</DropdownMenu>
	);
}

/**
 * A read-only value with a Copy button, such as the trigger class or shortcode.
 *
 * @param {Object} props        Props.
 * @param {string} props.label  Label.
 * @param {string} props.value  Value to copy.
 * @param {string} [props.help] Help under the field.
 */
export function CopyField( { label, value, help } ) {
	const id = useInstanceId( CopyField, 'cbandit-copy' );
	const [ copied, setCopied ] = useState( false );
	const ref = useCopyToClipboard( value, () => setCopied( true ) );
	useEffect( () => {
		if ( ! copied ) {
			return undefined;
		}
		const timer = setTimeout( () => setCopied( false ), 2000 );
		return () => clearTimeout( timer );
	}, [ copied ] );

	return (
		<div className="cbandit-setting">
			<label className="cbandit-section-title" htmlFor={ id }>
				{ label }
			</label>
			<div className="cbandit-copy">
				<input
					id={ id }
					className="cbandit-copy__value"
					readOnly
					value={ value }
					onFocus={ ( event ) => event.target.select() }
					aria-describedby={ help ? `${ id }-help` : undefined }
				/>
				<Button variant="secondary" size="compact" ref={ ref }>
					{ copied
						? __( 'Copied', 'campaign-bandit' )
						: __( 'Copy', 'campaign-bandit' ) }
				</Button>
			</div>
			{ help && (
				<p className="cbandit-note" id={ `${ id }-help` }>
					{ help }
				</p>
			) }
		</div>
	);
}

/**
 * The "Pro" badge text.
 *
 * @return {string} Badge.
 */
export const proBadge = () => __( 'Pro', 'campaign-bandit' );

/**
 * The badge for the plan that unlocks a control (FR-25).
 *
 * @param {string} plan Plan that unlocks it.
 * @return {string} Badge.
 */
export const planBadge = ( plan ) => {
	if ( 'agency_plus' === plan ) {
		return __( 'Agency Plus', 'campaign-bandit' );
	}
	if ( 'agency' === plan ) {
		return __( 'Agency', 'campaign-bandit' );
	}
	return proBadge();
};
