<?php
/**
 * Campaign figures for Campaign detail (S12, BR-15): views, conversions, conversion
 * rate and leads per day, their totals, and the change on the previous period.
 * WordPress-free, so the arithmetic is unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Metrics;

/**
 * Daily series and totals.
 */
final class Figures {

	public const MEASURES = array( 'views', 'conversions', 'rate', 'leads' );

	/**
	 * One row per day from the first day, filling days with nothing in them.
	 *
	 * @param array<string, array{views?: int, conversions?: int, leads?: int}> $rows      Counts by day (Y-m-d).
	 * @param string                                                            $first_day First day, Y-m-d.
	 * @param int                                                               $days      Number of days.
	 * @return array<int, array{day: string, views: int, conversions: int, leads: int, rate: ?float}>
	 */
	public static function series( array $rows, string $first_day, int $days ): array {
		$out  = array();
		$time = strtotime( $first_day . ' 12:00:00 UTC' );
		for ( $i = 0; $i < $days; $i++ ) {
			$day         = gmdate( 'Y-m-d', $time + $i * 86400 );
			$row         = $rows[ $day ] ?? array();
			$views       = (int) ( $row['views'] ?? 0 );
			$conversions = (int) ( $row['conversions'] ?? 0 );
			$out[]       = array(
				'day'         => $day,
				'views'       => $views,
				'conversions' => $conversions,
				'leads'       => (int) ( $row['leads'] ?? 0 ),
				'rate'        => self::rate( $views, $conversions ),
			);
		}
		return $out;
	}

	/**
	 * Totals for a series. The rate is over the whole period, not an average of days.
	 *
	 * @param array<int, array{views: int, conversions: int, leads: int}> $series Series.
	 * @return array{views: int, conversions: int, leads: int, rate: ?float}
	 */
	public static function totals( array $series ): array {
		$views       = (int) array_sum( array_column( $series, 'views' ) );
		$conversions = (int) array_sum( array_column( $series, 'conversions' ) );
		return array(
			'views'       => $views,
			'conversions' => $conversions,
			'leads'       => (int) array_sum( array_column( $series, 'leads' ) ),
			'rate'        => self::rate( $views, $conversions ),
		);
	}

	/**
	 * Change on the previous period: percent for counts, percentage points for the
	 * rate; null when there is nothing to compare with.
	 *
	 * @param array{views: int, conversions: int, leads: int, rate: ?float} $now    This period.
	 * @param array{views: int, conversions: int, leads: int, rate: ?float} $before Previous period.
	 * @return array{views: ?int, conversions: ?int, leads: ?int, rate: ?float}
	 */
	public static function change( array $now, array $before ): array {
		$percent = static fn ( int $a, int $b ): ?int => 0 === $b ? null : (int) round( ( $a - $b ) / $b * 100 );
		return array(
			'views'       => $percent( $now['views'], $before['views'] ),
			'conversions' => $percent( $now['conversions'], $before['conversions'] ),
			'leads'       => $percent( $now['leads'], $before['leads'] ),
			'rate'        => null === $now['rate'] || null === $before['rate'] ? null : round( ( $now['rate'] - $before['rate'] ) * 100, 1 ),
		);
	}

	/**
	 * Conversion rate (BR-15): conversions ÷ views, or null with no views.
	 *
	 * @param int $views       Views.
	 * @param int $conversions Conversions.
	 */
	public static function rate( int $views, int $conversions ): ?float {
		return 0 === $views ? null : $conversions / $views;
	}

	/**
	 * Whether daily rates swing too much to read: fewer than 5 conversions a day on average.
	 *
	 * @param array{conversions: int} $totals Totals.
	 * @param int                     $days   Days in the period.
	 */
	public static function rate_is_noisy( array $totals, int $days ): bool {
		return $days > 0 && $totals['conversions'] / $days < 5;
	}
}
