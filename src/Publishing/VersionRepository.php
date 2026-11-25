<?php
/**
 * Stores and reads versions. Rows are inserted, never updated (BR-20).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Publishing;

use CampaignBandit\Campaign\CampaignSettings;
use CampaignBandit\Database\Schema;
use RuntimeException;

/**
 * Data access for the versions table.
 */
final class VersionRepository {

	/**
	 * Full table name.
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::VERSIONS;
	}

	/**
	 * Insert the next version of a campaign.
	 *
	 * @param string           $campaign_uid   Campaign ULID.
	 * @param string           $content        Block markup.
	 * @param CampaignSettings $settings       Settings.
	 * @param string           $change_summary What changed.
	 * @param int              $user_id        Publishing user.
	 * @param string           $user_name      Their display name.
	 * @throws RuntimeException When the row can't be written.
	 */
	public function insert_next( string $campaign_uid, string $content, CampaignSettings $settings, string $change_summary, int $user_id, string $user_name ): Version {
		global $wpdb;

		$number = $this->latest_number( $campaign_uid ) + 1;
		$now    = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'campaign_uid'      => $campaign_uid,
				'number'            => $number,
				'content'           => $content,
				'settings'          => $settings->to_json(),
				'change_summary'    => $change_summary,
				'published_by'      => $user_id,
				'published_by_name' => $user_name,
				'published_at'      => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new RuntimeException( 'Could not store the version.' );
		}

		return new Version( (int) $wpdb->insert_id, $campaign_uid, $number, $content, $settings, $change_summary, $user_id, $now );
	}

	/**
	 * Store a version received from the hub under its hub number, once. A connected
	 * site keeps hub versions in the same table as its own, so leads, metrics and the
	 * package use them unchanged; hub campaign IDs never clash with local ones (ULIDs).
	 *
	 * @param string           $campaign_uid Campaign ULID.
	 * @param int              $number       Version number on the hub.
	 * @param string           $content      Block markup.
	 * @param CampaignSettings $settings     Settings.
	 * @param string           $published_at When the hub published it (UTC, Y-m-d H:i:s).
	 * @throws RuntimeException When the row can't be written.
	 */
	public function store_received( string $campaign_uid, int $number, string $content, CampaignSettings $settings, string $published_at ): Version {
		global $wpdb;
		$existing = $this->find_number( $campaign_uid, $number );
		if ( null !== $existing ) {
			// A version's content never changes, but its settings can: the hub sends this
			// site's own settings (BR-21) with it, and they change when the site's settings
			// are saved on the hub.
			if ( $existing->settings->to_json() !== $settings->to_json() ) {
				$wpdb->update( $this->table(), array( 'settings' => $settings->to_json() ), array( 'id' => $existing->id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new Version( $existing->id, $existing->campaign_uid, $existing->number, $existing->content, $settings, $existing->change_summary, $existing->published_by, $existing->published_at );
			}
			return $existing;
		}
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'campaign_uid'      => $campaign_uid,
				'number'            => $number,
				'content'           => $content,
				'settings'          => $settings->to_json(),
				'change_summary'    => '',
				'published_by'      => 0,
				'published_by_name' => '',
				'published_at'      => $published_at,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new RuntimeException( 'Could not store the version.' );
		}
		return new Version( (int) $wpdb->insert_id, $campaign_uid, $number, $content, $settings, '', 0, $published_at );
	}

	/**
	 * A campaign's versions, newest first, with who published each (S12 Versions).
	 *
	 * @param string $campaign_uid Campaign ULID.
	 * @return array<int, array{version: Version, published_by_name: string}>
	 */
	public function for_campaign( string $campaign_uid ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE campaign_uid = %s ORDER BY number DESC', $this->table(), $campaign_uid ),
			ARRAY_A
		);
		return array_map(
			static fn ( array $row ): array => array(
				'version'           => self::from_row( $row ),
				'published_by_name' => (string) ( $row['published_by_name'] ?? '' ),
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Highest version number of a campaign, 0 when it has none.
	 *
	 * @param string $campaign_uid Campaign ULID.
	 */
	public function latest_number( string $campaign_uid ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT MAX(number) FROM %i WHERE campaign_uid = %s', $this->table(), $campaign_uid )
		);
	}

	/**
	 * Find a campaign's version by number.
	 *
	 * @param string $campaign_uid Campaign ULID.
	 * @param int    $number       Version number.
	 */
	public function find_number( string $campaign_uid, int $number ): ?Version {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE campaign_uid = %s AND number = %d', $this->table(), $campaign_uid, $number ),
			ARRAY_A
		);
		return is_array( $row ) ? self::from_row( $row ) : null;
	}

	/**
	 * Find a version by row ID.
	 *
	 * @param int $id Row ID.
	 */
	public function find( int $id ): ?Version {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ),
			ARRAY_A
		);
		return is_array( $row ) ? self::from_row( $row ) : null;
	}

	/**
	 * Build a version from a row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private static function from_row( array $row ): Version {
		return new Version(
			(int) $row['id'],
			(string) $row['campaign_uid'],
			(int) $row['number'],
			(string) $row['content'],
			CampaignSettings::from_json( (string) $row['settings'] ),
			(string) $row['change_summary'],
			(int) $row['published_by'],
			(string) $row['published_at'],
		);
	}
}
