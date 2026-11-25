<?php
/**
 * Can this campaign show on this URL at all? (BR-01, BR-06, decision 0032.)
 *
 * The runtime decides eligibility in the visitor's browser, so full-page caching can
 * never freeze one visitor's campaign for everyone. That is right for anything about
 * the visitor — their device, country, URL parameters, how often they have seen it.
 *
 * It is not needed for page and URL path rules. Those depend only on the URL, so every
 * visitor on that URL gets the same answer, and a cache can hold it safely. Knowing the
 * answer on the server is what lets an inline placement render nothing on a page the
 * campaign can never show on, instead of painting it and hiding it a moment later.
 *
 * **This class may only ever say no when it is certain.** Every rule it does not
 * understand, and everything that depends on the visitor, is `MAYBE`, and a `MAYBE`
 * anywhere that could change the answer makes the whole answer `MAYBE`. Being wrong in
 * the other direction would hide a campaign that should have shown, which is worse than
 * the flash this exists to prevent.
 *
 * WordPress-free, so the three-valued logic is unit-tested. The caller supplies the
 * page facts (`Frontend::page_facts()`), which the runtime already receives.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

/**
 * Three-valued evaluation of the rules a URL settles on its own.
 */
final class PageDecision {

	/** This URL passes the rules we can decide here. */
	public const YES = 'yes';

	/** This URL cannot pass, whoever the visitor is. */
	public const NO = 'no';

	/** It depends on something only the browser knows. */
	public const MAYBE = 'maybe';

	/**
	 * Whether a campaign is certain not to show on this page.
	 *
	 * @param array<string, mixed> $condition The version's condition.
	 * @param array<string, mixed> $facts     Page facts (`Frontend::page_facts()`).
	 * @param string               $path      The page's path.
	 */
	public static function never_here( $condition, array $facts, string $path ): bool {
		return self::NO === self::condition( is_array( $condition ) ? $condition : array(), $facts, $path );
	}

	/**
	 * Evaluate a whole condition (BR-06). Rule sets combine with OR unless the
	 * condition says "all"; `invert` flips a decided answer and leaves MAYBE alone.
	 *
	 * @param array<string, mixed> $condition Condition.
	 * @param array<string, mixed> $facts     Page facts.
	 * @param string               $path      Page path.
	 */
	public static function condition( array $condition, array $facts, string $path ): string {
		$groups = array_values(
			array_filter(
				(array) ( $condition['groups'] ?? array() ),
				static fn ( $group ): bool => is_array( $group ) && array() !== (array) ( $group['rules'] ?? array() )
			)
		);
		if ( array() === $groups ) {
			// No rules: everyone, on every page.
			return self::YES;
		}

		$results = array_map( fn ( array $group ): string => self::group( $group, $facts, $path ), $groups );
		$met     = 'all' === ( $condition['logic'] ?? '' )
			? self::every( $results )
			: self::some( $results );

		return true === ( $condition['invert'] ?? false ) ? self::flip( $met ) : $met;
	}

	/**
	 * One group of rules: "all" unless it says "any".
	 *
	 * @param array<string, mixed> $group Group.
	 * @param array<string, mixed> $facts Page facts.
	 * @param string               $path  Page path.
	 */
	private static function group( array $group, array $facts, string $path ): string {
		$results = array_map(
			fn ( $rule ): string => self::rule( is_array( $rule ) ? $rule : array(), $facts, $path ),
			(array) ( $group['rules'] ?? array() )
		);
		if ( array() === $results ) {
			return self::YES;
		}
		return 'any' === ( $group['logic'] ?? '' ) ? self::some( $results ) : self::every( $results );
	}

	/**
	 * One rule. Anything about the visitor, and any type this doesn't know, is MAYBE.
	 *
	 * @param array<string, mixed> $rule  Rule.
	 * @param array<string, mixed> $facts Page facts.
	 * @param string               $path  Page path.
	 */
	private static function rule( array $rule, array $facts, string $path ): string {
		$values = is_array( $rule['value'] ?? null ) ? $rule['value'] : array( $rule['value'] ?? '' );
		$op     = (string) ( $rule['op'] ?? '' );

		switch ( (string) ( $rule['type'] ?? '' ) ) {
			case 'page':
				$hit = false;
				foreach ( $values as $value ) {
					$hit = $hit || self::page_is( (string) $value, $facts );
				}
				return self::of( 'is_not' === $op ? ! $hit : $hit );
			case 'path':
				return self::of( self::path_matches( $op, (string) ( $rule['value'] ?? '' ), $path ) );
			case 'condition':
				// Saved conditions arrive copied into the version (BR-07).
				return self::condition( (array) ( $rule['condition'] ?? array() ), $facts, $path );
			default:
				// device, country, param, and anything newer than this class.
				return self::MAYBE;
		}
	}

