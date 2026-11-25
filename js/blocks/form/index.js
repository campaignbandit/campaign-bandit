/**
 * Form block (FR-23): holds the field blocks; the server adds the submit button,
 * spam trap and success state.
 */
import { registerBlockType } from '@wordpress/blocks';
import {
	InnerBlocks,
	InspectorControls,
	PanelColorSettings,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

const FIELDS = [
	'campaign-bandit/text',
	'campaign-bandit/long-text',
	'campaign-bandit/email',
	'campaign-bandit/phone',
	'campaign-bandit/date',
	'campaign-bandit/choice',
	'campaign-bandit/consent',
	'campaign-bandit/hidden',
];

const TEMPLATE = [
	[
		'campaign-bandit/text',
		{ label: 'Name', fieldKey: 'name', required: true },
	],
	[
		'campaign-bandit/email',
		{ label: 'Email', fieldKey: 'email', required: true },
	],
	[ 'campaign-bandit/consent', {} ],
];

function Edit( { attributes, setAttributes } ) {
	return (
		<div
			{ ...useBlockProps( {
				className:
					'cbandit-form' +
					( attributes.layout === 'row' ? ' cbandit-form--row' : '' ),
			} ) }
		>
			<InspectorControls>
				<PanelBody title={ __( 'Form', 'campaign-bandit' ) }>
					<TextControl
						label={ __( 'Button text', 'campaign-bandit' ) }
						value={ attributes.submitLabel }
						onChange={ ( submitLabel ) =>
							setAttributes( { submitLabel } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextareaControl
						label={ __(
							'Message after a successful submission',
							'campaign-bandit'
						) }
						help={ __(
							'Replaces the form when the visitor submits.',
							'campaign-bandit'
						) }
						value={ attributes.successMessage }
						onChange={ ( successMessage ) =>
							setAttributes( { successMessage } )
						}
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Layout', 'campaign-bandit' ) }
						help={ __(
							'One row puts the fields and the button side by side, for short forms such as an email address. It stacks on small screens.',
							'campaign-bandit'
						) }
						value={ attributes.layout || 'stacked' }
						options={ [
							{
								value: 'stacked',
								label: __(
									'Stacked, button full width',
									'campaign-bandit'
								),
							},
							{
								value: 'row',
								label: __( 'One row', 'campaign-bandit' ),
							},
						] }
						onChange={ ( layout ) => setAttributes( { layout } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</PanelBody>
				<PanelColorSettings
					title={ __( 'Button colours', 'campaign-bandit' ) }
					initialOpen={ false }
					colorSettings={ [
						{
							value: attributes.buttonBackground || undefined,
							onChange: ( value ) =>
								setAttributes( {
									buttonBackground: value || '',
								} ),
							label: __( 'Background', 'campaign-bandit' ),
						},
						{
							value: attributes.buttonText || undefined,
							onChange: ( value ) =>
								setAttributes( { buttonText: value || '' } ),
							label: __( 'Text', 'campaign-bandit' ),
						},
					] }
				/>
			</InspectorControls>
			<InnerBlocks allowedBlocks={ FIELDS } template={ TEMPLATE } />
			<button
				type="button"
				className="wp-element-button cbandit-submit"
				style={ {
					backgroundColor: attributes.buttonBackground || undefined,
					color: attributes.buttonText || undefined,
				} }
				disabled
			>
				{ attributes.submitLabel || __( 'Submit', 'campaign-bandit' ) }
			</button>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => <InnerBlocks.Content />,
} );
