/**
 * Preview in the editor's top bar (FR-28, S4): saves the draft, then opens a signed
 * link that shows it on the real site for an hour. On a hub, a menu picks the site:
 * this one or one of the campaign's client sites, where it shows with that site's
 * own settings.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, Dropdown, MenuGroup, MenuItem } from '@wordpress/components';
import { createPortal, useEffect, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import apiFetch from '@wordpress/api-fetch';
import { useHeaderSlot } from './header-slot';

const config = window.campaignBanditEditor || { locked: {} };

/**
 * The Preview button.
 *
 * @param {Object} props     Props.
 * @param {string} props.uid Campaign ID.
 */
export function PreviewLive( { uid } ) {
	const slot = useHeaderSlot();
	const [ sites, setSites ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const { savePost } = useDispatch( editorStore );
	const dirty = useSelect(
		( select ) => select( editorStore ).isEditedPostDirty(),
		[]
	);
	const locked = Boolean( config.locked?.preview );

	useEffect( () => {
		if ( ! uid || locked ) {
			return;
		}
		apiFetch( {
			path: `/${ config.namespace }/campaigns/${ uid }/preview-sites`,
		} )
			.then( ( response ) => setSites( response.sites || [] ) )
			.catch( () => setSites( [] ) );
	}, [ uid, locked ] );

	if ( ! slot || ! uid ) {
		return null;
	}
	if ( locked ) {
		return createPortal(
			<Button
				variant="tertiary"
				disabled
				accessibleWhenDisabled
				label={ __(
					'Preview on your site comes with Pro',
					'campaign-bandit'
				) }
				showTooltip
			>
				{ __( 'Preview · Pro', 'campaign-bandit' ) }
			</Button>,
			slot
		);
	}
	if ( ! sites || sites.length === 0 ) {
		return null;
	}

	const open = async ( site ) => {
		// Open the tab now, while the click still counts, and send it on once the link exists.
		const tab = window.open( '', '_blank' );
		setBusy( true );
		setError( '' );
		try {
			if ( dirty ) {
				await savePost();
			}
			const response = await apiFetch( {
				path: `/${ config.namespace }/campaigns/${ uid }/preview`,
				method: 'POST',
				data: { site },
			} );
			if ( tab ) {
				tab.opener = null;
				tab.location.href = response.url;
			} else {
				window.open( response.url, '_blank', 'noopener' );
			}
		} catch ( e ) {
			tab?.close();
			setError(
				e?.message ||
					__( 'The preview could not be opened.', 'campaign-bandit' )
			);
		} finally {
			setBusy( false );
		}
	};

	const help = __(
		'Opens the saved draft on the real site, shown at once, to you only, for an hour. Nothing is sent or counted.',
		'campaign-bandit'
	);
	const errorNote = error ? (
		<span className="cbandit-preview-error" role="alert">
			{ error }
		</span>
	) : null;

	if ( sites.length === 1 ) {
		return createPortal(
			<>
				<Button
					variant="tertiary"
					isBusy={ busy }
					disabled={ busy }
					accessibleWhenDisabled
					label={ help }
					showTooltip
					onClick={ () => open( sites[ 0 ].id ) }
				>
					{ __( 'Preview', 'campaign-bandit' ) }
				</Button>
				{ errorNote }
			</>,
			slot
		);
	}

	return createPortal(
		<>
			<Dropdown
				popoverProps={ { placement: 'bottom-end' } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<Button
						variant="tertiary"
						isBusy={ busy }
						disabled={ busy }
						accessibleWhenDisabled
						aria-expanded={ isOpen }
						onClick={ onToggle }
					>
						{ __( 'Preview on…', 'campaign-bandit' ) }
					</Button>
				) }
				renderContent={ ( { onClose } ) => (
					<MenuGroup label={ __( 'Preview on', 'campaign-bandit' ) }>
						{ sites.map( ( site ) => (
							<MenuItem
								key={ site.id }
								onClick={ () => {
									onClose();
									open( site.id );
								} }
							>
								{ site.id === 0
									? site.label
									: sprintf(
											/* translators: %s: site address. */
											__(
												'%s, with its site settings',
												'campaign-bandit'
											),
											site.label
										) }
							</MenuItem>
						) ) }
						<p className="cbandit-preview-help">{ help }</p>
					</MenuGroup>
				) }
			/>
			{ errorNote }
		</>,
		slot
	);
}
