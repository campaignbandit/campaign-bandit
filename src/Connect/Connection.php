<?php
/**
 * This site's connection to a hub (FR-10, decision 0014). The site's secret key is
 * stored sealed and never leaves the site.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

use CampaignBandit\Support\SecretBox;

/**
 * Reads and writes the connection option.
 *
 * @phpstan-type ConnectionData array{hub_url: string, hub_name: string, hub_public_key: string, site_uid: string, site_public_key: string, site_secret: string, paired_url: string, connection: string, workspace: string, last_sequence: int, last_sync_at: ?int, connected_at: int, white_label?: array{name: string, support_url: string, logo: string}|null}
 */
final class Connection {

	public const OPTION = 'campaignbandit_connection';

	/**
	 * The box keyed from this site's salts.
	 */
	public static function box(): SecretBox {
		return new SecretBox( wp_salt( 'secure_auth' ) . wp_salt( 'auth' ) );
	}

	/**
	 * The stored connection, with the secret key opened, or null when not connected.
	 *
	 * @return ConnectionData|null
	 */
	public static function get(): ?array {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || ! is_string( $stored['site_uid'] ?? null ) || ! is_string( $stored['site_secret'] ?? null ) ) {
			return null;
		}
		$secret = self::box()->open( $stored['site_secret'] );
		if ( null === $secret ) {
			return null;
		}
		return array(
			'hub_url'         => (string) ( $stored['hub_url'] ?? '' ),
			'hub_name'        => (string) ( $stored['hub_name'] ?? '' ),
			'hub_public_key'  => (string) ( $stored['hub_public_key'] ?? '' ),
			'site_uid'        => $stored['site_uid'],
			'site_public_key' => (string) ( $stored['site_public_key'] ?? '' ),
			'site_secret'     => $secret,
			'paired_url'      => (string) ( $stored['paired_url'] ?? '' ),
			'connection'      => 'staging' === ( $stored['connection'] ?? '' ) ? 'staging' : 'live',
			'workspace'       => (string) ( $stored['workspace'] ?? '' ),
			'last_sequence'   => (int) ( $stored['last_sequence'] ?? 0 ),
			'last_sync_at'    => isset( $stored['last_sync_at'] ) ? (int) $stored['last_sync_at'] : null,
			'connected_at'    => (int) ( $stored['connected_at'] ?? 0 ),
		);
	}

	/**
	 * Store a connection.
	 *
	 * @param array<string, mixed> $connection Connection with the secret key in plain form.
	 * @phpstan-param ConnectionData $connection
	 */
	public static function save( array $connection ): void {
		$connection['site_secret'] = self::box()->seal( $connection['site_secret'] );
		update_option( self::OPTION, $connection, true );
	}

	/**
	 * Change some fields of the stored connection (sequence, last sync, white label).
	 *
	 * @param array<string, int|string|array<string, string>|null> $fields Fields to change.
	 */
	public static function update( array $fields ): void {
		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) ) {
			update_option( self::OPTION, array_merge( $stored, $fields ), true );
		}
	}

	/**
	 * Whether this site is connected as staging (BR-33). Reads only the autoloaded
	 * option, so it is cheap on every request.
	 */
	public static function is_staging(): bool {
		$stored = get_option( self::OPTION );
		return is_array( $stored ) && 'staging' === ( $stored['connection'] ?? '' );
	}

	/**
	 * Forget the connection.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * This site's URL as it is compared with the paired URL (BR-34).
	 */
	public static function current_url(): string {
		return untrailingslashit( home_url() );
	}
}
