/**
 * A place in the editor's top bar for Campaign Bandit's own button (S4).
 *
 * The editor has no slot for a top-bar button, so this adds an element just before
 * core's (hidden) publish button and puts it back if the header is re-rendered,
 * as it is when switching to distraction-free mode and back.
 */
import { useEffect, useState } from '@wordpress/element';

const HEADER = '.editor-header__settings, .edit-post-header__settings';

/**
 * Find the header and add the slot to it, once.
 *
 * @param {HTMLElement} slot Slot element.
 * @return {boolean} Whether the slot is in the header.
 */
function place( slot ) {
	const header = document.querySelector( HEADER );
	if ( ! header ) {
		return false;
	}
	if ( slot.parentNode === header ) {
		return true;
	}
	const before =
		header.querySelector( '.editor-post-publish-button__button' ) ||
		header.querySelector( '.editor-post-publish-panel__toggle' );
	if ( before && before.parentNode === header ) {
		header.insertBefore( slot, before );
	} else {
		header.appendChild( slot );
	}
	return true;
}

/**
 * The slot element, or null until the header exists.
 *
 * @return {HTMLElement|null} Element to render into with createPortal.
 */
export function useHeaderSlot() {
	const [ slot, setSlot ] = useState( null );
	useEffect( () => {
		const element = document.createElement( 'div' );
		element.className = 'cbandit-header-slot';
		const keep = () => {
			if ( ! element.isConnected && place( element ) ) {
				setSlot( element );
			}
		};
		keep();
		const observer = new window.MutationObserver( keep );
		observer.observe( document.body, { childList: true, subtree: true } );
		return () => {
			observer.disconnect();
			element.remove();
		};
	}, [] );
	return slot;
}
