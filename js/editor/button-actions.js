/**
 * Campaign buttons (FR-23): what a Button does in a campaign (go to its link, close
 * the campaign, call or text a number) and whether it counts as the goal (BR-15).
 *
 * The settings are block attributes on core/button; the server turns them into the
 * link when it renders the campaign, so nothing is added to saved block markup.
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RadioControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export const ACTIONS = [ 'link', 'close', 'call', 'text' ];

/**
 * Whether a phone number has enough digits to dial.
 *
 * @param {string} phone Number as typed.
 * @return {boolean} Valid.
 */
export function dialable( phone ) {
	return String( phone || '' ).replace( /[^0-9]/g, '' ).length >= 3;
}

addFilter(
	'blocks.registerBlockType',
	'campaign-bandit/button-actions',
	( settings, name ) => {
		if ( name !== 'core/button' ) {
			return settings;
		}
		return {
			...settings,
			attributes: {
				...settings.attributes,
				cbanditAction: { type: 'string', enum: ACTIONS },
				cbanditPhone: { type: 'string' },
				cbanditMessage: { type: 'string' },
				cbanditGoal: { type: 'boolean' },
			},
		};
	}
);

const withCampaignButton = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => {
		if ( props.name !== 'core/button' ) {
			return <BlockEdit { ...props } />;
		}
		const { attributes, setAttributes } = props;
		const styled = /\bis-style-cbandit-dismiss\b/.test(
			attributes.className || ''
		);
		const action = styled ? 'close' : attributes.cbanditAction || 'link';
		const needsPhone = action === 'call' || action === 'text';
		return (
			<>
				<BlockEdit { ...props } />
				<InspectorControls>
					<PanelBody
						title={ __( 'Campaign button', 'campaign-bandit' ) }
					>
						{ styled ? (
							<p className="cbandit-note">
								{ __(
									'This button closes the campaign: its style is "Closes the campaign".',
									'campaign-bandit'
								) }
							</p>
						) : (
							<RadioControl
								label={ __( 'Action', 'campaign-bandit' ) }
								selected={ action }
								options={ [
									{
										label: __(
											'Go to URL',
											'campaign-bandit'
										),
										value: 'link',
									},
									{
										label: __(
											'Close the campaign',
											'campaign-bandit'
										),
										value: 'close',
									},
									{
										label: __( 'Call', 'campaign-bandit' ),
										value: 'call',
									},
									{
										label: __(
											'Text us',
											'campaign-bandit'
										),
										value: 'text',
									},
								] }
								onChange={ ( value ) =>
									setAttributes( {
										cbanditAction:
											value === 'link'
												? undefined
												: value,
									} )
								}
							/>
						) }
						{ needsPhone && (
							<TextControl
								label={ __(
									'Phone number',
									'campaign-bandit'
								) }
								help={
									dialable( attributes.cbanditPhone )
										? __(
												'On a hub, each site can use its own number.',
												'campaign-bandit'
											)
										: __(
												'Enter the number to call or text, such as +1 416 555 0148.',
												'campaign-bandit'
											)
								}
								type="tel"
								value={ attributes.cbanditPhone || '' }
								onChange={ ( cbanditPhone ) =>
									setAttributes( { cbanditPhone } )
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						) }
						{ action === 'text' && (
							<TextareaControl
								label={ __( 'Message', 'campaign-bandit' ) }
								help={ __(
									'Filled in for the visitor, who can change it. Works best on phones.',
									'campaign-bandit'
								) }
								value={ attributes.cbanditMessage || '' }
								onChange={ ( cbanditMessage ) =>
									setAttributes( { cbanditMessage } )
								}
								rows={ 2 }
								__nextHasNoMarginBottom
							/>
						) }
						{ action === 'close' && ! styled && (
							<p className="cbandit-note">
								{ __(
									'Closes the campaign and counts as a dismissal, like the close button.',
									'campaign-bandit'
								) }
							</p>
						) }
						<ToggleControl
							label={ __(
								'Counts as the goal',
								'campaign-bandit'
							) }
							help={ __(
								'For campaigns whose goal is "Button clicked": a click or tap here is a conversion.',
								'campaign-bandit'
							) }
							checked={ Boolean( attributes.cbanditGoal ) }
							onChange={ ( cbanditGoal ) =>
								setAttributes( {
									cbanditGoal: cbanditGoal || undefined,
								} )
							}
							__nextHasNoMarginBottom
						/>
					</PanelBody>
				</InspectorControls>
			</>
		);
	},
	'withCampaignButton'
);

addFilter(
	'editor.BlockEdit',
	'campaign-bandit/button-actions',
	withCampaignButton
);
