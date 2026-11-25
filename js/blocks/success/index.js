/**
 * Success screen: what visitors see after the form is sent. On the site it is hidden
 * until then, and replaces the blocks beside it (the runtime swaps them). A "Closes
 * the campaign" button inside it closes without counting as a dismissal.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

const TEMPLATE = [
	[
		'core/heading',
		{ level: 2, content: __( 'Thank you!', 'campaign-bandit' ) },
	],
	[
		'core/paragraph',
		{
			content: __(
				'We’ve got your details and will be in touch soon.',
				'campaign-bandit'
			),
		},
	],
	[
		'core/buttons',
		{},
		[
			[
				'core/button',
				{
					text: __( 'Close', 'campaign-bandit' ),
					url: '#',
					className: 'is-style-cbandit-dismiss',
				},
			],
		],
	],
];

function Edit() {
	return (
		<div { ...useBlockProps( { className: 'cbandit-after' } ) }>
			<p className="cbandit-after__label">
				{ __(
					'Success screen · shown after the form is sent, in place of the blocks beside it',
					'campaign-bandit'
				) }
			</p>
			<InnerBlocks template={ TEMPLATE } />
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => <InnerBlocks.Content />,
} );
