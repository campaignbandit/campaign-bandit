/**
 * Choice field (FR-23): radio buttons, checkboxes when several can be chosen, or a
 * dropdown for a single choice from a long list.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import {
	SelectControl,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { FieldSettings } from '../field-edit';
import metadata from './block.json';

function Edit( props ) {
	const { attributes, setAttributes } = props;
	const options = attributes.options || [];
	const dropdown = ! attributes.multiple && attributes.display === 'dropdown';
	const chips = attributes.display === 'chips';
	const blockProps = useBlockProps( {
		className: dropdown
			? 'cbandit-field cbandit-field--dropdown'
			: 'cbandit-field cbandit-field--choice' +
				( chips ? ' cbandit-field--chips' : '' ),
	} );
	const required = attributes.required && (
		<span className="cbandit-required">
			{ ' ' }
			{ __( '(required)', 'campaign-bandit' ) }
		</span>
	);
	return (
		<>
			<FieldSettings { ...props } placeholder={ false }>
				<TextareaControl
					label={ __( 'Options (one per line)', 'campaign-bandit' ) }
					value={ options.join( '\n' ) }
					onChange={ ( value ) =>
						setAttributes( {
							options: value
								.split( '\n' )
								.map( ( o ) => o.trim() )
								.filter( Boolean ),
						} )
					}
					__nextHasNoMarginBottom
				/>
				<ToggleControl
					label={ __(
						'Visitors can choose several',
						'campaign-bandit'
					) }
					checked={ Boolean( attributes.multiple ) }
					onChange={ ( multiple ) => setAttributes( { multiple } ) }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Show as', 'campaign-bandit' ) }
					value={
						attributes.multiple && attributes.display === 'dropdown'
							? 'options'
							: attributes.display || 'options'
					}
					options={ [
						{
							value: 'options',
							label: __( 'Options', 'campaign-bandit' ),
						},
						{
							value: 'chips',
							label: __(
								'Chips (buttons in a row)',
								'campaign-bandit'
							),
						},
						...( attributes.multiple
							? []
							: [
									{
										value: 'dropdown',
										label: __(
											'Dropdown',
											'campaign-bandit'
										),
									},
								] ),
					] }
					onChange={ ( display ) => setAttributes( { display } ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				{ dropdown && (
					<TextControl
						label={ __( 'First entry', 'campaign-bandit' ) }
						help={ __(
							'Shown until the visitor picks. Leave empty for "Choose…".',
							'campaign-bandit'
						) }
						value={ attributes.placeholder }
						onChange={ ( placeholder ) =>
							setAttributes( { placeholder } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				) }
			</FieldSettings>
			{ dropdown ? (
				<div { ...blockProps }>
					<div className="cbandit-preview">
						{ attributes.label }
						{ required }
						<select disabled aria-label={ attributes.label }>
							<option>
								{ attributes.placeholder ||
									__( 'Choose…', 'campaign-bandit' ) }
							</option>
						</select>
					</div>
				</div>
			) : (
				<fieldset { ...blockProps }>
					<legend>
						{ attributes.label }
						{ required }
					</legend>
					<div className="cbandit-options">
						{ options.map( ( option ) => (
							<span
								key={ option }
								className={
									'cbandit-option' +
									( chips ? ' cbandit-chip' : '' )
								}
							>
								<input
									type={
										attributes.multiple
											? 'checkbox'
											: 'radio'
									}
									aria-label={ option }
									disabled
								/>{ ' ' }
								{ option }
							</span>
						) ) }
					</div>
				</fieldset>
			) }
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
