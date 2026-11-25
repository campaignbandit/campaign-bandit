/**
 * Preview live (FR-28, decision 0022): a link opens a real page with one campaign
 * shown at once, to this browser only. Nothing is counted, no analytics are sent, the
 * visitor's history is left alone, and a form shows its thank-you without sending a
 * lead. The token is kept for this tab, so the preview follows the person around the
 * site until it expires or they choose Exit.
 */

import { show } from './render.js';
import { attachForms } from './forms.js';

export const PARAM = 'cbandit_preview';
const KEY = 'cbandit_preview';
const TOKEN = /^[lh][0-9a-f]{32}$/;

/**
 * The preview token for this page: from the link, or kept from earlier in this tab.
 *
 * @param {Window}       win     Window.
 * @param {Storage|null} session Session storage, or null when blocked.
 * @return {string} Token, or ''.
 */
export function previewToken( win, session ) {
	const fromLink =
		new URLSearchParams( win.location.search ).get( PARAM ) || '';
	if ( TOKEN.test( fromLink ) ) {
		try {
			session?.setItem( KEY, fromLink );
		} catch {
			// Without storage, the preview shows on this page only.
		}
		return fromLink;
	}
	try {
		const kept = session?.getItem( KEY ) || '';
		return TOKEN.test( kept ) ? kept : '';
	} catch {
		return '';
	}
}

/**
 * Forget the preview in this tab.
 *
 * @param {Storage|null} session Session storage.
 */
export function forgetPreview( session ) {
	try {
		session?.removeItem( KEY );
	} catch {
		// Nothing kept.
	}
}

/**
 * Show the campaign a token stands for.
 *
 * @param {Object}       config  Runtime configuration.
 * @param {Window}       win     Window.
 * @param {string}       token   Token.
 * @param {Storage|null} session Session storage.
 * @return {Promise<{opened: string[], preview: boolean}>} What opened.
 */
export async function runPreview( config, win, token, session ) {
	const doc = win.document;
	const res = await win.fetch(
		`${ config.rest }preview?token=${ encodeURIComponent( token ) }`,
		{ cache: 'no-store', credentials: 'omit' }
	);
	const data = res.ok ? await res.json() : null;
	const deployment = data?.deployments?.[ 0 ];
	if ( ! deployment ) {
		// Expired or unknown: stop following this tab around.
		forgetPreview( session );
		return { opened: [], preview: false };
	}
	const html = data.content?.[ deployment.content ] ?? '';

	let host = null;
	if ( deployment.format === 'inline' ) {
		// Where the campaign is placed on this page, or else at the top of the content.
		host = doc.querySelector(
			`[data-cbandit-campaign="${ deployment.campaign }"]`
		);
		if ( ! host ) {
			host = doc.createElement( 'div' );
			( doc.querySelector( 'main' ) || doc.body ).prepend( host );
		}
		host.hidden = false;
	}
	show(
		deployment,
		html,
		{ onClose: () => {}, onGoal: () => {} },
		host,
		doc
	);

	attachForms( {
		doc,
		win,
		config: { ...config, previewMode: true },
		context: () => ( {
			params: {},
			device: '',
			pageGroup: '',
			explanation: () => null,
			source: () => ( {} ),
		} ),
		onSuccess: () => {},
	} );

	previewBar( doc, data.name || '', config.messages || {}, () => {
		forgetPreview( session );
		const url = new URL( win.location.href );
		url.searchParams.delete( PARAM );
		win.location.replace( url.toString() );
	} );
	return { opened: [ deployment.campaign ], preview: true };
}

/**
 * A small bar saying this is a preview, with Exit.
 *
 * @param {Document}   doc      Document.
 * @param {string}     name     Campaign name.
 * @param {Object}     messages Translated messages.
 * @param {() => void} onExit   Called on Exit.
 */
function previewBar( doc, name, messages, onExit ) {
	const bar = doc.createElement( 'div' );
	bar.className = 'cbandit-preview-bar';
	bar.setAttribute( 'role', 'status' );
	bar.style.cssText =
		'position:fixed;left:12px;bottom:12px;z-index:2147483647;display:flex;gap:12px;align-items:center;padding:8px 12px;background:#1d2327;color:#fff;font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;border-radius:2px';
	const text = doc.createElement( 'span' );
	text.textContent = (
		messages.preview || 'Preview of “%s”. Only you see it.'
	).replace( '%s', name );
	const exit = doc.createElement( 'button' );
	exit.type = 'button';
	exit.textContent = messages.previewExit || 'Exit preview';
	exit.style.cssText =
		'background:none;border:1px solid #fff;color:#fff;padding:2px 8px;cursor:pointer;font:inherit';
	exit.addEventListener( 'click', onExit );
	bar.append( text, exit );
	doc.body.append( bar );
}
