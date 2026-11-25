<?php
/**
 * The daily line chart on Campaign detail (S12): the axis top and which days get a
 * label. The page script (assets/campaign-detail.js) draws it at the chart's real
 * width. WordPress-free, so it is unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Metrics;

/**
 * Axis and labels for the chart.
 */
final class LineChart {

	/**
	 * A round top for the y axis, so ticks land on readable numbers.
	 *
	 * @param float $max Largest value.
	 * @return float
	 */
	public static function nice_max( float $max ): float {
		if ( $max <= 0 ) {
			return 4;
		}
		$step = 10 ** floor( log10( $max ) );
		foreach ( array( 1, 2, 2.5, 5, 10 ) as $m ) {
			if ( $max <= $m * $step ) {
				return $m * $step;
			}
		}
		return 10 * $step;
	}

	/**
	 * Which day labels to show along the bottom: all of a week, about six of a month.
	 *
	 * @param int $count Days.
	 * @return int[] Indexes.
	 */
	public static function label_days( int $count ): array {
		if ( $count <= 8 ) {
			return range( 0, max( 0, $count - 1 ) );
		}
		$every = (int) ceil( $count / 6 );
		$out   = range( 0, $count - 1, $every );
		if ( end( $out ) !== $count - 1 ) {
			$out[] = $count - 1;
		}
		return $out;
	}
}
