/**
 * The editor canvas as a stage (S4): the campaign drawn where it will appear, over
 * a placeholder page — centred over a dimmed page (modal), in a corner (slide-in),
 * across the top or bottom (bar), or among page content (inline).
 *
 * The CSS is built from the campaign's settings and handed to the editor as one of
 * its canvas styles, so it follows a Format change at once and survives the canvas
 * being reloaded (device preview).
 */
import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';

import { backdropCss, modalMargins, stylesCss } from './appearance-rules';

const SOURCE = 'campaign-bandit-stage';

/**
 * Widths that match the front end (render.js).
 */
export const WIDTHS = { modal: 560, slide_in: 360, inline: 640 };

/**
 * The frame width: the campaign's own, or the format's.
 *
 * @param {Object} settings Campaign settings.
 * @param {string} format   Format.
 * @return {number} Pixels.
 */
const widthOf = ( settings, format ) =>
	settings.appearance?.width || WIDTHS[ format ] || WIDTHS.modal;

/**
 * The strip above the stage: what the user is looking at.
 *
 * @param {Object} settings Campaign settings.
 * @return {string} Text.
 */
export function stageLabel( settings ) {
	const corner = {
		bottom_right: __( 'bottom right', 'campaign-bandit' ),
		bottom_left: __( 'bottom left', 'campaign-bandit' ),
		top_right: __( 'top right', 'campaign-bandit' ),
		top_left: __( 'top left', 'campaign-bandit' ),
	}[ settings.corner || 'bottom_right' ];
	switch ( settings.format ) {
		case 'slide_in':
			return sprintf(
				/* translators: 1: width in pixels, 2: corner, such as "bottom right". */
				__( 'Slide-in · %1$d px · %2$s corner', 'campaign-bandit' ),
				widthOf( settings, 'slide_in' ),
				corner
			);
		case 'bar':
			return settings.bar_position === 'top'
				? __( 'Bar · full width · top of the page', 'campaign-bandit' )
				: __(
						'Bar · full width · bottom of the page',
						'campaign-bandit'
					);
		case 'inline':
			return __(
				'Inline · placed in page content with the block or shortcode',
				'campaign-bandit'
			);
		default:
			return sprintf(
				/* translators: %d: width in pixels. */
				__(
					'Modal · up to %d px · shown over a page preview',
					'campaign-bandit'
				),
				widthOf( settings, 'modal' )
			);
	}
}

/**
 * A CSS string literal.
 *
 * @param {string} text Text.
 * @return {string} Quoted for CSS.
 */
const cssString = ( text ) =>
	'"' + String( text ).replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' ) + '"';

/**
 * Canvas CSS for the campaign's format.
 *
 * @param {Object} settings Campaign settings.
 * @return {string} CSS.
 */
