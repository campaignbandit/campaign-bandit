/**
 * Appearance rules without WordPress dependencies, so they can be unit-tested: which
 * starter pattern a campaign came from, the settings it brings, and the backdrop
 * colour.
 */

/**
 * The widths each format uses when none is set (render.js).
 */
export const DEFAULT_WIDTHS = { modal: 560, slide_in: 360, inline: 640 };

/**
 * The starter pattern a block came from, from its "cbandit-pattern--slug" class.
 *
 * @param {Object[]} blocks Top-level blocks.
 * @return {string} Slug, or ''.
 */
export function patternOf( blocks ) {
	for ( const block of blocks || [] ) {
		const match = /(?:^|\s)cbandit-pattern--([a-z0-9-]+)/.exec(
			block?.attributes?.className || ''
		);
		if ( match ) {
			return match[ 1 ];
		}
	}
	return '';
}

/**
 * The settings a starter pattern brings, or null when it has been applied already.
 *
 * @param {string} slug     Pattern slug.
 * @param {Object} settings Current settings.
 * @param {Object} patterns Pattern defaults by slug.
 * @return {Object|null} A settings patch.
 */
export function patternPatch( slug, settings, patterns ) {
	if ( ! slug || settings.pattern === slug || ! patterns?.[ slug ] ) {
		return null;
	}
	return { ...patterns[ slug ], pattern: slug };
}

/**
 * The backdrop colour as CSS, mirroring CampaignSettings::backdrop_css().
 *
 * @param {Object} appearance Appearance settings.
 * @return {string} rgba().
 */
export function backdropCss( appearance = {} ) {
	const hex = /^#[0-9a-f]{6}$/i.test( appearance.backdrop || '' )
		? appearance.backdrop
		: '#000000';
	const opacity = ( appearance.backdrop_opacity ?? 55 ) / 100;
	const channel = ( at ) => parseInt( hex.slice( at, at + 2 ), 16 );
	return `rgba(${ channel( 1 ) },${ channel( 3 ) },${ channel(
		5
	) },${ opacity })`;
}

/**
 * System font stacks, mirroring CampaignStyles::SYSTEM_FONTS.
 */
export const SYSTEM_FONTS = {
	sans: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
	serif: 'Georgia, Cambria, "Times New Roman", Times, serif',
	mono: 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
};

/**
 * Campaign styles as values, mirroring CampaignStyles::variables(): every value set,
 * unset ones taking their defaults. Null when campaign styles are off.
 *
 * @param {Object} styles Styles settings.
 * @return {Object|null} Font, colours, radius and phone scale.
 */
export function styleValues( styles = {} ) {
	if ( ! styles?.enabled ) {
		return null;
	}
	const pick = ( value, fallback ) => value || fallback;
	const font = ( value ) => SYSTEM_FONTS[ value ] || value;
	const text = pick( styles.font, 'sans' );
	const accent = pick( styles.accent, '#1d2327' );
	return {
		font: font( text ),
		headingFont: font( pick( styles.heading_font, text ) ),
		text: pick( styles.text, '#1d2327' ),
		background: pick( styles.background, '#ffffff' ),
		accent,
		accentText: pick( styles.accent_text, '#ffffff' ),
		accentHover: pick( styles.accent_hover, accent ),
		radius: Number( styles.radius ) || 0,
		mobile: ( Number( styles.mobile_scale ) || 100 ) / 100,
	};
}

/**
 * The editor canvas with the campaign's own styles, so it looks as it will on sites.
 *
 * @param {Object} styles Styles settings.
 * @param {string} root   Selector of the campaign's frame in the canvas.
 * @return {string} CSS, or '' when campaign styles are off.
 */
export function stylesCss( styles, root ) {
	const v = styleValues( styles );
	if ( ! v ) {
		return '';
	}
	return `${ root }{font-family:${ v.font }!important;color:${ v.text }!important;background:${ v.background }!important;border-radius:${ v.radius }px}
${ root } :is(h1,h2,h3,h4,h5,h6){font-family:${ v.headingFont }!important;color:inherit}
${ root } a:not(.wp-block-button__link){color:${ v.accent }}
${ root } .wp-block-button__link,${ root } .cbandit-form>.wp-element-button{background:${ v.accent };color:${ v.accentText };border-radius:${ v.radius }px!important;font-family:inherit}
${ root } .wp-block-button__link:hover,${ root } .cbandit-form>.wp-element-button:hover{background:${ v.accentHover }}
${ root } .cbandit-preview :is(input,select,textarea){border-radius:${ v.radius }px}
`;
}

/**
 * Where a modal sits in the canvas, for its 9-point position.
 *
 * @param {string} position Position.
 * @return {string} Margins.
 */
export function modalMargins( position = 'center' ) {
	const [ first, second ] = String( position || 'center' ).split( '_' );
	const vertical = [ 'top', 'bottom' ].includes( first ) ? first : 'center';
	const horizontal = [ 'left', 'right' ].includes( second || first )
		? second || first
		: 'center';
	const top = {
		top: 'margin-top:24px!important;margin-bottom:auto!important;',
		center: 'margin-top:72px!important;margin-bottom:48px!important;',
		bottom: 'margin-top:auto!important;margin-bottom:24px!important;',
	}[ vertical ];
	const side = {
		left: 'margin-left:32px!important;margin-right:auto!important;',
		center: 'margin-left:auto!important;margin-right:auto!important;',
		right: 'margin-left:auto!important;margin-right:32px!important;',
	}[ horizontal ];
	return top + side;
}
