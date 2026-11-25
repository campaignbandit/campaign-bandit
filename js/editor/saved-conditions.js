/**
 * Saved conditions in the rule builder (BR-07, FR-36): use one as a rule, edit it
 * without leaving the editor, or save the campaign's rules as a new one. The campaign
 * keeps a copy of the rules; publishing refreshes it from the library.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Modal,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { select } from '@wordpress/data';
import { ConditionBuilder } from './conditions';

const config = window.campaignBanditEditor || { locked: {} };
const path = ( rest = '' ) => `/${ config.namespace }/conditions${ rest }`;

let cache = null;
const listeners = new Set();

/**
 * The library, loaded once and shared by every rule on the page.
 *
 * @return {[Array<Object>|null, () => void]} Saved conditions (null while loading) and a reload.
 */
export function useSavedConditions() {
	const [ list, setList ] = useState( cache );
	useEffect( () => {
		listeners.add( setList );
		if ( cache === null ) {
			reload();
		}
		return () => listeners.delete( setList );
	}, [] );
	return [ list, reload ];
}

function reload() {
	// In a campaign, only the saved conditions its client can use (hub, BR-07).
	const campaign =
		select( 'core/editor' )?.getEditedPostAttribute?.( 'campaign_uid' ) ||
		'';
	apiFetch( {
		path: campaign
			? `${ path() }?campaign=${ encodeURIComponent( campaign ) }`
			: path(),
	} )
		.then( ( response ) => {
			cache = response.conditions || [];
			listeners.forEach( ( set ) => set( cache ) );
		} )
		.catch( () => {
			cache = [];
			listeners.forEach( ( set ) => set( cache ) );
		} );
}

/**
 * A rule that uses a saved condition: choose it, and edit it in place.
 *
 * @param {Object}                 props          Props.
 * @param {Object}                 props.rule     Rule ({type: 'condition', id, name, condition}).
 * @param {(rule: Object) => void} props.onChange Called with the new rule.
 */
export function SavedConditionRule( { rule, onChange } ) {
	const [ list ] = useSavedConditions();
	const [ editing, setEditing ] = useState( false );
	if ( list === null ) {
		return (
			<p className="cbandit-muted">
				{ __( 'Loading saved conditions…', 'campaign-bandit' ) }
			</p>
		);
	}
	const known = list.find( ( c ) => c.id === rule.id );
	const choose = ( id ) => {
		const saved = list.find( ( c ) => c.id === id );
		if ( saved ) {
			onChange( {
				type: 'condition',
				id: saved.id,
				name: saved.name,
				condition: saved.condition,
			} );
		}
	};
	if ( list.length === 0 ) {
		return (
			<p className="cbandit-muted">
				{ __(
					'No saved conditions yet. Build rules here and use “Save as a saved condition”, or add them on the Conditions screen.',
					'campaign-bandit'
				) }
			</p>
		);
	}
	return (
		<div className="cbandit-sentence">
			<SelectControl
				label={ __( 'Saved condition', 'campaign-bandit' ) }
				hideLabelFromVision
				value={ rule.id || '' }
				options={ [
					{
						value: '',
						label: __( 'Choose…', 'campaign-bandit' ),
						disabled: true,
					},
					...list.map( ( c ) => ( {
						value: c.id,
						label: c.category
							? `${ c.name } (${ c.category })`
							: c.name,
					} ) ),
					...( rule.id && ! known
						? [
								{
									value: rule.id,
									label: sprintf(
										/* translators: %s: saved condition name. */
										__( '%s (deleted)', 'campaign-bandit' ),
										rule.name || rule.id
									),
								},
							]
						: [] ),
				] }
				onChange={ choose }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			{ known && (
				<Button variant="link" onClick={ () => setEditing( true ) }>
					{ __( 'Edit', 'campaign-bandit' ) }
				</Button>
			) }
			{ editing && known && (
				<EditSaved
					saved={ known }
					onClose={ () => setEditing( false ) }
					onSaved={ ( saved ) => {
						setEditing( false );
						onChange( {
							...rule,
							name: saved.name,
							condition: saved.condition,
						} );
					} }
				/>
			) }
		</div>
	);
}

