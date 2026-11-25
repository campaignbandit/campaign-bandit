<?php
/**
 * Conversion rate (BR-15): conversions ÷ views for the same campaign, site,
 * version and date range.
 *
 * WordPress-free, so the rule is unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Metrics;

/**
 * Conversion rate as a percentage.
 */
final class ConversionRate {

	/**
	 * The rate in percent, to one decimal place; null when there were no views.
	 *
	 * @param int $conversions Conversions.
	 * @param int $views       Views.
	 */
	public static function percent( int $conversions, int $views ): ?float {
		if ( $views <= 0 ) {
			return null;
		}
		return round( 100 * max( 0, $conversions ) / $views, 1 );
	}
}
