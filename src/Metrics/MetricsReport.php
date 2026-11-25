<?php
/**
 * Campaign metrics a connected site reports to its hub (FR-61, BR-15, decision 0020).
 *
 * The site sends its daily totals for hub campaigns with each sync, from the day before
 * the last day the hub confirmed. Totals, not increments: the hub stores what it is
 * sent, so a report that arrives twice changes nothing. Only totals leave the site: no
 * per-visitor rows (BR-14).
 *
 * WordPress-free, so the rules are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Metrics;

/**
 * Report rules, on the site and on the hub.
 */
final class MetricsReport {

	/** Rows in one report. More wait for the next sync. */
	public const MAX_ROWS = 2000;

	/** Days a site reports the first time (today included). */
	public const FIRST_DAYS = 35;

	/** The oldest day the hub accepts, in days before today. */
	public const MAX_AGE_DAYS = 400;

	/** A count no day can reach; anything above is refused. */
	private const MAX_COUNT = 100000000;

	/**
	 * The first day to report: the day before the last confirmed day, so counts added
	 * late in that day still arrive; the last FIRST_DAYS days the first time.
	 *
	 * @param string|null $through Last day the hub confirmed, Y-m-d, or null.
	 * @param string      $today   Today on the site, Y-m-d.
	 */
	public static function from_day( ?string $through, string $today ): string {
		$today_at = self::at( $today ) ?? 0;
		$through  = null === $through ? null : self::at( $through );
		if ( null === $through ) {
			return gmdate( 'Y-m-d', $today_at - ( self::FIRST_DAYS - 1 ) * 86400 );
		}
		return gmdate( 'Y-m-d', min( $through, $today_at ) - 86400 );
	}

	/**
	 * Keep whole days within the limit. Rows must be sorted by day.
	 *
	 * @param array<int, array<string, mixed>> $rows  Rows, oldest day first; one more than the limit says there are more.
	 * @param int                              $limit Most rows to send.
	 * @return array{rows: array<int, array<string, mixed>>, through: string|null} Rows to send, and the last day sent in full when the rest waits (null: everything was sent).
	 */
	public static function take( array $rows, int $limit = self::MAX_ROWS ): array {
		if ( count( $rows ) <= $limit ) {
			return array(
				'rows'    => array_values( $rows ),
				'through' => null,
			);
		}
		$cut  = (string) $rows[ $limit ]['day'];
		$kept = array_values( array_filter( array_slice( $rows, 0, $limit ), static fn ( array $r ): bool => (string) $r['day'] < $cut ) );
		if ( array() === $kept ) {
			// One day holds more than the limit: send what fits of it, then move on.
			return array(
				'rows'    => array_slice( $rows, 0, $limit ),
				'through' => $cut,
			);
		}
		return array(
			'rows'    => $kept,
			'through' => (string) end( $kept )['day'],
		);
	}

	/**
	 * Rows from a site's report that the hub can store: for campaigns deployed to that
	 * site, a real day not in the future (a day's grace for time zones) nor older than
	 * MAX_AGE_DAYS, a known device, a page group, and whole counts. A row sent twice
	 * keeps the last.
	 *
	 * @param mixed               $raw       What the site sent.
	 * @param array<string, bool> $campaigns Campaign IDs deployed to the site, as keys.
	 * @param string              $today     Today on the hub, Y-m-d.
	 * @return array<int, array{campaign: string, version: int, day: string, device: string, page_group: string, views: int, conversions: int}>
	 */
	public static function clean( $raw, array $campaigns, string $today ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$today_at = self::at( $today ) ?? 0;
		$latest   = $today_at + 86400;
		$oldest   = $today_at - self::MAX_AGE_DAYS * 86400;
		$out      = array();
		foreach ( array_slice( $raw, 0, self::MAX_ROWS ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$campaign = $row['campaign'] ?? null;
			$day      = is_string( $row['day'] ?? null ) ? self::at( $row['day'] ) : null;
			$version  = self::count( $row['version'] ?? null );
			$views    = self::count( $row['views'] ?? null );
			$convs    = self::count( $row['conversions'] ?? null );
			$device   = $row['device'] ?? '';
			if ( ! is_string( $campaign ) || ! isset( $campaigns[ $campaign ] ) || null === $day || $day > $latest || $day < $oldest
				|| null === $version || $version < 1 || null === $views || null === $convs
				|| ! is_string( $device ) || ( '' !== $device && ! in_array( $device, MetricsService::DEVICES, true ) ) ) {
				continue;
			}
			$clean       = array(
				'campaign'    => $campaign,
				'version'     => $version,
				'day'         => gmdate( 'Y-m-d', $day ),
				'device'      => $device,
				'page_group'  => MetricsService::page_group( $row['page_group'] ?? '/' ),
				'views'       => $views,
				'conversions' => $convs,
			);
			$key         = implode( '|', array( $campaign, $version, $clean['day'], $device, $clean['page_group'] ) );
			$out[ $key ] = $clean;
		}//end foreach
		return array_values( $out );
	}

	/**
	 * Midday UTC of a Y-m-d day, or null when it isn't a real day.
	 *
	 * @param string $day Day.
	 */
	private static function at( string $day ): ?int {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return (int) gmmktime( 12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * A whole, non-negative count, or null.
	 *
	 * @param mixed $value Candidate.
	 */
	private static function count( $value ): ?int {
		if ( is_int( $value ) ) {
			$number = $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/^\d{1,9}$/', $value ) ) {
			$number = (int) $value;
		} else {
			return null;
		}
		return $number >= 0 && $number <= self::MAX_COUNT ? $number : null;
	}
}
