/**
 * Shared editor parts for the form field blocks (FR-23): label, placeholder,
 * required, and a field key suggested from the label (BR-45).
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Suggest a field key from a label (BR-45): letters, digits and underscore,
 * starting with a letter. Mirrors FormSchema::suggest_key().
 *
 * @param {string} label Label.
 * @return {string} Key.
 */
export function suggestKey( label ) {
	let key = String( label || '' )
		.replace( /[^A-Za-z0-9]+/g, '_' )
		.replace( /^_+|_+$/g, '' )
		.toLowerCase();
	if ( ! key || ! /^[a-z]/.test( key ) ) {
		key = `field_${ key }`;
	}
	return key.replace( /_+$/, '' ).slice( 0, 64 );
}

/**
 * Whether a key is valid (BR-45).
 *
 * @param {string} key Key.
 * @return {boolean} Valid.
 */
export const validKey = ( key ) =>
	/^[A-Za-z][A-Za-z0-9_]{0,63}$/.test( key || '' );

/**
 * Label change that keeps the key in step while the editor hasn't customised it.
 *
 * @param {Object}                       attributes    Block attributes.
 * @param {(attributes: Object) => void} setAttributes Setter.
 * @return {(label: string) => void} Handler.
 */
export function labelHandler( attributes, setAttributes ) {
	return ( label ) => {
		const followsLabel =
			! attributes.fieldKey ||
			attributes.fieldKey === suggestKey( attributes.label );
		setAttributes(
			followsLabel ? { label, fieldKey: suggestKey( label ) } : { label }
		);
	};
}

/**
 * Settings panel shared by every field.
 *
 * @param {Object}                       props               Props.
 * @param {Object}                       props.attributes    Block attributes.
 * @param {(attributes: Object) => void} props.setAttributes Setter.
 * @param {boolean}                      [props.placeholder] Whether the field has a placeholder.
 * @param {boolean}                      [props.keyControl]  Whether the field key can be edited.
 * @param {Object}                       [props.children]    Extra controls.
 */
export function FieldSettings( {
	attributes,
	setAttributes,
	placeholder = true,
	keyControl = true,
	children,
} ) {
	return (
		<InspectorControls>
			<PanelBody title={ __( 'Field', 'campaign-bandit' ) }>
				{ attributes.label !== undefined && (
					<TextControl
						label={ __( 'Label', 'campaign-bandit' ) }
						value={ attributes.label }
						onChange={ labelHandler( attributes, setAttributes ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				) }
				{ placeholder && (
					<TextControl
						label={ __( 'Placeholder', 'campaign-bandit' ) }
						value={ attributes.placeholder || '' }
						onChange={ ( value ) =>
							setAttributes( { placeholder: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				) }
				{ attributes.hideLabel !== undefined && (
					<ToggleControl
						label={ __( 'Hide the label', 'campaign-bandit' ) }
						help={ __(
							'Screen readers still read it. Use on single-field forms, with a placeholder that says what to enter.',
							'campaign-bandit'
						) }
						checked={ Boolean( attributes.hideLabel ) }
						onChange={ ( hideLabel ) =>
							setAttributes( { hideLabel } )
						}
						__nextHasNoMarginBottom
					/>
				) }
				<ToggleControl
					label={ __( 'Required', 'campaign-bandit' ) }
					checked={ Boolean( attributes.required ) }
					onChange={ ( required ) => setAttributes( { required } ) }
					__nextHasNoMarginBottom
				/>
				{ keyControl && (
					<TextControl
						label={ __( 'Field key', 'campaign-bandit' ) }
						help={
							validKey( attributes.fieldKey )
								? __(
										'The name this value is sent under to destinations.',
										'campaign-bandit'
									)
								: __(
										'Use letters, digits and underscores, starting with a letter.',
										'campaign-bandit'
									)
						}
						value={ attributes.fieldKey || '' }
						onChange={ ( fieldKey ) =>
							setAttributes( { fieldKey: fieldKey.trim() } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				) }
				{ children }
			</PanelBody>
		</InspectorControls>
	);
}

/**
 * Editor preview of a single-line field.
 *
 * @param {Object} props            Props.
 * @param {Object} props.attributes Block attributes.
 * @param {string} props.type       Input type.
 */
export function InputPreview( { attributes, type } ) {
	return (
		<div { ...useBlockProps( { className: 'cbandit-field' } ) }>
			{ /* Editor preview only: the real, labelled field is rendered by the server. */ }
			<div className="cbandit-preview">
				{ attributes.label }
				{ attributes.required && (
					<span className="cbandit-required">
						{ ' ' }
						{ __( '(required)', 'campaign-bandit' ) }
					</span>
				) }
				<input
					type={ type }
					placeholder={ attributes.placeholder }
					disabled
					aria-label={ attributes.label }
				/>
			</div>
		</div>
	);
}
