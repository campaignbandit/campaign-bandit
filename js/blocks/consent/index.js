/**
 * Consent (FR-23): the exact text shown is stored with the lead, under its purpose
 * (contact about this request, email marketing or text messages, BR-44).
 */
import { registerBlockType } from '@wordpress/blocks';
import { RichText, useBlockProps } from '@wordpress/block-editor';
import { RadioControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { FieldSettings } from '../field-edit';
import metadata from './block.json';

function Edit( props ) {
	const { attributes, setAttributes } = props;
	return (
		<>
			<FieldSettings
				{ ...props }
				placeholder={ false }
				keyControl={ false }
			>
				<RadioControl
					label={ __( 'Purpose', 'campaign-bandit' ) }
					help={ __(
						'Where this answer goes in the lead and the webhook. A form can have one Consent block for each purpose.',
						'campaign-bandit'
					) }
					selected={ attributes.purpose || 'contact' }
					options={ [
						{
							label: __(
								'Contact about this request',
								'campaign-bandit'
							),
							value: 'contact',
						},
						{
							label: __( 'Email marketing', 'campaign-bandit' ),
							value: 'email',
						},
						{
							label: __( 'Text messages', 'campaign-bandit' ),
							value: 'sms',
						},
					] }
					onChange={ ( purpose ) =>
						setAttributes( {
							purpose,
							// Each purpose has its own field key, so two Consent blocks never clash.
							fieldKey:
								purpose === 'contact'
									? 'consent'
									: 'consent_' + purpose,
						} )
					}
				/>
			</FieldSettings>
			<div
				{ ...useBlockProps( {
					className: 'cbandit-field cbandit-field--consent',
				} ) }
			>
				<input type="checkbox" disabled />{ ' ' }
				<RichText
					tagName="span"
					value={ attributes.text }
					allowedFormats={ [
						'core/bold',
						'core/italic',
						'core/link',
					] }
					placeholder={ __( 'Consent text', 'campaign-bandit' ) }
					onChange={ ( text ) => setAttributes( { text } ) }
				/>
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
