<?php
/**
 * Language copy (BR-21, BR-22, decision 0029): a site's own words for any text in a
 * campaign, keyed by a hash of the text rather than its position, so a replacement
 * survives blocks being added, removed or moved.
 *
 * WordPress-free: works on parsed blocks (the parse_blocks() shape), so the rules are
 * unit-tested. The caller parses, applies and serialises.
 *
 * A replacement is plain text and is escaped when it goes back in, so a site can never
 * put markup into a hub-authored campaign. Inline markup in the original is lost when
 * that text is replaced.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

/**
 * Finds, keys and replaces the text in a campaign.
 *
 * @phpstan-type Item array{key: string, text: string}
 */
final class LanguageCopy {

	/**
	 * Longest a replacement can be.
	 */
	public const MAX_LENGTH = 2000;

	/**
	 * Most pieces of text one site can replace.
	 */
	public const MAX = 200;

	/**
	 * Blocks whose own markup holds the text.
	 */
	private const MARKUP_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/list-item', 'core/verse' );

	/**
	 * Blocks with text in an attribute: block name => attribute names.
	 */
	private const ATTRIBUTE_BLOCKS = array(
		'core/button'               => array( 'text' ),
		'core/details'              => array( 'summary' ),
		'campaign-bandit/text'      => array( 'label', 'placeholder' ),
		'campaign-bandit/long-text' => array( 'label', 'placeholder' ),
		'campaign-bandit/email'     => array( 'label', 'placeholder' ),
		'campaign-bandit/phone'     => array( 'label', 'placeholder' ),
		'campaign-bandit/date'      => array( 'label' ),
		'campaign-bandit/choice'    => array( 'label' ),
		'campaign-bandit/consent'   => array( 'text' ),
		'campaign-bandit/success'   => array( 'text' ),
	);

	/**
	 * The key for a piece of text: short, stable, and the same wherever that text is.
	 *
	 * @param string $text Text, as `plain()` gives it.
	 */
	public static function key( string $text ): string {
		return substr( sha1( self::plain( $text ) ), 0, 10 );
	}

