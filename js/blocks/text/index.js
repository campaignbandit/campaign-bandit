/**
 * Text field (FR-23).
 */
import { registerBlockType } from '@wordpress/blocks';
import { FieldSettings, InputPreview } from '../field-edit';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: ( props ) => (
		<>
			<FieldSettings { ...props } />
			<InputPreview attributes={ props.attributes } type="text" />
		</>
	),
	save: () => null,
} );
