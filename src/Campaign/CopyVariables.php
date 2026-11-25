<?php
/**
 * Copy variables (FR-26, BR-21, decision 0028): named placeholders in campaign text,
 * written `{{city}}`, with a default on the campaign and a value per site.
 *
 * WordPress-free, so the rules are unit-tested. Values reach `render()` already
 * escaped: substitution happens after the content has been through `wp_kses`, so a
 * value must never be able to introduce markup.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

/**
 * Defines, finds and substitutes copy variables.
 *
 * @phpstan-type Definition array{name: string, default: string}
 */
final class CopyVariables {

	/**
	 * Most variables one campaign can define.
	 */
	public const MAX = 20;

	/**
	 * Longest a value can be.
	 */
	public const MAX_VALUE = 200;

	/**
	 * A name: a letter, then letters, digits or underscores.
	 */
	public const NAME = '/^[a-z][a-z0-9_]{0,31}$/';

	/**
	 * A token in text. Whitespace inside the braces is allowed, so `{{ city }}` works.
	 */
	private const TOKEN = '/\{\{\s*([a-z][a-z0-9_]{0,31})\s*\}\}/';

	/**
	 * Clean stored or submitted definitions: named, unique, in the order given.
	 *
	 * @param mixed $raw Candidate.
	 * @return array<int, Definition>
	 */
	public static function definitions( $raw ): array {
		$out  = array();
		$seen = array();
		foreach ( is_array( $raw ) ? $raw : array() as $item ) {
			$name = is_array( $item ) ? self::name( $item['name'] ?? '' ) : '';
			if ( '' === $name || isset( $seen[ $name ] ) || count( $out ) >= self::MAX ) {
				continue;
			}
			$seen[ $name ] = true;
			$out[]         = array(
				'name'    => $name,
				'default' => self::value( $item['default'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * A valid name, or ''.
	 *
	 * @param mixed $raw Candidate.
	 */
	public static function name( $raw ): string {
		$name = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
		return 1 === preg_match( self::NAME, $name ) ? $name : '';
	}

	/**
	 * A value: one line, trimmed, not too long.
	 *
	 * @param mixed $raw Candidate.
	 */
	public static function value( $raw ): string {
		if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
			return '';
		}
		$value = trim( preg_replace( '/\s+/u', ' ', (string) $raw ) ?? '' );
		return '' === $value ? '' : mb_substr( $value, 0, self::MAX_VALUE );
	}

	/**
	 * Clean a site's values: name => value, only for variables the campaign defines,
	 * and only where the site actually says something. An empty value means "use the
	 * campaign's default", so it is dropped (BR-21, BR-22).
	 *
	 * @param mixed                  $raw         Candidate.
	 * @param array<int, Definition> $definitions The campaign's definitions.
	 * @return array<string, string>
	 */
	public static function site_values( $raw, array $definitions ): array {
		$known = self::names( $definitions );
		$out   = array();
		foreach ( is_array( $raw ) ? $raw : array() as $name => $value ) {
			$name = self::name( $name );
			if ( '' === $name || ! in_array( $name, $known, true ) ) {
				continue;
			}
			$value = self::value( $value );
			if ( '' !== $value ) {
				$out[ $name ] = $value;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The names a campaign defines, in order.
	 *
	 * @param array<int, Definition> $definitions Definitions.
	 * @return string[]
	 */
	public static function names( array $definitions ): array {
		return array_values(
			array_filter(
				array_map( static fn ( $d ): string => is_array( $d ) ? self::name( $d['name'] ?? '' ) : '', $definitions ),
				static fn ( string $name ): bool => '' !== $name
			)
		);
	}

	/**
	 * The definitions with a site's values written over their defaults, so the package
	 * on the wire has one shape (decision 0028).
	 *
	 * @param array<int, Definition> $definitions Definitions.
	 * @param array<string, string>  $values      Site values, name => value.
	 * @return array<int, Definition>
	 */
	public static function with_values( array $definitions, array $values ): array {
		$out = array();
		foreach ( self::definitions( $definitions ) as $definition ) {
			if ( isset( $values[ $definition['name'] ] ) && '' !== $values[ $definition['name'] ] ) {
				$definition['default'] = self::value( $values[ $definition['name'] ] );
			}
			$out[] = $definition;
		}
		return $out;
	}

	/**
	 * What each variable says here: name => value.
	 *
	 * @param array<int, Definition> $definitions Definitions.
	 * @return array<string, string>
	 */
	public static function resolve( array $definitions ): array {
		$out = array();
		foreach ( self::definitions( $definitions ) as $definition ) {
			$out[ $definition['name'] ] = $definition['default'];
		}
		return $out;
	}

	/**
	 * Every token in some text, in the order first seen, whether or not it is defined.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function tokens( string $text ): array {
		preg_match_all( self::TOKEN, $text, $matches );
		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * Tokens in the text that the campaign doesn't define (FR-32 warning).
	 *
	 * @param string                 $text        Text.
	 * @param array<int, Definition> $definitions Definitions.
	 * @return string[]
	 */
	public static function undefined( string $text, array $definitions ): array {
		$known = self::names( $definitions );
		return array_values( array_filter( self::tokens( $text ), static fn ( string $name ): bool => ! in_array( $name, $known, true ) ) );
	}

	/**
	 * Substitute the variables in some text. Tokens with no value are left as typed:
	 * `{{` is ordinary text in a campaign about templates (decision 0028).
	 *
	 * @param string                $text   Text.
	 * @param array<string, string> $values name => value, already escaped for the text.
	 */
	public static function render( string $text, array $values ): string {
		if ( array() === $values || ! str_contains( $text, '{{' ) ) {
			return $text;
		}
		return (string) preg_replace_callback(
			self::TOKEN,
			static fn ( array $m ): string => array_key_exists( $m[1], $values ) ? $values[ $m[1] ] : $m[0],
			$text
		);
	}
}
