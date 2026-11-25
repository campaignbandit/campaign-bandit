<?php
/**
 * Stores leads (FR-50).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Leads;

use CampaignBandit\Database\Schema;
use DateTimeZone;
use RuntimeException;

/**
 * Data access for the leads table.
 */
final class LeadRepository {

	/**
	 * Full table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::LEADS;
	}

	/**
	 * Insert a lead.
	 *
	 * @param array<string, mixed> $lead Lead columns.
	 * @throws RuntimeException When the row can't be written.
	 */
	public function insert( array $lead ): void {
		global $wpdb;
		$formats = array(
			'uid'               => '%s',
			'site_id'           => '%d',
			'campaign_uid'      => '%s',
			'version_number'    => '%d',
			'deployment_id'     => '%d',
			'submitted_at'      => '%s',
			'email'             => '%s',
			'name'              => '%s',
			'fields'            => '%s',
			'consent_text'      => '%s',
			'consent'           => '%s',
			'page_url'          => '%s',
			'utm'               => '%s',
			'source'            => '%s',
			'explanation'       => '%s',
			'status'            => '%s',
			'forward_status'    => '%s',
			'forward_attempts'  => '%d',
			'forwarded_at'      => '%s',
			'is_test'           => '%d',
			'delivered_by_site' => '%d',
			'created_at'        => '%s',
		);
		$row     = array_intersect_key( $lead, $formats );
		// One format per column, in the row's own order.
		$written = $wpdb->insert( self::table(), $row, array_map( static fn ( string $key ): string => $formats[ $key ], array_keys( $row ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $written ) {
			throw new RuntimeException( 'Could not store the lead.' );
		}
	}

	/**
	 * Leads matching a filter, newest first.
	 *
	 * @param LeadFilter   $filter        Filter.
	 * @param DateTimeZone $site_timezone Site timezone, for date filters.
	 * @param int          $limit         Rows.
	 * @param int          $offset        Rows to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( LeadFilter $filter, DateTimeZone $site_timezone, int $limit, int $offset = 0 ): array {
		global $wpdb;
		$where = $filter->where( $site_timezone );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where['sql'] is fixed SQL from LeadFilter::where(); every value is a placeholder.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- values are spread from LeadFilter.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- conditions come from LeadFilter, values are placeholders.
				"SELECT * FROM %i WHERE {$where['sql']} ORDER BY submitted_at DESC, id DESC LIMIT %d OFFSET %d",
				self::table(),
				...array_merge( $where['args'], array( max( 1, $limit ), max( 0, $offset ) ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many leads match a filter.
	 *
	 * @param LeadFilter   $filter        Filter.
	 * @param DateTimeZone $site_timezone Site timezone.
	 */
	public function count( LeadFilter $filter, DateTimeZone $site_timezone ): int {
		global $wpdb;
		$where = $filter->where( $site_timezone );
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where['sql'] is fixed SQL from LeadFilter::where(); every value is a placeholder.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- values are spread from LeadFilter.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- conditions come from LeadFilter, values are placeholders.
				"SELECT COUNT(*) FROM %i WHERE {$where['sql']}",
				self::table(),
				...$where['args']
			)
		);
	}

