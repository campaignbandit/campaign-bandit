<?php
/**
 * Views and conversions summed by site, version or campaign (FR-61, BR-15), for the
 * Sites and Versions tabs (S12), the Overview (S1) and Site detail (S13).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Metrics;

use CampaignBandit\Database\Schema;
use CampaignBandit\Leads\LeadRepository;

/**
 * Metric totals.
 */
final class MetricTotals {

	/**
	 * One campaign's totals per site for days in a range.
	 *
	 * @param string $uid   Campaign ULID.
	 * @param string $first First day, Y-m-d.
	 * @param string $last  Last day, Y-m-d.
	 * @return array<int, array{views: int, conversions: int}> By site ID (0: this install).
	 */
	public static function by_site( string $uid, string $first, string $last ): array {
		global $wpdb;
		return self::keyed(
			$wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT site_id AS k, SUM(views) AS views, SUM(conversions) AS conversions FROM %i WHERE campaign_uid = %s AND day BETWEEN %s AND %s GROUP BY site_id',
					$wpdb->prefix . Schema::METRICS,
					$uid,
					$first,
					$last
				),
				ARRAY_A
			)
		);
	}

	/**
	 * One campaign's leads per site for days in a range, test leads left out (BR-15).
	 *
	 * @param string $uid   Campaign ULID.
	 * @param string $first First day, Y-m-d in site time.
	 * @param string $last  Last day, Y-m-d in site time.
	 * @return array<int, int> By site ID.
	 */
	public static function leads_by_site( string $uid, string $first, string $last ): array {
		global $wpdb;
		$zone = wp_timezone();
		$utc  = new \DateTimeZone( 'UTC' );
		$from = ( new \DateTimeImmutable( $first . ' 00:00:00', $zone ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$to   = ( new \DateTimeImmutable( $last . ' 23:59:59', $zone ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT site_id, COUNT(*) AS leads FROM %i WHERE campaign_uid = %s AND is_test = 0 AND submitted_at BETWEEN %s AND %s GROUP BY site_id',
				LeadRepository::table(),
				$uid,
				$from,
				$to
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['site_id'] ] = (int) $row['leads'];
		}
		return $out;
	}

	/**
	 * One campaign's totals per version since each went live, across every site that
	 * ran it (S12 Versions). This install only when $all_sites is false.
	 *
	 * @param string $uid       Campaign ULID.
	 * @param bool   $all_sites Every site (hub) or this install.
	 * @return array<int, array{views: int, conversions: int}> By version number.
	 */
	public static function by_version( string $uid, bool $all_sites ): array {
		global $wpdb;
		return self::keyed(
			$wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT version_number AS k, SUM(views) AS views, SUM(conversions) AS conversions FROM %i WHERE campaign_uid = %s AND ( site_id = 0 OR %d = 1 ) GROUP BY version_number',
					$wpdb->prefix . Schema::METRICS,
					$uid,
					$all_sites ? 1 : 0
				),
				ARRAY_A
			)
		);
	}

	/**
	 * Totals per campaign on some sites from a day on (S1 live campaigns).
	 *
	 * @param int[]  $site_ids Sites (0: this install).
	 * @param string $first    First day, Y-m-d.
	 * @return array<string, array{views: int, conversions: int}> By campaign ULID.
	 */
	public static function by_campaign( array $site_ids, string $first ): array {
		if ( array() === $site_ids ) {
			return array();
		}
		global $wpdb;
		$in = implode( ',', array_map( 'intval', $site_ids ) );
		return self::keyed(
			$wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is integers.
					"SELECT campaign_uid AS k, SUM(views) AS views, SUM(conversions) AS conversions FROM %i WHERE site_id IN ({$in}) AND day >= %s GROUP BY campaign_uid",
					$wpdb->prefix . Schema::METRICS,
					$first
				),
				ARRAY_A
			)
		);
	}

	/**
	 * One site's totals, every campaign, for days in a range (S13).
	 *
	 * @param int    $site_id Site.
	 * @param string $first   First day, Y-m-d.
	 * @param string $last    Last day, Y-m-d.
	 * @return array{views: int, conversions: int}
	 */
	public static function for_site( int $site_id, string $first, string $last ): array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COALESCE(SUM(views), 0) AS views, COALESCE(SUM(conversions), 0) AS conversions FROM %i WHERE site_id = %d AND day BETWEEN %s AND %s',
				$wpdb->prefix . Schema::METRICS,
				$site_id,
				$first,
				$last
			),
			ARRAY_A
		);
		return array(
			'views'       => (int) ( $row['views'] ?? 0 ),
			'conversions' => (int) ( $row['conversions'] ?? 0 ),
		);
	}

	/**
	 * Rows keyed by their "k" column.
	 *
	 * @param mixed $rows Query result.
	 * @return array<int|string, array{views: int, conversions: int}>
	 */
	private static function keyed( $rows ): array {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ is_numeric( $row['k'] ) ? (int) $row['k'] : (string) $row['k'] ] = array(
				'views'       => (int) $row['views'],
				'conversions' => (int) $row['conversions'],
			);
		}
		return $out;
	}
}
