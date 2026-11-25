/**
 * Conditions screen (S11): add or edit a saved condition with the campaign editor's
 * own rule builder (BR-07). On a hub, also the client it belongs to and its own
 * definition on client sites (requirements/08 P4).
 */
import { __ } from '@wordpress/i18n';
import {
	Button,
	Modal,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { createRoot, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { ConditionBuilder, SiteContent } from '../editor/conditions';
import '../editor/editor.css';
import './style.css';

const data = window.campaignBanditCondition || {};
const config = window.campaignBanditEditor || {};

function SavedConditionEditor() {
	const saved = data.saved;
	const [ name, setName ] = useState( saved?.name || '' );
	const [ category, setCategory ] = useState( saved?.category || '' );
	const [ workspace, setWorkspace ] = useState(
		String( saved?.workspace || 0 )
	);
	const [ condition, setCondition ] = useState(
		saved?.condition || { logic: 'any', invert: false, groups: [] }
	);
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const save = () => {
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: `/${ config.namespace }/conditions${
				saved ? '/' + saved.id : ''
			}`,
			method: saved ? 'PUT' : 'POST',
			data: {
				name,
				category,
				condition,
				...( data.workspaces
					? { workspace: Number( workspace ) }
					: {} ),
			},
		} )
			.then( () => {
				window.location.href = data.listUrl;
			} )
			.catch( ( e ) => {
				setError( e?.message || '' );
				setBusy( false );
			} );
	};

	return (
		<div className="cbandit-condition-editor">
			<h1>
				{ saved
					? __( 'Edit saved condition', 'campaign-bandit' )
					: __( 'Add saved condition', 'campaign-bandit' ) }
			</h1>
			{ saved && (
				<p className="cbandit-muted">
					{ __(
						'Campaigns that use it get the change when they are next published. Check usage on the list to see which.',
						'campaign-bandit'
					) }
				</p>
			) }
			<div className="cbandit-condition-editor__fields">
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
				{ ( data.categories || [] ).length > 0 && (
					<p className="cbandit-condition-editor__categories">
						{ __( 'In use:', 'campaign-bandit' ) }{ ' ' }
						{ data.categories.map( ( c ) => (
							<Button
								key={ c }
								variant="link"
								onClick={ () => setCategory( c ) }
							>
								{ c }
							</Button>
						) ) }
					</p>
				) }
				{ data.workspaces && (
					// On a hub, a saved condition is for every client or for one (BR-07).
					<SelectControl
						label={ __( 'Client', 'campaign-bandit' ) }
						help={ __(
							'Campaigns of other clients don’t see a client’s own saved conditions.',
							'campaign-bandit'
						) }
						value={ workspace }
						options={ [
							{
								value: '0',
								label: __( 'Every client', 'campaign-bandit' ),
							},
							...data.workspaces.map( ( w ) => ( {
								value: String( w.id ),
								label: w.name,
							} ) ),
						] }
						onChange={ setWorkspace }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				) }
			</div>
			<div className="cbandit-condition-editor__rules">
				<h2>{ __( 'Rules', 'campaign-bandit' ) }</h2>
				<ConditionBuilder
					condition={ condition }
					onChange={ setCondition }
					allowSaved={ false }
				/>
			</div>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<p>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy || ! name.trim() }
					onClick={ save }
				>
					{ __( 'Save saved condition', 'campaign-bandit' ) }
				</Button>{ ' ' }
				<Button variant="tertiary" href={ data.listUrl }>
					{ __( 'Cancel', 'campaign-bandit' ) }
				</Button>
			</p>
			{ saved && data.sites && data.sites.length > 0 && (
				<SitesPanel saved={ saved } sites={ data.sites } />
			) }
		</div>
	);
}

/**
 * On a hub: the saved condition on each client site (requirements/08 P4). A site uses
 * the definition above, or its own, picked from that site's pages and categories.
 *
 * @param {Object}        props       Props.
 * @param {Object}        props.saved The saved condition.
 * @param {Array<Object>} props.sites Sites with their status and content.
 */
