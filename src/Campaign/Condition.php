<?php
/**
 * Normalises a campaign condition (BR-06, decision 0011): groups of rules combined
 * with all or any, optionally inverted. Shared with `js/runtime/rules/conditions.js`.
 *
 * WordPress-free so every rule shape is unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

/**
 * Condition shape and limits.
 */
final class Condition {

	public const MAX_GROUPS = 20;
	public const MAX_RULES  = 50;
	public const MAX_DEPTH  = 3;

	public const OPERATORS = array(
		'page'    => array( 'is', 'is_not' ),
		'path'    => array( 'is', 'is_not', 'starts_with', 'contains', 'not_contains', 'matches' ),
		'param'   => array( 'equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'exists', 'not_exists' ),
		'device'  => array( 'is', 'is_not' ),
		'country' => array( 'is', 'is_not' ),
	);

	private const PAGE_VALUE = '/^(front|posts_page|archive|search|404|singular(:[a-z0-9_-]{1,40})?|term:[a-z0-9_-]{1,40}:[a-z0-9_%-]{1,200}|child_of:(\d{1,20}|\/[a-z0-9\/_%.~-]{1,200}))$/';

	/**
	 * The empty condition: everyone, on every page.
	 *
	 * @return array{logic: string, invert: bool, groups: array<int, mixed>}
	 */
	public static function everyone(): array {
		return array(
			'logic'  => 'any',
			'invert' => false,
			'groups' => array(),
		);
	}

	/**
	 * Normalise a raw condition. Invalid rules are dropped; empty groups are removed.
	 *
	 * @param mixed $raw   Candidate.
	 * @param int   $depth Nesting depth (saved conditions copied into a version nest one level).
	 * @return array{logic: string, invert: bool, groups: array<int, array{logic: string, rules: array<int, array<string, mixed>>}>}
	 */
	public static function normalise( $raw, int $depth = 0 ): array {
		if ( ! is_array( $raw ) ) {
			return self::everyone();
		}

		$groups = array();
		foreach ( array_slice( (array) ( $raw['groups'] ?? array() ), 0, self::MAX_GROUPS ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$rules = array();
			foreach ( array_slice( (array) ( $group['rules'] ?? array() ), 0, self::MAX_RULES ) as $rule ) {
				$clean = is_array( $rule ) ? self::rule( $rule, $depth ) : null;
				if ( null !== $clean ) {
					$rules[] = $clean;
				}
			}
			if ( array() !== $rules ) {
				$groups[] = array(
					'logic' => self::logic( $group['logic'] ?? null ),
					'rules' => $rules,
				);
			}
		}

		return array(
			// Rule sets combine with OR unless a stored condition says otherwise (BR-06).
			'logic'  => self::logic( $raw['logic'] ?? null, 'any' ),
			'invert' => true === ( $raw['invert'] ?? false ),
			'groups' => $groups,
		);
	}

	/**
	 * Whether a condition has any rules.
	 *
	 * @param array{groups: array<int, mixed>} $condition Normalised condition.
	 */
	public static function has_rules( array $condition ): bool {
		return array() !== $condition['groups'];
	}

	/**
	 * Whether a condition uses a rule type anywhere, including nested saved conditions.
	 *
	 * @param array<string, mixed> $condition Normalised condition.
	 * @param string               $type      Rule type.
	 */
	public static function uses( array $condition, string $type ): bool {
		foreach ( $condition['groups'] ?? array() as $group ) {
			foreach ( $group['rules'] as $rule ) {
				if ( $type === $rule['type'] || ( 'condition' === $rule['type'] && self::uses( $rule['condition'], $type ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Normalise one rule, or null when it is invalid.
	 *
	 * @param array<string, mixed> $rule  Candidate.
	 * @param int                  $depth Nesting depth.
	 * @return array<string, mixed>|null
	 */
	private static function rule( array $rule, int $depth ): ?array {
		$type = is_string( $rule['type'] ?? null ) ? $rule['type'] : '';

		if ( 'condition' === $type ) {
			if ( $depth >= self::MAX_DEPTH || ! is_array( $rule['condition'] ?? null ) ) {
				return null;
			}
			$nested = self::normalise( $rule['condition'], $depth + 1 );
			return self::has_rules( $nested ) ? array(
				'type'      => 'condition',
				'id'        => is_string( $rule['id'] ?? null ) ? substr( $rule['id'], 0, 26 ) : '',
				'name'      => is_string( $rule['name'] ?? null ) ? mb_substr( trim( $rule['name'] ), 0, 100 ) : '',
				'condition' => $nested,
			) : null;
		}

		if ( ! isset( self::OPERATORS[ $type ] ) ) {
			return null;
		}
		$op = is_string( $rule['op'] ?? null ) && in_array( $rule['op'], self::OPERATORS[ $type ], true ) ? $rule['op'] : null;
		if ( null === $op ) {
			return null;
		}

		switch ( $type ) {
			case 'page':
				$values = self::list( $rule['value'] ?? null, static fn ( string $v ): bool => 1 === preg_match( self::PAGE_VALUE, $v ) );
				return array() === $values ? null : array(
					'type'  => 'page',
					'op'    => $op,
					'value' => $values,
				);

			case 'device':
				$values = array_values( array_intersect( CampaignSettings::DEVICES, (array) ( $rule['value'] ?? array() ) ) );
				return array() === $values ? null : array(
					'type'  => 'device',
					'op'    => $op,
					'value' => $values,
				);

			case 'country':
				$values = array_map( 'strtoupper', self::list( $rule['value'] ?? null, static fn ( string $v ): bool => 1 === preg_match( '/^[A-Za-z]{2}$/', $v ) ) );
				return array() === $values ? null : array(
					'type'  => 'country',
					'op'    => $op,
					'value' => array_values( array_unique( $values ) ),
				);

			case 'path':
				$value = is_string( $rule['value'] ?? null ) ? substr( trim( $rule['value'] ), 0, 255 ) : '';
				return '' === $value ? null : array(
					'type'  => 'path',
					'op'    => $op,
					'value' => $value,
				);

			default:
				// param.
				$name = is_string( $rule['name'] ?? null ) ? substr( trim( $rule['name'] ), 0, 100 ) : '';
				if ( '' === $name ) {
					return null;
				}
				return array(
					'type'  => 'param',
					'name'  => $name,
					'op'    => $op,
					'value' => is_scalar( $rule['value'] ?? null ) ? substr( (string) $rule['value'], 0, 255 ) : '',
				);
		}//end switch
	}

	/**
	 * A list of strings passing a test, from a string or an array.
	 *
	 * @param mixed    $value Candidate.
	 * @param callable $valid Test for one value.
	 * @return string[]
	 */
	private static function list( $value, callable $valid ): array {
		$out = array();
		foreach ( (array) $value as $item ) {
			if ( is_string( $item ) && $valid( trim( $item ) ) ) {
				$out[] = trim( $item );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * 'any' or 'all'; anything else becomes the default.
	 *
	 * @param mixed  $value    Candidate.
	 * @param string $fallback 'all' for the rules in a set, 'any' between sets.
	 */
	private static function logic( $value, string $fallback = 'all' ): string {
		return in_array( $value, array( 'any', 'all' ), true ) ? $value : $fallback;
	}
}