/**
 * Edit a saved condition in a dialog. Every campaign that uses it gets the change
 * when it is next published.
 *
 * @param {Object}                  props         Props.
 * @param {Object}                  props.saved   Saved condition.
 * @param {() => void}              props.onClose Close without saving.
 * @param {(saved: Object) => void} props.onSaved Called with the saved condition.
 */
function EditSaved( { saved, onClose, onSaved } ) {
	const [ name, setName ] = useState( saved.name );
	const [ condition, setCondition ] = useState( saved.condition );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const save = () => {
		setBusy( true );
		apiFetch( {
			path: path( '/' + saved.id ),
			method: 'PUT',
			data: { name, category: saved.category || '', condition },
		} )
			.then( ( result ) => {
				reload();
				onSaved( result );
			} )
			.catch( ( e ) => setError( e?.message || '' ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<Modal
			title={ __( 'Edit saved condition', 'campaign-bandit' ) }
			onRequestClose={ onClose }
			className="cbandit-saved-modal"
		>
			<p className="cbandit-muted">
				{ __(
					'Campaigns that use it get the change when they are next published.',
					'campaign-bandit'
				) }
			</p>
			<TextControl
				label={ __( 'Name', 'campaign-bandit' ) }
				value={ name }
				onChange={ setName }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<ConditionBuilder
				condition={ condition }
				onChange={ setCondition }
				allowSaved={ false }
			/>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<div className="cbandit-modal-actions">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'campaign-bandit' ) }
				</Button>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy || ! name.trim() }
					onClick={ save }
				>
					{ __( 'Save saved condition', 'campaign-bandit' ) }
				</Button>
			</div>
		</Modal>
	);
}

/**
 * "Save as a saved condition": the campaign's rules become a saved condition, and the
 * campaign then uses it.
 *
 * @param {Object}                      props           Props.
 * @param {Object}                      props.condition The campaign's condition.
 * @param {(condition: Object) => void} props.onChange  Called with the campaign's new condition.
 */
export function SaveAsSaved( { condition, onChange } ) {
	const [ open, setOpen ] = useState( false );
	const [ name, setName ] = useState( '' );
	const [ category, setCategory ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	if ( config.locked?.saved_conditions ) {
		return null;
	}
	const save = () => {
		setBusy( true );
		apiFetch( {
			path: path(),
			method: 'POST',
			data: { name, category, condition },
		} )
			.then( ( saved ) => {
				reload();
				setOpen( false );
				onChange( {
					logic: 'any',
					invert: false,
					groups: [
						{
							logic: 'all',
							rules: [
								{
									type: 'condition',
									id: saved.id,
									name: saved.name,
									condition: saved.condition,
								},
							],
						},
					],
				} );
			} )
			.catch( ( e ) => setError( e?.message || '' ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<>
			<Button variant="link" onClick={ () => setOpen( true ) }>
				{ __( 'Save as a saved condition', 'campaign-bandit' ) }
			</Button>
			{ open && (
				<Modal
					title={ __(
						'Save as a saved condition',
						'campaign-bandit'
					) }
					onRequestClose={ () => setOpen( false ) }
				>
					<p className="cbandit-muted">
						{ __(
							'These rules get a name you can use in other campaigns. This campaign then uses the saved condition.',
							'campaign-bandit'
						) }
					</p>
					<TextControl
						label={ __( 'Name', 'campaign-bandit' ) }
						placeholder={ __(
							'UK mobile visitors',
							'campaign-bandit'
						) }
						value={ name }
						onChange={ setName }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Category (optional)', 'campaign-bandit' ) }
						value={ category }
						onChange={ setCategory }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<div className="cbandit-modal-actions">
						<Button
							variant="tertiary"
							onClick={ () => setOpen( false ) }
						>
							{ __( 'Cancel', 'campaign-bandit' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy || ! name.trim() }
							onClick={ save }
						>
							{ __( 'Save', 'campaign-bandit' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
