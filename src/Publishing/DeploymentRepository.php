<?php
/**
 * Stores and reads deployments.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Publishing;

use CampaignBandit\Database\Schema;
use RuntimeException;

/**
 * Data access for the deployments table. One row per campaign per site.
 */
final class DeploymentRepository {

	/**
	 * Full table name.
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::DEPLOYMENTS;
	}

	/**
	 * The deployment of a campaign on a site, if any.
	 *
	 * @param string $campaign_uid Campaign ULID.
	 * @param int    $site_id      Site, 0 for this install.
	 */
	public function find( string $campaign_uid, int $site_id = 0 ): ?Deployment {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE campaign_uid = %s AND site_id = %d', $this->table(), $campaign_uid, $site_id ),
			ARRAY_A
		);
		return is_array( $row ) ? self::from_row( $row ) : null;
	}

	/**
	 * Every live deployment on a site.
	 *
	 * @param int $site_id Site, 0 for this install.
	 * @return Deployment[]
	 */
	public function live( int $site_id = 0 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE site_id = %d AND state = %s', $this->table(), $site_id, Deployment::LIVE ),
			ARRAY_A
		);
		return array_map( array( self::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * A site's deployments in the given states.
	 *
	 * @param int      $site_id Site, 0 for this install.
	 * @param string[] $states  States to include.
	 * @return Deployment[]
	 */
	public function on_site( int $site_id, array $states ): array {
		global $wpdb;
		if ( array() === $states ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $states ), '%s' ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM %i WHERE site_id = %d AND state IN ({$placeholders}) ORDER BY id ASC", $this->table(), $site_id, ...$states ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders only, one per state.
			ARRAY_A
		);
		return array_map( array( self::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Give a new site the same live and paused deployments as another, with their per-site
	 * settings: a copied site marked as staging (BR-34). Schedules are not copied.
	 *
	 * @param int $from Site to copy from.
	 * @param int $to   New site.
	 * @return int Deployments copied.
	 */
	public function copy_site( int $from, int $to ): int {
		global $wpdb;
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT INTO %i (campaign_uid, site_id, version_id, version_number, state, overrides, destinations, shows_reset_at, published_at, updated_at)
				SELECT campaign_uid, %d, version_id, version_number, state, overrides, destinations, shows_reset_at, published_at, %s FROM %i WHERE site_id = %d AND state IN (%s, %s)',
				$this->table(),
				$to,
				gmdate( 'Y-m-d H:i:s' ),
				$this->table(),
				$from,
				Deployment::LIVE,
				Deployment::PAUSED
			)
		);
	}

	/**
	 * Whether a publish of the campaign is scheduled on any site (BR-23).
	 *
	 * @param string $campaign_uid Campaign ULID.
	 */
	public function has_schedule( string $campaign_uid ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT 1 FROM %i WHERE campaign_uid = %s AND scheduled_at IS NOT NULL LIMIT 1', $this->table(), $campaign_uid )
		);
	}

	/**
	 * A campaign's deployments on every site, except removed ones.
	 *
	 * @param string $campaign_uid Campaign ULID.
	 * @return Deployment[]
	 */
	public function for_campaign( string $campaign_uid ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE campaign_uid = %s AND state <> %s ORDER BY site_id ASC', $this->table(), $campaign_uid, Deployment::REMOVED ),
			ARRAY_A
		);
		return array_map( array( self::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * A deployment's site overrides as stored (FR-42), or null when it has none.
	 *
	 * @param string $campaign_uid Campaign ULID.
	 * @param int    $site_id      Site.
	 * @return array<string, mixed>|null
	 */
	public function overrides( string $campaign_uid, int $site_id ): ?array {
		global $wpdb;
		$json  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT overrides FROM %i WHERE campaign_uid = %s AND site_id = %d', $this->table(), $campaign_uid, $site_id )
		);
		$value = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Store a deployment's site overrides. They stay when the site moves to another
	 * version, since deploy() changes only the version (BR-22).
	 *
	 * @param string                    $campaign_uid Campaign ULID.
	 * @param int                       $site_id      Site.
	 * @param array<string, mixed>|null $overrides    Overrides, or null for none.
	 */
	public function set_overrides( string $campaign_uid, int $site_id, ?array $overrides ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'overrides'  => null === $overrides ? null : wp_json_encode( $overrides ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'campaign_uid' => $campaign_uid,
				'site_id'      => $site_id,
			)
		);
	}

	/**
	 * Schedule a version for a site (BR-23). A site without a deployment gets a row in
	 * the "scheduled" state, which no package or manifest includes until it runs.
	 *
	 * @param Version $version     Version to switch to.
	 * @param int     $site_id     Site, 0 for this install.
	 * @param string  $at          When, UTC (Y-m-d H:i:s).
	 * @param bool    $reset_shows Show again to visitors who already saw it, when it runs.
	 */
	public function schedule( Version $version, int $site_id, string $at, bool $reset_shows ): void {
		global $wpdb;
		$existing = $this->find( $version->campaign_uid, $site_id );
		$data     = array(
			'scheduled_version_number' => $version->number,
			'scheduled_at'             => $at,
			'updated_at'               => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( null === $existing || Deployment::REMOVED === $existing->state ) {
			$data += array(
				'version_id'     => $version->id,
				'version_number' => $version->number,
				'state'          => Deployment::SCHEDULED,
			);
		}
		if ( null === $existing ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$this->table(),
				$data + array(
					'campaign_uid' => $version->campaign_uid,
					'site_id'      => $site_id,
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		} else {
			$wpdb->update( $this->table(), $data, array( 'id' => $existing->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		// No column holds this flag; it is kept until the switch runs (see take_scheduled_reset()).
		$name = self::reset_option( $version->campaign_uid, $site_id );
		if ( $reset_shows ) {
			update_option( $name, 1, false );
		} else {
			delete_option( $name );
		}
	}

	/**
	 * Whether a scheduled switch should show the campaign again to visitors who saw it,
	 * read once.
	 *
	 * @param string $campaign_uid Campaign ULID.
	 * @param int    $site_id      Site.
	 */
	public function take_scheduled_reset( string $campaign_uid, int $site_id ): bool {
		$name  = self::reset_option( $campaign_uid, $site_id );
		$reset = (bool) get_option( $name, false );
		delete_option( $name );
		return $reset;
	}

	/**
	 * Option name for a scheduled "show again".
	 *
	 * @param string $campaign_uid Campaign ULID.
	 * @param int    $site_id      Site.
	 */
	private static function reset_option( string $campaign_uid, int $site_id ): string {
		return 'campaignbandit_scheduled_reset_' . strtolower( $campaign_uid ) . '_' . $site_id;
	}

	/**
	 * Cancel a campaign's scheduled switches on every site (BR-23).
	 *
	 * @param string $campaign_uid Campaign ULID.
	 * @return int Sites whose schedule was cancelled.
	 */
	public function cancel_schedule( string $campaign_uid ): int {
		global $wpdb;
		$table   = $this->table();
		$removed = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'UPDATE %i SET state = %s, scheduled_version_number = NULL, scheduled_at = NULL WHERE campaign_uid = %s AND state = %s', $table, Deployment::REMOVED, $campaign_uid, Deployment::SCHEDULED )
		);
		$cleared = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'UPDATE %i SET scheduled_version_number = NULL, scheduled_at = NULL WHERE campaign_uid = %s AND scheduled_at IS NOT NULL', $table, $campaign_uid )
		);
		return $removed + $cleared;
	}

	/**
	 * Scheduled switches that are due.
	 *
	 * @param string $now Current UTC time.
	 * @return array<int, array{campaign_uid: string, site_id: int, version_number: int}>
	 */
	public function due_schedules( string $now ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT campaign_uid, site_id, scheduled_version_number FROM %i WHERE scheduled_at IS NOT NULL AND scheduled_at <= %s', $this->table(), $now ),
			ARRAY_A
		);
		return array_map(
			static fn ( array $row ): array => array(
				'campaign_uid'   => (string) $row['campaign_uid'],
				'site_id'        => (int) $row['site_id'],
				'version_number' => (int) $row['scheduled_version_number'],
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * A site's pending schedule for a campaign.
	 *
	 * @param string $campaign_uid Campaign ULID.
	 * @param int    $site_id      Site.
	 * @return array{version: int, at: string}|null
	 */
	public function scheduled( string $campaign_uid, int $site_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT scheduled_version_number, scheduled_at FROM %i WHERE campaign_uid = %s AND site_id = %d AND scheduled_at IS NOT NULL', $this->table(), $campaign_uid, $site_id ),
			ARRAY_A
		);
		return is_array( $row ) ? array(
			'version' => (int) $row['scheduled_version_number'],
			'at'      => (string) $row['scheduled_at'],
		) : null;
	}

	/**
	 * Clear a site's schedule once it ran.
	 *
	 * @param string $campaign_uid Campaign ULID.
	 * @param int    $site_id      Site.
	 */
	public function clear_schedule( string $campaign_uid, int $site_id ): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'UPDATE %i SET scheduled_version_number = NULL, scheduled_at = NULL WHERE campaign_uid = %s AND site_id = %d', $this->table(), $campaign_uid, $site_id )
		);
	}

	/**
	 * Point a site's deployment of a campaign at a version and make it live,
	 * creating the deployment if it doesn't exist yet.
	 *
	 * @param Version $version     Version to deploy.
	 * @param int     $site_id     Site, 0 for this install.
	 * @param bool    $reset_shows "Show again to visitors who already saw it" (BR-11).
	 * @throws RuntimeException When the row can't be written.
	 */
	public function deploy( Version $version, int $site_id, bool $reset_shows ): Deployment {
		global $wpdb;
		$now      = gmdate( 'Y-m-d H:i:s' );
		$existing = $this->find( $version->campaign_uid, $site_id );

		$data = array(
			'version_id'     => $version->id,
			'version_number' => $version->number,
			'state'          => Deployment::LIVE,
			'published_at'   => $now,
			'updated_at'     => $now,
		);
		if ( $reset_shows ) {
			$data['shows_reset_at'] = $now;
		}

		if ( null === $existing ) {
			$data   += array(
				'campaign_uid' => $version->campaign_uid,
				'site_id'      => $site_id,
			);
			$written = $wpdb->insert( $this->table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		} else {
			$written = $wpdb->update( $this->table(), $data, array( 'id' => $existing->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( false === $written ) {
			throw new RuntimeException( 'Could not store the deployment.' );
		}

		$deployment = $this->find( $version->campaign_uid, $site_id );
		if ( null === $deployment ) {
			throw new RuntimeException( 'Could not read the deployment back.' );
		}
		return $deployment;
	}

	/**
	 * Mark every deployment of a campaign as removed, on every site.
	 *
	 * @param string $campaign_uid Campaign ULID.
	 * @return int Rows changed.
	 */
	public function remove_campaign( string $campaign_uid ): int {
		global $wpdb;
		return (int) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'state'      => Deployment::REMOVED,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'campaign_uid' => $campaign_uid )
		);
	}

	/**
	 * Change a deployment's state (pause, resume).
	 *
	 * @param Deployment $deployment Deployment.
	 * @param string     $state      New state.
	 */
	public function set_state( Deployment $deployment, string $state ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'state'      => $state,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $deployment->id )
		);
	}

	/**
	 * Build a deployment from a row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private static function from_row( array $row ): Deployment {
		return new Deployment(
			(int) $row['id'],
			(string) $row['campaign_uid'],
			(int) $row['site_id'],
			(int) $row['version_id'],
			(int) $row['version_number'],
			(string) $row['state'],
			isset( $row['published_at'] ) ? (string) $row['published_at'] : null,
			isset( $row['shows_reset_at'] ) ? (string) $row['shows_reset_at'] : null,
		);
	}
}
