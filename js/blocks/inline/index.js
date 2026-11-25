/**
 * Inline block (FR-22): the one Campaign Bandit block in normal post and page
 * editors. It lists the inline campaigns on this site; the server renders the
 * live version and the runtime decides per visitor whether it shows.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	Placeholder,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useEntityRecords } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

/**
 * Inline campaigns, from the campaign drafts' settings.
 *
 * @param {Object[]|null} records Campaign posts.
 * @return {Array<{value: string, label: string}>} Options.
 */
export function inlineOptions( records ) {
	return ( records || [] )
		.map( ( post ) => {
			let settings = {};
			try {
				settings =
					JSON.parse( post.meta?._campaignbandit_settings || '{}' ) ||
					{};
			} catch {
				settings = {};
			}
			return { post, settings };
		} )
		.filter(
			( { post, settings } ) =>
				settings.format === 'inline' && post.campaign_uid
		)
		.map( ( { post } ) => ( {
			value: post.campaign_uid,
			label:
				post.title?.raw ||
				post.title?.rendered ||
				__( '(no name)', 'campaign-bandit' ),
		} ) );
}

function Edit( { attributes, setAttributes } ) {
	const { records, hasResolved } = useEntityRecords(
		'postType',
		'cbandit_campaign',
		{
			per_page: 100,
			status: 'draft',
			context: 'edit',
		}
	);
	const options = inlineOptions( records );
	const chosen = options.find( ( o ) => o.value === attributes.campaign );
	const select = (
		<SelectControl
			label={ __( 'Inline campaign', 'campaign-bandit' ) }
			value={ attributes.campaign }
			options={ [
				{
					value: '',
					label: __( 'Choose a campaign', 'campaign-bandit' ),
				},
				...options,
			] }
			onChange={ ( campaign ) => setAttributes( { campaign } ) }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);

	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Campaign', 'campaign-bandit' ) }>
					{ select }
				</PanelBody>
			</InspectorControls>
			<Placeholder
				icon="megaphone"
				label={
					chosen
						? chosen.label
						: __( 'Campaign Bandit Inline', 'campaign-bandit' )
				}
				instructions={
					chosen
						? __(
								'Shows here to visitors this campaign qualifies for, once it is published.',
								'campaign-bandit'
							)
						: __(
								'Choose which inline campaign shows here.',
								'campaign-bandit'
							)
				}
			>
				{ ! hasResolved ? <Spinner /> : ! chosen && select }
			</Placeholder>
		</div>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
