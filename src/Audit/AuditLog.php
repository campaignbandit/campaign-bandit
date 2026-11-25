<?php
/**
 * The audit trail (BR-71): who did what, to which object, when, and through which channel.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Audit;

use CampaignBandit\Database\Schema;

/**
 * Records audit events. Retention (365 days) runs with the other cleanup jobs.
 */
final class AuditLog {

	public const CHANNEL_SCREEN = 'screen';
	public const CHANNEL_REST   = 'rest';
	public const CHANNEL_MCP    = 'mcp';
	public const CHANNEL_SYSTEM = 'system';

	/**
	 * Record one event as the current user.
	 *
	 * @param string               $action      For example `campaign.published`.
	 * @param string               $object_type For example `campaign`.
	 * @param string               $object_id   Object ID (a ULID for campaigns).
	 * @param string               $channel     One of the channel constants.
	 * @param array<string, mixed> $details     Extra facts, such as the version number.
	 */
	public function record( string $action, string $object_type, string $object_id, string $channel, array $details = array() ): void {
		global $wpdb;
		$user = wp_get_current_user();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . Schema::AUDIT,
			array(
				'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => $user->ID,
				'user_name'   => $user->display_name,
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'channel'     => $channel,
				'details'     => array() === $details ? null : wp_json_encode( $details ),
			),
			// Explicit formats: WordPress treats any column named object_id as an integer by default.
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * How long events are kept (BR-71).
	 */
	public const KEEP_DAYS = 365;

	/**
	 * Areas for the audit log filter (S8): object types.
	 */
	public const AREAS = array( 'campaign', 'site', 'destination', 'lead', 'setting', 'pairing_key', 'hub' );

	/**
	 * Events matching a filter, newest first, a page at a time (Settings › Audit log, S8).
	 *
	 * @param array{area?: string, user?: int, days?: int} $filter   Area (object type), person, last n days.
	 * @param int                                          $page     Page, from 1.
	 * @param int                                          $per_page Events per page.
	 * @return array{events: array<int, array{occurred_at: string, user_name: string, action: string, object_type: string, object_id: string, channel: string, details: array<string, mixed>}>, total: int}
	 */
	public function query( array $filter, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$where = array( '1 = 1' );
		$args  = array( $wpdb->prefix . Schema::AUDIT );
		if ( in_array( $filter['area'] ?? '', self::AREAS, true ) ) {
			$where[] = 'object_type = %s';
			$args[]  = $filter['area'];
		}
		if ( ( $filter['user'] ?? 0 ) > 0 ) {
			$where[] = 'user_id = %d';
			$args[]  = (int) $filter['user'];
		}
		if ( ( $filter['days'] ?? 0 ) > 0 ) {
			$where[] = 'occurred_at >= %s';
			$args[]  = gmdate( 'Y-m-d H:i:s', time() - (int) $filter['days'] * DAY_IN_SECONDS );
		}
		$sql   = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$sql}", ...$args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the WHERE is built from fixed fragments with placeholders.
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT occurred_at, user_name, action, object_type, object_id, channel, details FROM %i WHERE {$sql} ORDER BY id DESC LIMIT %d OFFSET %d", ...array_merge( $args, array( $per_page, max( 0, $page - 1 ) * $per_page ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- as above; the arguments are spread.
			ARRAY_A
		);
		return array(
			'events' => array_map(
				static function ( array $row ): array {
					$details = json_decode( (string) $row['details'], true );
					return array(
						'occurred_at' => (string) $row['occurred_at'],
						'user_name'   => (string) $row['user_name'],
						'action'      => (string) $row['action'],
						'object_type' => (string) $row['object_type'],
						'object_id'   => (string) $row['object_id'],
						'channel'     => (string) $row['channel'],
						'details'     => is_array( $details ) ? $details : array(),
					);
				},
				is_array( $rows ) ? $rows : array()
			),
			'total'  => $total,
		);
	}

	/**
	 * People who appear in the trail, for the filter.
	 *
	 * @return array<int, string> User ID => name.
	 */
	public function people(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT user_id, MAX(user_name) AS user_name FROM %i WHERE user_id > 0 GROUP BY user_id ORDER BY user_name ASC', $wpdb->prefix . Schema::AUDIT ),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['user_id'] ] = (string) $row['user_name'];
		}
		return $out;
	}

	/**
	 * Forget events older than 365 days (BR-71).
	 *
	 * @param int $now Current Unix time.
	 * @return int Events removed.
	 */
	public function prune( int $now ): int {
		global $wpdb;
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'DELETE FROM %i WHERE occurred_at < %s', $wpdb->prefix . Schema::AUDIT, gmdate( 'Y-m-d H:i:s', $now - self::KEEP_DAYS * DAY_IN_SECONDS ) )
		);
	}

	/**
	 * The newest events of every kind (S1 Recent activity).
	 *
	 * @param int $limit Most events to return.
	 * @return array<int, array{occurred_at: string, user_name: string, action: string, object_type: string, object_id: string, details: array<string, mixed>}>
	 */
	public function recent( int $limit = 10 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT occurred_at, user_name, action, object_type, object_id, details FROM %i ORDER BY id DESC LIMIT %d', $wpdb->prefix . Schema::AUDIT, $limit ),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				$details = json_decode( (string) $row['details'], true );
				return array(
					'occurred_at' => (string) $row['occurred_at'],
					'user_name'   => (string) $row['user_name'],
					'action'      => (string) $row['action'],
					'object_type' => (string) $row['object_type'],
					'object_id'   => (string) $row['object_id'],
					'details'     => is_array( $details ) ? $details : array(),
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Events for one object, newest first (S12 Activity).
	 *
	 * @param string $object_type Object type, such as campaign.
	 * @param string $object_id   Object ID.
	 * @param int    $limit       Most events to return.
	 * @return array<int, array{occurred_at: string, user_name: string, action: string, channel: string, details: array<string, mixed>}>
	 */
	public function for_object( string $object_type, string $object_id, int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT occurred_at, user_name, action, channel, details FROM %i WHERE object_type = %s AND object_id = %s ORDER BY id DESC LIMIT %d',
				$wpdb->prefix . Schema::AUDIT,
				$object_type,
				$object_id,
				$limit
			),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				$details = json_decode( (string) $row['details'], true );
				return array(
					'occurred_at' => (string) $row['occurred_at'],
					'user_name'   => (string) $row['user_name'],
					'action'      => (string) $row['action'],
					'channel'     => (string) $row['channel'],
					'details'     => is_array( $details ) ? $details : array(),
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Events for one object, plus events of another type whose details contain a text,
	 * newest first. Site detail (S13) uses it for the site's own events and the campaign
	 * events that name the site; the caller checks each match exactly.
	 *
	 * @param string $object_type  Object type, such as site.
	 * @param string $object_id    Object ID.
	 * @param string $mention_type Type of the other events, such as campaign.
	 * @param string $needle       Text their details must contain.
	 * @param int    $limit        Most events to return.
	 * @return array<int, array{object_type: string, object_id: string, occurred_at: string, user_name: string, action: string, channel: string, details: array<string, mixed>}>
	 */
	public function for_object_or_mentioning( string $object_type, string $object_id, string $mention_type, string $needle, int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT object_type, object_id, occurred_at, user_name, action, channel, details FROM %i WHERE ( object_type = %s AND object_id = %s ) OR ( object_type = %s AND details LIKE %s ) ORDER BY id DESC LIMIT %d',
				$wpdb->prefix . Schema::AUDIT,
				$object_type,
				$object_id,
				$mention_type,
				'%' . $wpdb->esc_like( $needle ) . '%',
				$limit
			),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				$details = json_decode( (string) $row['details'], true );
				return array(
					'object_type' => (string) $row['object_type'],
					'object_id'   => (string) $row['object_id'],
					'occurred_at' => (string) $row['occurred_at'],
					'user_name'   => (string) $row['user_name'],
					'action'      => (string) $row['action'],
					'channel'     => (string) $row['channel'],
					'details'     => is_array( $details ) ? $details : array(),
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}
}
