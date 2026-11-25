/**
 * Long text field (FR-23): a multi-line answer, such as a message.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { FieldSettings } from '../field-edit';
import metadata from './block.json';

function Edit( props ) {
	const { attributes } = props;
	return (
		<>
			<FieldSettings { ...props } />
			<div
				{ ...useBlockProps( {
					className: 'cbandit-field cbandit-field--long_text',
				} ) }
			>
				{ /* Editor preview only: the real, labelled field is rendered by the server. */ }
				<div className="cbandit-preview">
					{ attributes.label }
					{ attributes.required && (
						<span className="cbandit-required">
							{ ' ' }
							{ __( '(required)', 'campaign-bandit' ) }
						</span>
					) }
					<textarea
						rows={ 4 }
						placeholder={ attributes.placeholder }
						disabled
						aria-label={ attributes.label }
					/>
				</div>
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
