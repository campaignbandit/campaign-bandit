<?php
/**
 * Campaign styles (FR-27, decision 0025): with "Use campaign styles" on, a campaign
 * sets its own fonts, colours, button hover colour, corner radius and text size on
 * phones, so it looks the same on every site whatever the theme. Off (the default),
 * it inherits each site's theme.
 *
 * Fonts are CSS font stacks: system stacks, or a theme's own font family by name. No
 * font file is ever loaded (05 §6); on a site without that font, the stack falls back.
 *
 * WordPress-free, so the rules are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

/**
 * Campaign styles.
 *
 * @phpstan-type Styles array{enabled: bool, font: string, heading_font: string, text: string, background: string, accent: string, accent_text: string, accent_hover: string, radius: int, mobile_scale: int}
 */
final class CampaignStyles {

	/** System font stacks, by key. */
	public const SYSTEM_FONTS = array(
		'sans'  => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
		'serif' => 'Georgia, Cambria, "Times New Roman", Times, serif',
		'mono'  => 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
	);

	/**
	 * What an unset value becomes with campaign styles on: every value is set, so the
	 * campaign looks the same whatever the theme.
	 */
	public const DEFAULTS = array(
		'font'        => 'sans',
		'text'        => '#1d2327',
		'background'  => '#ffffff',
		'accent'      => '#1d2327',
		'accent_text' => '#ffffff',
	);

	/** Largest corner radius, in pixels. */
	public const MAX_RADIUS = 32;

	/** Text size on phones, as a percentage of the campaign's, from and to. */
	public const MOBILE_MIN = 80;
	public const MOBILE_MAX = 120;

	/**
	 * Clean styles. Empty strings mean "not set": the theme's value stays.
	 *
	 * @param mixed $raw Candidate.
	 * @return Styles
	 */
	public static function normalise( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'enabled'      => true === ( $raw['enabled'] ?? false ),
			'font'         => self::font( $raw['font'] ?? '' ),
			'heading_font' => self::font( $raw['heading_font'] ?? '' ),
			'text'         => self::color( $raw['text'] ?? '' ),
			'background'   => self::color( $raw['background'] ?? '' ),
			'accent'       => self::color( $raw['accent'] ?? '' ),
			'accent_text'  => self::color( $raw['accent_text'] ?? '' ),
			'accent_hover' => self::color( $raw['accent_hover'] ?? '' ),
			'radius'       => max( 0, min( self::MAX_RADIUS, (int) ( is_numeric( $raw['radius'] ?? null ) ? $raw['radius'] : 0 ) ) ),
			'mobile_scale' => max( self::MOBILE_MIN, min( self::MOBILE_MAX, (int) ( is_numeric( $raw['mobile_scale'] ?? null ) ? $raw['mobile_scale'] : 100 ) ) ),
		);
	}

	/**
	 * The CSS custom properties the runtime sets on the campaign, or null when campaign
	 * styles are off. Every one is sent: an unset value takes its default (a heading
	 * font, the text font; a hover colour, the button colour).
	 *
	 * @param array<string, mixed> $styles Clean styles.
	 * @return array<string, string>|null
	 */
	public static function variables( array $styles ): ?array {
		if ( empty( $styles['enabled'] ) ) {
			return null;
		}
		$map                    = array(
			'font'         => '--cbandit-font',
			'heading_font' => '--cbandit-heading-font',
			'text'         => '--cbandit-text',
			'background'   => '--cbandit-bg',
			'accent'       => '--cbandit-accent',
			'accent_text'  => '--cbandit-accent-text',
			'accent_hover' => '--cbandit-accent-hover',
		);
		$set                    = array_filter( $styles, static fn ( $v ): bool => is_string( $v ) && '' !== $v );
		$full                   = array_merge( self::DEFAULTS, $set );
		$full['heading_font'] ??= $full['font'];
		$full['accent_hover'] ??= $full['accent'];
		$vars                   = array();
		foreach ( $map as $key => $name ) {
			$value         = (string) $full[ $key ];
			$vars[ $name ] = self::SYSTEM_FONTS[ $value ] ?? $value;
		}
		$vars['--cbandit-radius'] = (int) ( $styles['radius'] ?? 0 ) . 'px';
		$vars['--cbandit-mobile'] = (string) ( (int) ( $styles['mobile_scale'] ?? 100 ) / 100 );
		return $vars;
	}

	/**
	 * A font: a system stack key, or a font family list such as "Inter", sans-serif.
	 * Anything that could break out of a CSS value is refused.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function font( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( isset( self::SYSTEM_FONTS[ $value ] ) ) {
			return $value;
		}
		return 1 === preg_match( '/^[A-Za-z0-9 ,\'"_-]{1,200}$/', $value ) ? $value : '';
	}

	/**
	 * A colour: #rgb or #rrggbb, lowercased, or ''.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function color( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = strtolower( trim( $value ) );
		return 1 === preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value ) ? $value : '';
	}
}
