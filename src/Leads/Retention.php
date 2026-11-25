<?php
/**
 * Lead retention (BR-50): how long leads are kept.
 *
 * WordPress-free, so the allowed periods and the cutoff are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Leads;

/**
 * Retention periods and cutoffs.
 */
final class Retention {

	/**
	 * Days a lead may be kept; 0 means never delete automatically. **[confirm range]**
	 */
	public const PERIODS = array( 30, 90, 180, 365, 0 );

	public const DEFAULT_DAYS = 90;

	/**
	 * A stored or submitted value as an allowed period.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function days( $value ): int {
		$days = is_numeric( $value ) ? (int) $value : self::DEFAULT_DAYS;
		return in_array( $days, self::PERIODS, true ) ? $days : self::DEFAULT_DAYS;
	}

	/**
	 * Leads received before this UTC "Y-m-d H:i:s" are past retention; null when
	 * leads are kept until someone deletes them.
	 *
	 * @param int $days Retention period.
	 * @param int $now  Current Unix time.
	 */
	public static function cutoff( int $days, int $now ): ?string {
		$days = self::days( $days );
		return 0 === $days ? null : gmdate( 'Y-m-d H:i:s', $now - $days * 86400 );
	}
}