	/**
	 * A page rule value against the page facts (BR-01), as the runtime reads them.
	 *
	 * @param string               $value Value such as `front`, `singular:post`, `term:category:news`, `child_of:/services`.
	 * @param array<string, mixed> $facts Page facts.
	 */
	public static function page_is( string $value, array $facts ): bool {
		$parts = explode( ':', $value );
		$kind  = array_shift( $parts );
		$arg   = implode( ':', $parts );
		$list  = static fn ( string $key ): array => array_map( 'strval', (array) ( $facts[ $key ] ?? array() ) );

		switch ( $kind ) {
			case 'front':
				return ! empty( $facts['front'] );
			case 'posts_page':
				return ! empty( $facts['postsPage'] );
			case 'archive':
				return ! empty( $facts['archive'] );
			case 'search':
				return ! empty( $facts['search'] );
			case '404':
				return ! empty( $facts['notFound'] );
			case 'singular':
				return ! empty( $facts['singular'] ) && ( '' === $arg || (string) ( $facts['postType'] ?? '' ) === $arg );
			case 'term':
				return in_array( $arg, $list( 'terms' ), true );
			case 'child_of':
				// By path (decision 0021), or by ID for rules made before.
				return str_starts_with( $arg, '/' )
					? in_array( $arg, $list( 'ancestorPaths' ), true )
					: in_array( $arg, $list( 'ancestors' ), true );
			default:
				return false;
		}//end switch
	}

	/**
	 * A URL path rule (BR-01): trailing slash and case ignored, `*` matches anything.
	 *
	 * @param string $op    Operator.
	 * @param string $value Path or pattern.
	 * @param string $path  Page path.
	 */
	public static function path_matches( string $op, string $value, string $path ): bool {
		$actual   = self::normalise_path( $path );
		$expected = self::normalise_path( $value );
		$raw      = strtolower( $value );

		switch ( $op ) {
			case 'is':
				return $actual === $expected;
			case 'is_not':
				return $actual !== $expected;
			case 'starts_with':
				return str_starts_with( $actual, $expected );
			case 'contains':
				return str_contains( $actual, $raw );
			case 'not_contains':
				return ! str_contains( $actual, $raw );
			case 'matches':
				return self::matches_pattern( $value, $path );
			default:
				return false;
		}
	}

	/**
	 * Lowercase, a leading slash, no trailing slash except on "/".
	 *
	 * @param string $path Path or pattern.
	 */
	public static function normalise_path( string $path ): string {
		$value = strtolower( trim( $path ) );
		if ( ! str_starts_with( $value, '/' ) ) {
			$value = '/' . $value;
		}
		if ( strlen( $value ) > 1 ) {
			$value = rtrim( $value, '/' );
			$value = '' === $value ? '/' : $value;
		}
		return $value;
	}

	/**
	 * Whether a path matches one pattern, where `*` matches any characters.
	 *
	 * @param string $pattern Pattern such as `/blog/*`.
	 * @param string $path    Page path.
	 */
	public static function matches_pattern( string $pattern, string $path ): bool {
		$source = implode(
			'.*',
			array_map(
				static fn ( string $part ): string => preg_quote( $part, '#' ),
				explode( '*', self::normalise_path( $pattern ) )
			)
		);
		return 1 === preg_match( '#^' . $source . '$#', self::normalise_path( $path ) );
	}

	/**
	 * AND: one NO settles it; every YES is a YES; anything else is MAYBE.
	 *
	 * @param string[] $results Results.
	 */
	private static function every( array $results ): string {
		if ( in_array( self::NO, $results, true ) ) {
			return self::NO;
		}
		return in_array( self::MAYBE, $results, true ) ? self::MAYBE : self::YES;
	}

	/**
	 * OR: one YES settles it; all NO is a NO; anything else is MAYBE.
	 *
	 * @param string[] $results Results.
	 */
	private static function some( array $results ): string {
		if ( in_array( self::YES, $results, true ) ) {
			return self::YES;
		}
		return in_array( self::MAYBE, $results, true ) ? self::MAYBE : self::NO;
	}

	/**
	 * Invert a decided answer; MAYBE stays MAYBE.
	 *
	 * @param string $result Result.
	 */
	private static function flip( string $result ): string {
		if ( self::MAYBE === $result ) {
			return self::MAYBE;
		}
		return self::YES === $result ? self::NO : self::YES;
	}

	/**
	 * A decided boolean as a result.
	 *
	 * @param bool $pass Whether it passed.
	 */
	private static function of( bool $pass ): string {
		return $pass ? self::YES : self::NO;
	}
}