	/**
	 * Matching leads in batches by ID, for exports that don't fit in memory.
	 *
	 * @param LeadFilter   $filter        Filter.
	 * @param DateTimeZone $site_timezone Site timezone.
	 * @param int          $batch         Rows per query.
	 * @return \Generator<int, array<string, mixed>>
	 */
	public function each( LeadFilter $filter, DateTimeZone $site_timezone, int $batch = 500 ): \Generator {
		global $wpdb;
		$where = $filter->where( $site_timezone );
		$after = PHP_INT_MAX;
		do {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where['sql'] is fixed SQL from LeadFilter::where(); every value is a placeholder.
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- values are spread from LeadFilter.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- conditions come from LeadFilter, values are placeholders.
					"SELECT * FROM %i WHERE {$where['sql']} AND id < %d ORDER BY id DESC LIMIT %d",
					self::table(),
					...array_merge( $where['args'], array( $after, $batch ) )
				),
				ARRAY_A
			);
			$rows    = is_array( $rows ) ? $rows : array();
			$fetched = count( $rows );
			foreach ( $rows as $row ) {
				$after = (int) $row['id'];
				yield $row;
			}
		} while ( $fetched === $batch );
	}

	/**
	 * Campaign ULIDs that have leads, for the campaign filter.
	 *
	 * @return string[]
	 */
	public function campaign_uids(): array {
		global $wpdb;
		$uids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT DISTINCT campaign_uid FROM %i', self::table() )
		);
		return array_map( 'strval', is_array( $uids ) ? $uids : array() );
	}

	/**
	 * Leads whose email column or form values hold an email address (FR-55).
	 *
	 * @param string $email  Email address.
	 * @param int    $limit  Rows.
	 * @param int    $offset Rows to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_email( string $email, int $limit, int $offset = 0 ): array {
		global $wpdb;
		// The form values are JSON, where the address appears as a quoted string.
		$quoted = '%' . $wpdb->esc_like( '"' . $email . '"' ) . '%';
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM %i WHERE email = %s OR fields LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d',
				self::table(),
				$email,
				$quoted,
				$limit,
				$offset
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete up to a batch of leads received before a UTC time (BR-50).
	 *
	 * @param string $before UTC "Y-m-d H:i:s".
	 * @param int    $limit  Batch size.
	 * @return string[] ULIDs of the deleted leads.
	 */
	public function delete_before( string $before, int $limit ): array {
		global $wpdb;
		$uids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT uid FROM %i WHERE submitted_at < %s ORDER BY id ASC LIMIT %d', self::table(), $before, $limit )
		);
		$uids = array_map( 'strval', is_array( $uids ) ? $uids : array() );
		if ( array() === $uids ) {
			return array();
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one value per ULID placeholder.
				'DELETE FROM %i WHERE uid IN (' . implode( ',', array_fill( 0, count( $uids ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared -- one placeholder per ULID.
				self::table(),
				...$uids
			)
		);
		return $uids;
	}

	/**
	 * Set a lead's delivery status (S6): stored_only, sending, delivered, retrying, failed.
	 *
	 * @param string $uid    Lead ULID.
	 * @param string $status Status.
	 */
	public function set_status( string $uid, string $status ): void {
		global $wpdb;
		$changed = $wpdb->update( self::table(), array( 'status' => $status ), array( 'uid' => $uid ), array( '%s' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $changed > 0 ) {
			/**
			 * Fires after a lead's delivery status changes. A connected site sends the
			 * new status of its own campaigns' leads to the hub (BR-61).
			 *
			 * @param string $uid    Lead ULID.
			 * @param string $status New status.
			 */
			do_action( 'campaignbandit_lead_status_changed', $uid, $status );
		}
	}

	/**
	 * Leads due to be forwarded to the hub (BR-41), oldest first.
	 *
	 * @param string $now   Current UTC time, Y-m-d H:i:s.
	 * @param int    $limit Batch size.
	 * @return array<int, array<string, mixed>>
	 */
	public function due_forwards( string $now, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM %i WHERE forward_status = %s AND (next_forward_at IS NULL OR next_forward_at <= %s) ORDER BY id ASC LIMIT %d',
				self::table(),
				'pending',
				$now,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Give up on leads still waiting to be forwarded that were submitted before a
	 * time: they become "Not forwarded" and stay on the site (BR-41, BR-34).
	 *
	 * @param string $before UTC time, Y-m-d H:i:s.
	 * @return int Leads changed.
	 */
	public function expire_forwards( string $before ): int {
		global $wpdb;
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE %i SET forward_status = %s, next_forward_at = NULL WHERE forward_status = %s AND submitted_at < %s',
				self::table(),
				'not_forwarded',
				'pending',
				$before
			)
		);
	}

	/**
	 * Count leads by forwarding status, for the Connection section and the hub (S9).
	 *
	 * @param string $status Forwarding status.
	 */
	public function count_forward( string $status ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE forward_status = %s', self::table(), $status )
		);
	}

	/**
	 * Record whether the new-lead email went out (FR-56).
	 *
	 * @param string $uid    Lead ULID.
	 * @param string $status sent or failed.
	 */
	public function set_notified( string $uid, string $status ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'notified' => $status ), array( 'uid' => $uid ), array( '%s' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Leads per site since a time, for the Sites list on a hub (S2 "leads 7 days").
	 *
	 * @param string $since UTC "Y-m-d H:i:s".
	 * @return array<int, int> Site ID => leads.
	 */
	public function counts_by_site( string $since ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT site_id, COUNT(*) AS leads FROM %i WHERE submitted_at >= %s GROUP BY site_id', self::table(), $since ),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['site_id'] ] = (int) $row['leads'];
		}
		return $out;
	}

	/**
	 * Record a forwarding result.
	 *
	 * @param string      $uid      Lead ULID.
	 * @param string      $status   pending, forwarded or not_forwarded.
	 * @param int         $attempts Attempts so far.
	 * @param string|null $next     Next attempt (UTC), or null.
	 */
	public function set_forward( string $uid, string $status, int $attempts, ?string $next ): void {
		global $wpdb;
		$data = array(
			'forward_status'   => $status,
			'forward_attempts' => $attempts,
			'next_forward_at'  => $next,
		);
		if ( 'forwarded' === $status ) {
			$data['forwarded_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		$wpdb->update( self::table(), $data, array( 'uid' => $uid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Start forwarding a lead; on a staging connection it becomes a test lead (BR-33).
	 *
	 * @param string $uid     Lead ULID.
	 * @param bool   $is_test Mark as a test lead.
	 */
	public function queue_forward( string $uid, bool $is_test ): void {
		global $wpdb;
		$data = array(
			'forward_status'  => 'pending',
			'next_forward_at' => null,
		);
		if ( $is_test ) {
			$data['is_test'] = 1;
		}
		$wpdb->update( self::table(), $data, array( 'uid' => $uid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Make every lead still waiting to be forwarded a test lead: a copied site was
	 * marked as staging on the hub (BR-34).
	 */
	public function mark_held_as_test(): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'UPDATE %i SET is_test = 1 WHERE forward_status = %s', self::table(), 'pending' )
		);
	}

	/**
	 * Store a lead forwarded by a connected site, once (decision 0014: a duplicate is
	 * acknowledged, not stored again).
	 *
	 * @param array<string, mixed> $lead Lead columns.
	 * @return bool True when stored now, false when it was already here.
	 * @throws RuntimeException When the row can't be written.
	 */
	public function insert_forwarded( array $lead ): bool {
		if ( null !== $this->find( (string) $lead['uid'] ) ) {
			return false;
		}
		$this->insert( $lead );
		return true;
	}

	/**
	 * Delete a lead.
	 *
	 * @param string $uid Lead ULID.
	 * @return bool Whether a lead was deleted.
	 */
	public function delete( string $uid ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'uid' => $uid ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Find a lead by its ULID.
	 *
	 * @param string $uid Lead ULID.
	 * @return array<string, mixed>|null
	 */
	public function find( string $uid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE uid = %s', self::table(), $uid ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}
}