function SitesPanel( { saved, sites } ) {
	const [ editing, setEditing ] = useState( null );
	const status = {
		own: __( 'Its own definition', 'campaign-bandit' ),
		ok: __( 'Uses the definition above', 'campaign-bandit' ),
		partly: __( 'Some rules can’t match here', 'campaign-bandit' ),
		never: __( 'Can’t match here', 'campaign-bandit' ),
		unknown: __( 'Not checked yet', 'campaign-bandit' ),
	};
	const remove = ( site ) =>
		apiFetch( {
			path: `/${ config.namespace }/conditions/${ saved.id }/sites/${ site.id }`,
			method: 'DELETE',
		} ).then( () => window.location.reload() );

	return (
		<div className="cbandit-condition-editor__sites">
			<h2>{ __( 'On client sites', 'campaign-bandit' ) }</h2>
			<p className="cbandit-muted">
				{ __(
					'Where a site’s pages or categories differ, give it its own definition. Every campaign using this saved condition then works there, from the site’s next sync.',
					'campaign-bandit'
				) }
			</p>
			<table className="wp-list-table widefat striped">
				<tbody>
					{ sites.map( ( site ) => (
						<tr key={ site.id }>
							<td>{ site.label }</td>
							<td>
								<span
									className={ `cbandit-status cbandit-status--${
										[ 'never', 'partly' ].includes(
											site.status
										)
											? 'attention'
											: 'neutral'
									}` }
								>
									{ status[ site.status ] || site.status }
								</span>
								{ site.problems.length > 0 && (
									<ul className="cbandit-condition-editor__problems">
										{ site.problems.map( ( p ) => (
											<li key={ p }>{ p }</li>
										) ) }
									</ul>
								) }
							</td>
							<td className="cbandit-row-links">
								{ site.structure && (
									<Button
										variant="link"
										onClick={ () => setEditing( site ) }
									>
										{ site.own
											? __(
													'Edit its definition',
													'campaign-bandit'
												)
											: __(
													'Give it its own definition',
													'campaign-bandit'
												) }
									</Button>
								) }
								{ site.own && (
									<Button
										variant="link"
										isDestructive
										onClick={ () => remove( site ) }
									>
										{ __(
											'Use the definition above',
											'campaign-bandit'
										) }
									</Button>
								) }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			{ editing && (
				<SiteDefinition
					saved={ saved }
					site={ editing }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</div>
	);
}

/**
 * A site's own definition, built from that site's own content.
 *
 * @param {Object}     props         Props.
 * @param {Object}     props.saved   The saved condition.
 * @param {Object}     props.site    The site, with its content.
 * @param {() => void} props.onClose Close.
 */
function SiteDefinition( { saved, site, onClose } ) {
	const [ condition, setCondition ] = useState( site.own || saved.condition );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const save = () => {
		setBusy( true );
		apiFetch( {
			path: `/${ config.namespace }/conditions/${ saved.id }/sites/${ site.id }`,
			method: 'PUT',
			data: { condition },
		} )
			.then( () => window.location.reload() )
			.catch( ( e ) => {
				setError( e?.message || '' );
				setBusy( false );
			} );
	};
	return (
		<Modal
			title={ site.label }
			onRequestClose={ onClose }
			className="cbandit-saved-modal"
		>
			<p className="cbandit-muted">
				{ __(
					'Rules for this site only, with its own pages and categories to pick from.',
					'campaign-bandit'
				) }
			</p>
			<SiteContent.Provider value={ site.structure }>
				<ConditionBuilder
					condition={ condition }
					onChange={ setCondition }
					allowSaved={ false }
				/>
			</SiteContent.Provider>
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
					disabled={ busy }
					onClick={ save }
				>
					{ __( 'Save for this site', 'campaign-bandit' ) }
				</Button>
			</div>
		</Modal>
	);
}

const root = document.getElementById( 'cbandit-condition-editor' );
if ( root ) {
	createRoot( root ).render( <SavedConditionEditor /> );
}
