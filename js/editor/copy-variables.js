/**
 * Copy variables (FR-26, decision 0028): named placeholders a campaign defines once
 * and each site can fill in differently. The campaign sets the name and the default;
 * the token `{{name}}` goes into the text, and the hub's Publish screen sets each
 * site's value (BR-21).
 */
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { Button, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { CopyField, RemoveButton, planBadge } from './ui';

const config = window.campaignBanditEditor || { locked: {} };

/** Most variables one campaign can define (CopyVariables::MAX). */
const MAX = 20;

/**
 * A name as the server will keep it: lowercase, a letter then letters, digits or
 * underscores (CopyVariables::NAME). Typing is not blocked, so a name is only
 * trimmed to what will survive.
 *
 * @param {string} raw Typed name.
 * @return {string} Name.
 */
export const cleanName = ( raw ) =>
	String( raw || '' )
		.toLowerCase()
		.replace( /[^a-z0-9_]/g, '' )
		.replace( /^[^a-z]+/, '' )
		.slice( 0, 32 );

/**
 * The panel.
 *
 * @param {Object}                  props          Props.
 * @param {Object}                  props.settings Campaign settings.
 * @param {(patch: Object) => void} props.update   Merges a settings patch.
 * @return {Element} Panel.
 */
export function CopyVariablesPanel( { settings, update } ) {
	const plan = config.locked?.copy_variables;
	const locked = Boolean( plan );
	const variables = Array.isArray( settings.variables )
		? settings.variables
		: [];

	const setAt = ( index, patch ) =>
		update( {
			variables: variables.map( ( variable, i ) =>
				i === index ? { ...variable, ...patch } : variable
			),
		} );

	const remove = ( index ) =>
		update( {
			variables: variables.filter( ( _, i ) => i !== index ),
		} );

	const add = () =>
		update( { variables: [ ...variables, { name: '', default: '' } ] } );

	const duplicate = ( name, index ) =>
		'' !== name &&
		variables.some(
			( variable, i ) => i !== index && variable.name === name
		);

	return (
		<PluginDocumentSettingPanel
			name="campaign-bandit-variables"
			title={ __( 'Copy variables', 'campaign-bandit' ) }
		>
			<div className="cbandit-panel">
				{ locked && (
					<p className="cbandit-note">
						{ __(
							'Write the campaign once and let each site fill in its own words, such as the city or the offer.',
							'campaign-bandit'
						) }{ ' ' }
						<span className="cbandit-badge">
							{ planBadge( plan ) }
						</span>
					</p>
				) }
				{ ! locked && (
					<>
						<p className="cbandit-note">
							{ __(
								'Define a variable, then put its token in the text. Each site can set its own value when you publish; sites that don’t use the default.',
								'campaign-bandit'
							) }
						</p>
						{ variables.map( ( variable, index ) => {
							const name = String( variable.name || '' );
							const clash = duplicate( name, index );
							return (
								<div
									className="cbandit-item"
									key={ `variable-${ index }` }
								>
									<TextControl
										label={ __(
											'Name',
											'campaign-bandit'
										) }
										value={ name }
										onChange={ ( value ) =>
											setAt( index, {
												name: cleanName( value ),
											} )
										}
										help={
											clash
												? __(
														'Another variable already has this name.',
														'campaign-bandit'
													)
												: __(
														'Lowercase letters, numbers and underscores, such as city.',
														'campaign-bandit'
													)
										}
										__next40pxDefaultSize
										__nextHasNoMarginBottom
									/>
									<TextControl
										label={ __(
											'Default value',
											'campaign-bandit'
										) }
										value={ String(
											variable.default || ''
										) }
										onChange={ ( value ) =>
											setAt( index, { default: value } )
										}
										help={ __(
											'Used on every site that sets no value of its own. Leave empty to show nothing.',
											'campaign-bandit'
										) }
										__next40pxDefaultSize
										__nextHasNoMarginBottom
									/>
									{ '' !== name && (
										<CopyField
											label={ __(
												'Token',
												'campaign-bandit'
											) }
											value={ `{{${ name }}}` }
											help={ __(
												'Paste this into any text in the campaign.',
												'campaign-bandit'
											) }
										/>
									) }
									<RemoveButton
										label={
											'' === name
												? __(
														'Remove variable',
														'campaign-bandit'
													)
												: sprintf(
														/* translators: %s: variable name. */
														__(
															'Remove %s',
															'campaign-bandit'
														),
														name
													)
										}
										onClick={ () => remove( index ) }
									/>
								</div>
							);
						} ) }
						{ variables.length < MAX && (
							<Button
								variant="secondary"
								size="compact"
								onClick={ add }
							>
								{ 0 === variables.length
									? __( 'Add a variable', 'campaign-bandit' )
									: __( 'Add another', 'campaign-bandit' ) }
							</Button>
						) }
						{ variables.length >= MAX && (
							<p className="cbandit-note">
								{ sprintf(
									/* translators: %d: how many variables a campaign can have. */
									__(
										'A campaign can have %d variables.',
										'campaign-bandit'
									),
									MAX
								) }
							</p>
						) }
					</>
				) }
			</div>
		</PluginDocumentSettingPanel>
	);
}