export function stageCss( settings ) {
	const format = settings.format || 'modal';
	const top =
		( format === 'slide_in' &&
			String( settings.corner || '' ).startsWith( 'top' ) ) ||
		( format === 'bar' && settings.bar_position === 'top' );
	const left =
		format === 'slide_in' &&
		String( settings.corner || '' ).endsWith( 'left' );

	// Placeholder page: a header, a hero, lines of text.
	const page = [
		'linear-gradient(#d6d7d9,#d6d7d9) 32px 28px/26% 14px no-repeat',
		'linear-gradient(#e4e5e7,#e4e5e7) 32px 64px/calc(100% - 64px) 150px no-repeat',
		'linear-gradient(#e4e5e7,#e4e5e7) 32px 238px/62% 10px no-repeat',
		'linear-gradient(#e4e5e7,#e4e5e7) 32px 260px/48% 10px no-repeat',
		'linear-gradient(#e4e5e7,#e4e5e7) 32px 282px/56% 10px no-repeat',
		'#fff',
	];
	if ( format === 'modal' ) {
		// The dimmed backdrop, as visitors see it.
		const scrim = backdropCss( settings.appearance );
		page.unshift( `linear-gradient(${ scrim },${ scrim })` );
	}

	let panel;
	switch ( format ) {
		case 'slide_in':
			panel = `width:min(${ widthOf(
				settings,
				'slide_in'
			) }px,calc(100% - 32px));${
				left
					? 'margin-left:16px!important;'
					: 'margin-left:auto!important;margin-right:16px!important;'
			}${
				top
					? 'margin-top:16px!important;'
					: 'margin-top:auto!important;margin-bottom:16px!important;'
			}border:1px solid rgba(0,0,0,.2);`;
			break;
		case 'bar':
			panel = `width:100%;min-height:60px;padding:8px 60px 8px 16px!important;${
				top ? 'margin-top:0!important;' : 'margin-top:auto!important;'
			}`;
			break;
		case 'inline':
			panel = `width:min(${ WIDTHS.inline }px,calc(100% - 64px));margin:320px auto 48px!important;outline:1px dashed #8c8f94;outline-offset:8px;`;
			break;
		default:
			panel = `width:min(${ widthOf(
				settings,
				'modal'
			) }px,calc(100% - 32px));${ modalMargins(
				settings.appearance?.position
			) }box-shadow:0 8px 32px rgba(0,0,0,.3);`;
	}

	// Edge to edge: the content brings its own spacing.
	if ( settings.appearance?.edge ) {
		panel += 'padding:0!important;';
	}

	return `
body.editor-styles-wrapper{display:flex;flex-direction:column;box-sizing:border-box;min-height:${
		// Corner and edge formats need page around them to show where they sit.
		format === 'slide_in' || format === 'bar' ? 'max(100vh,820px)' : '100vh'
	};margin:0;padding:12px 24px 24px!important;background:#f0f0f1;position:relative}
body.editor-styles-wrapper::after{content:none!important}
body.editor-styles-wrapper::before{content:"";position:absolute;inset:56px 24px 24px;background:${ page.join(
		','
	) };pointer-events:none}
.editor-visual-editor__post-title-wrapper{position:relative;z-index:1;display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:4px 16px;margin:0 0 12px!important;padding:0!important;min-height:32px}
.editor-visual-editor__post-title-wrapper .wp-block-post-title{margin:0!important;max-width:none!important;flex:1 1 240px;font-size:18px!important;line-height:32px!important;font-weight:600!important}
.editor-visual-editor__post-title-wrapper::after{content:${ cssString(
		stageLabel( settings )
	) };font:12px/32px -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#50575e}
.is-root-container.wp-block-post-content{position:relative;z-index:1;box-sizing:border-box;flex:0 0 auto;background:#fff;padding:24px!important;min-height:120px;${ panel }}
.is-root-container.wp-block-post-content>*{max-width:none!important;margin-left:0!important;margin-right:0!important}
${
	format === 'inline'
		? ''
		: '.is-root-container.wp-block-post-content::before{content:"\\00d7";position:absolute;top:6px;right:12px;font:24px/1 sans-serif;color:#50575e;pointer-events:none}'
}
.cbandit-form>.block-editor-inner-blocks>.block-editor-block-list__layout{display:flex;flex-wrap:wrap;gap:12px 16px;margin-bottom:12px}
.cbandit-form>.block-editor-inner-blocks>.block-editor-block-list__layout>*{flex:1 1 180px}
.cbandit-form .cbandit-field{margin:0!important}
.cbandit-form .cbandit-field--consent,.cbandit-form .cbandit-field--choice,.cbandit-form .cbandit-field--long_text,.cbandit-form .block-list-appender{flex-basis:100%!important}
.cbandit-form fieldset.cbandit-field{border:0;padding:0;min-width:0;font-size:14px}
.cbandit-form fieldset.cbandit-field legend{padding:0;margin-bottom:6px;font-weight:600}
.cbandit-option{display:flex;align-items:center;gap:8px;margin:4px 0;font-size:14px}
.cbandit-preview{display:block;font-size:14px;font-weight:600}
.cbandit-preview .cbandit-required{font-weight:400;color:#646970}
.cbandit-preview input,.cbandit-preview textarea,.cbandit-preview select{display:block;margin-top:6px;width:100%;box-sizing:border-box;min-height:48px;padding:10px 14px;border:1px solid #8c8f94;border-radius:0;background:#fff;font:inherit;font-weight:400}
.cbandit-field--chips .cbandit-options{display:flex;flex-wrap:wrap;gap:8px}
.cbandit-chip{margin:0!important;min-height:44px;padding:8px 18px;border:1px solid #8c8f94}
.cbandit-chip input{display:none}
.cbandit-form--row{display:flex;flex-wrap:wrap;gap:12px 0;align-items:flex-start}
.cbandit-form--row>.block-editor-inner-blocks,.cbandit-form--row>.block-editor-inner-blocks>.block-editor-block-list__layout{display:contents}
.cbandit-form--row .cbandit-field{flex:1 1 120px;min-width:0}
.cbandit-form--row .cbandit-field--choice,.cbandit-form--row .cbandit-field--consent{flex-basis:100%;order:2}
.cbandit-form--row .cbandit-preview input,.cbandit-form--row>.cbandit-submit{min-height:48px;height:48px}
.cbandit-form--row>.cbandit-submit{flex:0 0 auto;width:auto;padding-left:20px;padding-right:20px;order:1}
.cbandit-form--row .block-list-appender{flex-basis:100%;order:3}
.cbandit-after{outline:1px dashed #8c8f94;outline-offset:6px;margin-top:24px!important}
.cbandit-after__label{margin:0 0 8px!important;font:12px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif!important;color:#50575e!important;letter-spacing:0!important;text-transform:none!important}
.is-style-cbandit-dismiss .wp-block-button__link{background:none!important;color:inherit!important;padding:8px 4px!important;text-decoration:underline;border:0}
.cbandit-field--consent{font-size:14px}
.cbandit-form>.wp-element-button{display:block;width:100%;min-height:48px;border-radius:0}
${ stylesCss( settings.styles, '.is-root-container.wp-block-post-content' ) }`;
}

/**
 * Keep the stage style in the editor's canvas styles, in step with the settings.
 *
 * @param {Object} props          Props.
 * @param {Object} props.settings Campaign settings.
 * @return {null} Renders nothing.
 */
export function Stage( { settings } ) {
	const styles = useSelect(
		( select ) => select( editorStore ).getEditorSettings().styles,
		[]
	);
	const { updateEditorSettings } = useDispatch( editorStore );
	const css = stageCss( settings );

	useEffect( () => {
		const current = ( styles || [] ).find(
			( style ) => style.source === SOURCE
		);
		if ( current && current.css === css ) {
			return;
		}
		const others = ( styles || [] ).filter(
			( style ) => style.source !== SOURCE
		);
		updateEditorSettings( {
			styles: [ ...others, { css, source: SOURCE } ],
		} );
	}, [ css, styles, updateEditorSettings ] );

	return null;
}
