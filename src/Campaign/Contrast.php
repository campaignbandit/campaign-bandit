<?php
/**
 * Contrast check (05 §6): the editor warns when a campaign's own colours make text hard
 * to read: below 4.5:1 for normal text, or 3:1 for headings and large text (WCAG 2.1 AA).
 *
 * Only colours the campaign sets are checked, on the block that sets them or inherits
 * them. A block on a background that can't be known (an image, a gradient) isn't
 * checked, and neither is a button with only one of its two colours set, as the theme
 * styles the other.
 *
 * WordPress-free, so the rules are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

/**
 * Contrast.
 */
final class Contrast {

	/** WCAG AA for normal text. */
	public const NORMAL = 4.5;

	/** WCAG AA for large text and headings. */
	public const LARGE = 3.0;

	/** The campaign panel's own colours (runtime CSS). */
	public const PANEL_TEXT       = '#1d2327';
	public const PANEL_BACKGROUND = '#ffffff';

	/**
	 * Blocks whose colours make text too hard to read.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Parsed blocks (tree).
	 * @param array<string, string>            $palette Theme colours by slug.
	 * @return array<int, array{block: string, text: string, background: string, ratio: float, needed: float}>
	 */
	public static function problems( array $blocks, array $palette ): array {
		$out = array();
		self::walk( $blocks, $palette, self::PANEL_TEXT, self::PANEL_BACKGROUND, false, $out );
		return $out;
	}

	/**
	 * The contrast ratio of two colours, from 1 to 21.
	 *
	 * @param string $a One colour.
	 * @param string $b Other colour.
	 */
	public static function ratio( string $a, string $b ): ?float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		if ( null === $la || null === $lb ) {
			return null;
		}
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Walk blocks, carrying the text and background colours they inherit.
	 *
	 * @param array<int, array<string, mixed>>                                                                $blocks     Blocks.
	 * @param array<string, string>                                                                           $palette    Theme colours.
	 * @param string|null                                                                                     $text       Inherited text colour, null when unknown.
	 * @param string|null                                                                                     $background Inherited background, null when unknown.
	 * @param bool                                                                                            $custom     Whether the campaign set a colour on a block around this one.
	 * @param array<int, array{block: string, text: string, background: string, ratio: float, needed: float}> $out        Problems found.
	 */
	private static function walk( array $blocks, array $palette, ?string $text, ?string $background, bool $custom, array &$out ): void {
		foreach ( $blocks as $block ) {
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = (array) ( $block['attrs'] ?? array() );
			if ( '' === $name ) {
				continue;
			}
			$own_text = self::colour( $attrs['style']['color']['text'] ?? null, $attrs['textColor'] ?? null, $palette );
			$own_bg   = self::colour( $attrs['style']['color']['background'] ?? null, $attrs['backgroundColor'] ?? null, $palette );
			$block_bg = $own_bg ?? $background;
			// An image or gradient behind the text: its colour can't be known.
			if ( null === $own_bg && ( 'core/cover' === $name || ! empty( $attrs['gradient'] ) || ! empty( $attrs['style']['color']['gradient'] ) || ! empty( $attrs['style']['background']['backgroundImage'] ) ) ) {
				$block_bg = null;
			}
			$block_text = $own_text ?? $text;

			$sets   = $custom || null !== $own_text || null !== $own_bg;
			$button = 'core/button' === $name;
			// A button the theme styles is left alone unless the campaign sets both colours.
			if ( $sets && ( ! $button || ( null !== $own_text && null !== $own_bg ) ) && null !== $block_text && null !== $block_bg && self::has_text( $name ) ) {
				$ratio  = self::ratio( $block_text, $block_bg );
				$needed = self::large( $name, $attrs ) ? self::LARGE : self::NORMAL;
				if ( null !== $ratio && $ratio < $needed ) {
					$out[] = array(
						'block'      => $name,
						'text'       => $block_text,
						'background' => $block_bg,
						'ratio'      => floor( $ratio * 10 ) / 10,
						'needed'     => $needed,
					);
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				// A button's colours stay on the button.
				self::walk( $block['innerBlocks'], $palette, $button ? $text : $block_text, $button ? $background : $block_bg, $button ? $custom : $sets, $out );
			}
		}//end foreach
	}

	/**
	 * Whether a block shows text of its own (containers are checked through their
	 * children, which inherit their colours).
	 *
	 * @param string $name Block name.
	 */
	private static function has_text( string $name ): bool {
		return ! in_array( $name, array( 'core/group', 'core/columns', 'core/column', 'core/cover', 'core/buttons', 'core/image', 'core/spacer', 'core/separator', 'core/media-text', 'campaign-bandit/form' ), true );
	}

	/**
	 * Whether text counts as large: a heading, or 24px and over (WCAG: 18pt).
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Attributes.
	 */
	private static function large( string $name, array $attrs ): bool {
		if ( 'core/heading' === $name ) {
			return true;
		}
		$size = $attrs['style']['typography']['fontSize'] ?? null;
		if ( is_string( $size ) && 1 === preg_match( '/^([\d.]+)(px|rem|em)$/', trim( $size ), $m ) ) {
			$px = 'px' === $m[2] ? (float) $m[1] : (float) $m[1] * 16;
			return $px >= 24;
		}
		return false;
	}

	/**
	 * A block's colour: a custom value, or a theme colour by slug; null when it sets none
	 * or it can't be read (a CSS variable, say).
	 *
	 * @param mixed                 $custom  Custom colour.
	 * @param mixed                 $slug    Theme colour slug.
	 * @param array<string, string> $palette Theme colours.
	 */
	private static function colour( $custom, $slug, array $palette ): ?string {
		if ( is_string( $custom ) && str_starts_with( $custom, 'var:preset|color|' ) ) {
			$slug   = substr( $custom, strlen( 'var:preset|color|' ) );
			$custom = null;
		}
		if ( is_string( $custom ) && null !== self::rgb( $custom ) ) {
			return strtolower( $custom );
		}
		if ( is_string( $slug ) && isset( $palette[ $slug ] ) && null !== self::rgb( $palette[ $slug ] ) ) {
			return strtolower( $palette[ $slug ] );
		}
		return null;
	}

	/**
	 * Relative luminance (WCAG), or null for a colour that can't be read.
	 *
	 * @param string $colour Colour.
	 */
	private static function luminance( string $colour ): ?float {
		$rgb = self::rgb( $colour );
		if ( null === $rgb ) {
			return null;
		}
		$linear = array_map(
			static function ( int $c ): float {
				$s = $c / 255;
				return $s <= 0.03928 ? $s / 12.92 : ( ( $s + 0.055 ) / 1.055 ) ** 2.4;
			},
			$rgb
		);
		return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
	}

	/**
	 * Red, green and blue from #rgb, #rrggbb or rgb()/rgba(); null otherwise. A colour
	 * with transparency can't be judged on its own, so it reads as null.
	 *
	 * @param string $colour Colour.
	 * @return array{0: int, 1: int, 2: int}|null
	 */
	private static function rgb( string $colour ): ?array {
		$colour = strtolower( trim( $colour ) );
		if ( 1 === preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $colour, $m ) ) {
			$hex = 3 === strlen( $m[1] ) ? $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] : $m[1];
			return array( (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ) );
		}
		if ( 1 === preg_match( '/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+)\s*)?\)$/', $colour, $m ) ) {
			if ( isset( $m[4] ) && (float) $m[4] < 1 ) {
				return null;
			}
			return array( min( 255, (int) $m[1] ), min( 255, (int) $m[2] ), min( 255, (int) $m[3] ) );
		}
		return null;
	}
}
