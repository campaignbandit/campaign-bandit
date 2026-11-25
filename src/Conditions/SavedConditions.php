<?php
/**
 * Saved conditions inside a campaign's condition (BR-07, decision 0024): a rule of type
 * "condition" names a saved condition by ID and carries a copy of its rules. The copy
 * is what runs. It is refreshed from the library when a version is created, so editing
 * a saved condition never changes live behaviour by itself (BR-20).
 *
 * WordPress-free, so the rules are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Conditions;

use CampaignBandit\Campaign\Condition;

/**
 * Saved condition references.
 */
final class SavedConditions {

	/**
	 * IDs of the saved conditions a condition uses, once each.
	 *
	 * @param array<string, mixed> $condition Condition.
	 * @return string[]
	 */
	public static function ids( array $condition ): array {
		$ids = array();
		foreach ( (array) ( $condition['groups'] ?? array() ) as $group ) {
			foreach ( (array) ( $group['rules'] ?? array() ) as $rule ) {
				if ( 'condition' === ( $rule['type'] ?? '' ) ) {
					if ( '' !== (string) ( $rule['id'] ?? '' ) ) {
						$ids[] = (string) $rule['id'];
					}
					$ids = array_merge( $ids, self::ids( (array) ( $rule['condition'] ?? array() ) ) );
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Whether a condition uses a saved condition.
	 *
	 * @param array<string, mixed> $condition Condition.
	 * @param string               $id        Saved condition ID.
	 */
	public static function uses( array $condition, string $id ): bool {
		return in_array( $id, self::ids( $condition ), true );
	}

	/**
	 * The condition with every saved condition's copy replaced by the library's current
	 * rules and name. A saved condition no longer in the library keeps its copy.
	 *
	 * @param array<string, mixed>                                                $condition Condition.
	 * @param array<string, array{name: string, condition: array<string, mixed>}> $library   Saved conditions by ID.
	 * @return array<string, mixed>
	 */
	public static function refresh( array $condition, array $library ): array {
		foreach ( (array) ( $condition['groups'] ?? array() ) as $g => $group ) {
			foreach ( (array) ( $group['rules'] ?? array() ) as $r => $rule ) {
				if ( 'condition' !== ( $rule['type'] ?? '' ) ) {
					continue;
				}
				$id = (string) ( $rule['id'] ?? '' );
				if ( isset( $library[ $id ] ) ) {
					$rule['name']      = $library[ $id ]['name'];
					$rule['condition'] = $library[ $id ]['condition'];
				}
				$condition['groups'][ $g ]['rules'][ $r ] = $rule;
			}
		}
		return $condition;
	}

	/**
	 * The condition as one site runs it: a saved condition with its own definition for
	 * that site uses it in place of the copy (requirements/08 P4). Other rules, and
	 * saved conditions without one, are left alone.
	 *
	 * @param array<string, mixed>                $condition   Condition.
	 * @param array<string, array<string, mixed>> $definitions The site's definitions, by saved condition ID.
	 * @return array<string, mixed>
	 */
	public static function for_site( array $condition, array $definitions ): array {
		if ( array() === $definitions ) {
			return $condition;
		}
		foreach ( (array) ( $condition['groups'] ?? array() ) as $g => $group ) {
			foreach ( (array) ( $group['rules'] ?? array() ) as $r => $rule ) {
				if ( 'condition' !== ( $rule['type'] ?? '' ) ) {
					continue;
				}
				$id = (string) ( $rule['id'] ?? '' );
				if ( isset( $definitions[ $id ] ) ) {
					$rule['condition'] = $definitions[ $id ];
				} else {
					// Site page rules (BR-21) wrap the campaign's rules one level down.
					$rule['condition'] = self::for_site( (array) ( $rule['condition'] ?? array() ), $definitions );
				}
				$condition['groups'][ $g ]['rules'][ $r ] = $rule;
			}
		}
		return $condition;
	}

	/**
	 * Whether a condition's copy of a saved condition differs from the library's, so
	 * live campaigns run older rules until they are republished (S11).
	 *
	 * @param array<string, mixed> $condition Condition (a live version's).
	 * @param string               $id        Saved condition ID.
	 * @param array<string, mixed> $current   The library's rules for it.
	 */
	public static function outdated( array $condition, string $id, array $current ): bool {
		foreach ( (array) ( $condition['groups'] ?? array() ) as $group ) {
			foreach ( (array) ( $group['rules'] ?? array() ) as $rule ) {
				if ( 'condition' === ( $rule['type'] ?? '' ) && (string) ( $rule['id'] ?? '' ) === $id ) {
					return Condition::normalise( $rule['condition'] ?? null, 1 ) !== Condition::normalise( $current, 1 );
				}
			}
		}
		return false;
	}

	/**
	 * A saved condition's own rules: no saved condition inside another, so nothing can
	 * refer to itself.
	 *
	 * @param mixed $raw Candidate.
	 * @return array<string, mixed>
	 */
	public static function definition( $raw ): array {
		$condition = Condition::normalise( $raw, 1 );
		foreach ( $condition['groups'] as $g => $group ) {
			$condition['groups'][ $g ]['rules'] = array_values( array_filter( $group['rules'], static fn ( array $rule ): bool => 'condition' !== $rule['type'] ) );
			if ( array() === $condition['groups'][ $g ]['rules'] ) {
				unset( $condition['groups'][ $g ] );
			}
		}
		$condition['groups'] = array_values( $condition['groups'] );
		return $condition;
	}
}
