<?php
/**
 * The dates a Date field allows (FR-23): any date, not in the past, within the next N
 * days, or between two dates. The browser checks it for the visitor; the server checks
 * it again against the site's own date.
 *
 * WordPress-free, so the rules are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Forms;

/**
 * Date field rules.
 */
final class DateRange {

	public const TYPES    = array( 'any', 'not_past', 'within', 'between' );
	public const MAX_DAYS = 3650;

	/**
	 * A range from block attributes.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return array{type: string, days: int, from: string, to: string}
	 */
	public static function from_attributes( array $attrs ): array {
		return array(
			'type' => in_array( $attrs['range'] ?? null, self::TYPES, true ) ? $attrs['range'] : 'any',
			'days' => max( 1, min( self::MAX_DAYS, (int) ( $attrs['withinDays'] ?? 365 ) ) ),
			'from' => self::valid( $attrs['from'] ?? '' ) ? $attrs['from'] : '',
			'to'   => self::valid( $attrs['to'] ?? '' ) ? $attrs['to'] : '',
		);
	}

	/**
	 * Whether a value is a real date written `YYYY-MM-DD`.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function valid( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * The earliest and latest dates allowed, as `YYYY-MM-DD`, or null for no limit.
	 *
	 * @param array{type: string, days: int, from: string, to: string} $range Range.
	 * @param string                                                   $today Today, `YYYY-MM-DD`, in the site's timezone.
	 * @return array{min: ?string, max: ?string}
	 */
	public static function limits( array $range, string $today ): array {
		switch ( $range['type'] ) {
			case 'not_past':
				return array(
					'min' => $today,
					'max' => null,
				);
			case 'within':
				return array(
					'min' => $today,
					'max' => gmdate( 'Y-m-d', (int) strtotime( $today . ' 12:00:00 UTC' ) + $range['days'] * 86400 ),
				);
			case 'between':
				return array(
					'min' => '' !== $range['from'] ? $range['from'] : null,
					'max' => '' !== $range['to'] ? $range['to'] : null,
				);
		}
		return array(
			'min' => null,
			'max' => null,
		);
	}

	/**
	 * What is wrong with a submitted date: null, `date` (not a date) or `date_range`
	 * (outside the dates allowed).
	 *
	 * @param string                                                   $value Submitted value.
	 * @param array{type: string, days: int, from: string, to: string} $range Range.
	 * @param string                                                   $today Today, `YYYY-MM-DD`.
	 */
	public static function error( string $value, array $range, string $today ): ?string {
		if ( ! self::valid( $value ) ) {
			return 'date';
		}
		$limits = self::limits( $range, $today );
		// Dates written YYYY-MM-DD compare correctly as strings.
		if ( ( null !== $limits['min'] && $value < $limits['min'] ) || ( null !== $limits['max'] && $value > $limits['max'] ) ) {
			return 'date_range';
		}
		return null;
	}
}
