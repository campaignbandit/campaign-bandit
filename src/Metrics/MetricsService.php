<?php
/**
 * Counts views and conversions as daily totals (BR-15). No per-visitor rows (BR-14).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Metrics;

use CampaignBandit\Connect\Connection;
use CampaignBandit\Database\Schema;
use CampaignBandit\Publishing\DeploymentRepository;

/**
 * Records events from the visitor runtime for campaigns live on this install.
 */
final class MetricsService {

	public const DEVICES = array( 'mobile', 'tablet', 'desktop' );

	/**
	 * Create the service.
	 *
	 * @param DeploymentRepository $deployments Deployments.
	 */
	public function __construct( private readonly DeploymentRepository $deployments ) {}

	/**
	 * Normalise a page group: a lowercase first path segment, or '/'.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function page_group( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '#^/[a-z0-9._~%-]{1,60}$#', strtolower( $value ) ) ) {
			return '/';
		}
		return strtolower( $value );
	}

	/**
	 * Count one view or conversion. Ignored unless that version is live here.
	 *
	 * @param string $campaign_uid Campaign ULID.
	 * @param int    $version      Version number.
	 * @param string $type         'view' or 'conversion'.
	 * @param string $device       mobile, tablet or desktop.
	 * @param string $group        Page group.
	 * @return bool Whether it was counted.
	 */
	public function record( string $campaign_uid, int $version, string $type, string $device, string $group ): bool {
		// Anything from a staging site counts in no metric (BR-15, BR-33).
		if ( ! in_array( $type, array( 'view', 'conversion' ), true ) || Connection::is_staging() ) {
			return false;
		}
		$deployment = $this->deployments->find( $campaign_uid, 0 );
		if ( null === $deployment || 'live' !== $deployment->state || $deployment->version_number !== $version ) {
			return false;
		}

		global $wpdb;
		$column = 'view' === $type ? 'views' : 'conversions';
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT INTO %i (campaign_uid, site_id, version_number, day, device, page_group, views, conversions) VALUES (%s, 0, %d, %s, %s, %s, %d, %d) ON DUPLICATE KEY UPDATE %i = %i + 1',
				$wpdb->prefix . Schema::METRICS,
				$campaign_uid,
				$version,
				wp_date( 'Y-m-d' ),
				in_array( $device, self::DEVICES, true ) ? $device : '',
				self::page_group( $group ),
				'views' === $column ? 1 : 0,
				'conversions' === $column ? 1 : 0,
				$column,
				$column
			)
		);
		return true;
	}

	/**
	 * This site's daily totals for some campaigns from a day on, oldest day first, with
	 * one row more than MetricsReport::MAX_ROWS when there are more (FR-61).
	 *
	 * @param string[] $campaign_uids Campaign ULIDs.
	 * @param string   $from          First day, Y-m-d.
	 * @return array<int, array{campaign: string, version: int, day: string, device: string, page_group: string, views: int, conversions: int}>
	 */
	public function report_rows( array $campaign_uids, string $from ): array {
		$campaign_uids = array_values( array_filter( $campaign_uids, 'is_string' ) );
		if ( array() === $campaign_uids ) {
			return array();
		}
		global $wpdb;
		$in   = implode( ',', array_fill( 0, count( $campaign_uids ), '%s' ) );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one placeholder per campaign.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is placeholders, one per campaign.
				"SELECT campaign_uid, version_number, day, device, page_group, views, conversions FROM %i WHERE site_id = 0 AND day >= %s AND campaign_uid IN ({$in}) ORDER BY day, id LIMIT %d",
				array_merge( array( $wpdb->prefix . Schema::METRICS, $from ), $campaign_uids, array( MetricsReport::MAX_ROWS + 1 ) )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'campaign'    => (string) $row['campaign_uid'],
				'version'     => (int) $row['version_number'],
				'day'         => (string) $row['day'],
				'device'      => (string) $row['device'],
				'page_group'  => (string) $row['page_group'],
				'views'       => (int) $row['views'],
				'conversions' => (int) $row['conversions'],
			);
		}
		return $out;
	}

	/**
	 * On a hub: store totals a client site reported, as they are (they are the site's
	 * totals, not increments, so a report sent twice changes nothing).
	 *
	 * @param int                                                                                                                              $site_id Site.
	 * @param array<int, array{campaign: string, version: int, day: string, device: string, page_group: string, views: int, conversions: int}> $rows    Rows from MetricsReport::clean().
	 * @return int Rows stored.
	 */
	public function store_reported( int $site_id, array $rows ): int {
		if ( $site_id < 1 || array() === $rows ) {
			return 0;
		}
		global $wpdb;
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$values = array();
			$args   = array( $wpdb->prefix . Schema::METRICS );
			foreach ( $chunk as $row ) {
				$values[] = '(%s, %d, %d, %s, %s, %s, %d, %d)';
				array_push( $args, $row['campaign'], $site_id, $row['version'], $row['day'], $row['device'], $row['page_group'], $row['views'], $row['conversions'] );
			}
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $values is placeholders only.
					'INSERT INTO %i (campaign_uid, site_id, version_number, day, device, page_group, views, conversions) VALUES ' . implode( ', ', $values ) . ' ON DUPLICATE KEY UPDATE views = VALUES(views), conversions = VALUES(conversions)',
					$args
				)
			);
		}
		return count( $rows );
	}
}
