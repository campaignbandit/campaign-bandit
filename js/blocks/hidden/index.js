/**
 * Hidden field (FR-23): a fixed value sent with every lead, such as a lead source.
 * Visitors never see it; the server takes the value from the published version.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { validKey } from '../field-edit';
import metadata from './block.json';

function Edit( { attributes, setAttributes } ) {
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Hidden field', 'campaign-bandit' ) }>
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
					<TextControl
						label={ __( 'Value', 'campaign-bandit' ) }
						help={ __(
							'Sent with every lead from this form. On a hub, each site can have its own value.',
							'campaign-bandit'
						) }
						value={ attributes.value || '' }
						onChange={ ( value ) => setAttributes( { value } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</PanelBody>
			</InspectorControls>
			<div
				{ ...useBlockProps( {
					className: 'cbandit-hidden-chip',
				} ) }
			>
				{ sprintf(
					/* translators: 1: field key, 2: value. */
					__( 'Hidden: %1$s = %2$s', 'campaign-bandit' ),
					attributes.fieldKey || '?',
					attributes.value || '—'
				) }
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