	/**
	 * Text as a person reads it: inline markup removed, whitespace collapsed.
	 *
	 * @param string $raw Raw text or markup.
	 */
	public static function plain( string $raw ): string {
		$text = (string) preg_replace( '/<[^>]*>/', '', $raw );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Every piece of text in the campaign, once per distinct text, in the order first
	 * seen. This is what the hub lists for a site to translate.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, Item>
	 */
	public static function items( array $blocks ): array {
		$items = array();
		foreach ( self::walk( $blocks ) as $text ) {
			$key = self::key( $text );
			if ( ! isset( $items[ $key ] ) ) {
				$items[ $key ] = array(
					'key'  => $key,
					'text' => self::plain( $text ),
				);
			}
		}
		return array_values( $items );
	}

	/**
	 * The keys the campaign has text for.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return string[]
	 */
	public static function keys( array $blocks ): array {
		return array_column( self::items( $blocks ), 'key' );
	}

	/**
	 * Clean a site's replacements: key => text, each a key this campaign could have.
	 *
	 * @param mixed $raw Candidate.
	 * @return array<string, string>
	 */
	public static function normalise( $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $key => $text ) {
			$key  = is_string( $key ) && 1 === preg_match( '/^[0-9a-f]{10}$/', $key ) ? $key : '';
			$text = self::replacement( $text );
			if ( '' !== $key && '' !== $text && count( $out ) < self::MAX ) {
				$out[ $key ] = $text;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * A replacement: plain text, not too long. Empty means "keep the campaign's".
	 *
	 * @param mixed $raw Candidate.
	 */
	public static function replacement( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$text = self::plain( $raw );
		return '' === $text ? '' : mb_substr( $text, 0, self::MAX_LENGTH );
	}

	/**
	 * The blocks with a site's words in place of the campaign's.
	 *
	 * @param array<int, array<string, mixed>> $blocks       Parsed blocks.
	 * @param array<string, string>            $replacements Clean replacements.
	 * @return array<int, array<string, mixed>>
	 */
	public static function apply( array $blocks, array $replacements ): array {
		if ( array() === $replacements ) {
			return $blocks;
		}
		foreach ( $blocks as $i => $block ) {
			$name = (string) ( $block['blockName'] ?? '' );

			foreach ( self::ATTRIBUTE_BLOCKS[ $name ] ?? array() as $attribute ) {
				$text = $block['attrs'][ $attribute ] ?? null;
				if ( is_string( $text ) && '' !== self::plain( $text ) ) {
					$new = $replacements[ self::key( $text ) ] ?? null;
					if ( null !== $new ) {
						$blocks[ $i ]['attrs'][ $attribute ] = $new;
					}
				}
			}

			if ( in_array( $name, self::MARKUP_BLOCKS, true ) ) {
				$blocks[ $i ] = self::replace_markup( $blocks[ $i ], $replacements );
			}

			if ( is_array( $block['innerBlocks'] ?? null ) && array() !== $block['innerBlocks'] ) {
				$blocks[ $i ]['innerBlocks'] = self::apply( $block['innerBlocks'], $replacements );
			}
		}//end foreach
		return $blocks;
	}

	/**
	 * The keys a site has replacements for that the campaign no longer has text for, so
	 * the Publish screen can warn before they are dropped (BR-22).
	 *
	 * @param array<string, string>            $replacements A site's replacements.
	 * @param array<int, array<string, mixed>> $blocks       The version's parsed blocks.
	 * @return string[] Keys.
	 */
	public static function dropped( array $replacements, array $blocks ): array {
		$keys = self::keys( $blocks );
		return array_values( array_filter( array_keys( $replacements ), static fn ( string $key ): bool => ! in_array( $key, $keys, true ) ) );
	}

	/**
	 * The campaign's text a site hasn't replaced, so the Publish screen can say that
	 * those blocks show in the version's own language (BR-22).
	 *
	 * @param array<string, string>            $replacements A site's replacements.
	 * @param array<int, array<string, mixed>> $blocks       The version's parsed blocks.
	 * @return array<int, Item>
	 */
	public static function untranslated( array $replacements, array $blocks ): array {
		return array_values( array_filter( self::items( $blocks ), static fn ( array $item ): bool => ! isset( $replacements[ $item['key'] ] ) ) );
	}

	/**
	 * One block's own markup with the site's words inside its outer tag.
	 *
	 * @param array<string, mixed>  $block        Block.
	 * @param array<string, string> $replacements Replacements.
	 * @return array<string, mixed>
	 */
	private static function replace_markup( array $block, array $replacements ): array {
		$html = (string) ( $block['innerHTML'] ?? '' );
		$text = self::inner( $html );
		if ( null === $text || '' === self::plain( $text ) ) {
			return $block;
		}
		$new = $replacements[ self::key( $text ) ] ?? null;
		if ( null === $new ) {
			return $block;
		}
		$open  = (int) strpos( $html, '>' ) + 1;
		$close = (int) strrpos( $html, '<' );
		$html  = substr( $html, 0, $open ) . self::escape( $new ) . substr( $html, $close );

		$block['innerHTML'] = $html;
		if ( is_array( $block['innerContent'] ?? null ) ) {
			foreach ( $block['innerContent'] as $i => $chunk ) {
				if ( is_string( $chunk ) ) {
					$block['innerContent'][ $i ] = $html;
					break;
				}
			}
		}
		return $block;
	}

	/**
	 * What sits inside a block's outer tag, or null when its markup isn't that shape.
	 *
	 * @param string $html Block markup.
	 */
	private static function inner( string $html ): ?string {
		$open  = strpos( $html, '>' );
		$close = strrpos( $html, '<' );
		if ( false === $open || false === $close || $close <= $open ) {
			return null;
		}
		return substr( $html, $open + 1, $close - $open - 1 );
	}

	/**
	 * A replacement as markup: text only, with nothing that could open a tag.
	 *
	 * @param string $text Replacement.
	 */
	private static function escape( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Every piece of text in the blocks, in document order, with repeats.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return string[]
	 */
	private static function walk( array $blocks ): array {
		$found = array();
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			foreach ( self::ATTRIBUTE_BLOCKS[ $name ] ?? array() as $attribute ) {
				$text = $block['attrs'][ $attribute ] ?? null;
				if ( is_string( $text ) && '' !== self::plain( $text ) ) {
					$found[] = $text;
				}
			}
			if ( in_array( $name, self::MARKUP_BLOCKS, true ) ) {
				$text = self::inner( (string) ( $block['innerHTML'] ?? '' ) );
				if ( null !== $text && '' !== self::plain( $text ) ) {
					$found[] = $text;
				}
			}
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$found = array_merge( $found, self::walk( $block['innerBlocks'] ) );
			}
		}
		return $found;
	}
}
