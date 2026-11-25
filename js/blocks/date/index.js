/**
 * Date field (FR-23): the browser's own date picker, with the dates allowed.
 */
import { registerBlockType } from '@wordpress/blocks';
import { RadioControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { FieldSettings, InputPreview } from '../field-edit';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: ( props ) => {
		const { attributes, setAttributes } = props;
		return (
			<>
				<FieldSettings { ...props } placeholder={ false }>
					<RadioControl
						label={ __( 'Dates allowed', 'campaign-bandit' ) }
						selected={ attributes.range || 'any' }
						options={ [
							{
								label: __( 'Any date', 'campaign-bandit' ),
								value: 'any',
							},
							{
								label: __(
									'Not in the past',
									'campaign-bandit'
								),
								value: 'not_past',
							},
							{
								label: __(
									'Within the next…',
									'campaign-bandit'
								),
								value: 'within',
							},
							{
								label: __(
									'Between two dates',
									'campaign-bandit'
								),
								value: 'between',
							},
						] }
						onChange={ ( range ) => setAttributes( { range } ) }
					/>
					{ attributes.range === 'within' && (
						<TextControl
							label={ __( 'Days from today', 'campaign-bandit' ) }
							type="number"
							min={ 1 }
							max={ 3650 }
							value={ String( attributes.withinDays ?? 365 ) }
							onChange={ ( value ) =>
								setAttributes( {
									withinDays: Math.max(
										1,
										Math.min( 3650, Number( value ) || 1 )
									),
								} )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					) }
					{ attributes.range === 'between' && (
						<>
							<TextControl
								label={ __( 'Earliest', 'campaign-bandit' ) }
								type="date"
								value={ attributes.from || '' }
								onChange={ ( from ) =>
									setAttributes( { from } )
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
							<TextControl
								label={ __( 'Latest', 'campaign-bandit' ) }
								type="date"
								value={ attributes.to || '' }
								onChange={ ( to ) => setAttributes( { to } ) }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						</>
					) }
				</FieldSettings>
				<InputPreview attributes={ attributes } type="date" />
			</>
		);
	},
	save: () => null,
} );
