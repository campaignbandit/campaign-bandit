<?php
/**
 * The campaign package a hub sends a site (decision 0014, BR-30, BR-34).
 *
 * The hub sends each version's block markup and settings, not a rendered manifest:
 * the site stores them as ordinary versions and deployments and builds its own
 * manifest, so schedules follow the site's timezone (BR-08) and leads, metrics and the
 * visitor runtime need nothing new.
 *
 * WordPress-free, so the payload rules are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

/**
 * Builds the hash that decides a new sequence number, and turns a received package
 * into the changes a site makes.
 *
 * @phpstan-type HubCampaign array{campaign: string, name: string, version: int, state: string, settings: array<string, mixed>, content: string, published_at: string, shows_reset_at: ?string, media: array<int, mixed>}
 */
final class HubPackage {

	public const LIVE   = 'live';
	public const PAUSED = 'paused';

	/**
	 * A hash of what the site would receive, without the sequence number: the hub
	 * gives a site a new sequence only when this changes.
	 *
	 * @param array<string, mixed> $payload Payload without "sequence" and "generated_at".
	 */
	public static function hash( array $payload ): string {
		unset( $payload['sequence'], $payload['generated_at'] );
		return hash( 'sha256', (string) json_encode( self::sorted( $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-free.
	}

	/**
	 * Campaigns from a payload that are well formed. Anything else is dropped.
	 *
	 * @param mixed $campaigns Payload "campaigns".
	 * @return array<string, HubCampaign> By campaign ID.
	 */
	public static function campaigns( $campaigns ): array {
		$out = array();
		foreach ( is_array( $campaigns ) ? $campaigns : array() as $c ) {
			if ( ! is_array( $c )
				|| ! is_string( $c['campaign'] ?? null ) || 1 !== preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $c['campaign'] )
				|| ! is_int( $c['version'] ?? null ) || $c['version'] < 1
				|| ! in_array( $c['state'] ?? null, array( self::LIVE, self::PAUSED ), true )
				|| ! is_array( $c['settings'] ?? null ) || ! is_string( $c['content'] ?? null )
			) {
				continue;
			}
			$out[ $c['campaign'] ] = array(
				'campaign'       => $c['campaign'],
				'name'           => is_string( $c['name'] ?? null ) ? $c['name'] : '',
				'version'        => $c['version'],
				'state'          => $c['state'],
				'settings'       => $c['settings'],
				'content'        => $c['content'],
				'published_at'   => is_string( $c['published_at'] ?? null ) ? $c['published_at'] : '',
				'shows_reset_at' => is_string( $c['shows_reset_at'] ?? null ) ? $c['shows_reset_at'] : null,
				'media'          => is_array( $c['media'] ?? null ) ? array_values( $c['media'] ) : array(),
			);
		}//end foreach
		return $out;
	}

	/**
	 * What a site changes when it applies a package.
	 *
	 * Campaigns in the package are deployed in the state the hub set. Hub campaigns the
	 * site had that are no longer in the package are removed. While the site's URL
	 * differs from the one it was paired with, every hub campaign is held paused
	 * (BR-34); local campaigns are untouched.
	 *
	 * @param array<string, HubCampaign> $campaigns   Received campaigns, by ID.
	 * @param string[]                   $known       Hub campaign IDs the site had.
	 * @param bool                       $url_changed Whether the site's URL differs from its paired URL.
	 * @return array{deploy: array<string, HubCampaign>, remove: string[]}
	 */
	public static function plan( array $campaigns, array $known, bool $url_changed ): array {
		if ( $url_changed ) {
			$campaigns = array_map(
				static function ( array $c ): array {
					$c['state'] = self::PAUSED;
					return $c;
				},
				$campaigns
			);
		}
		return array(
			'deploy' => $campaigns,
			'remove' => array_values( array_diff( $known, array_keys( $campaigns ) ) ),
		);
	}

	/**
	 * Keys sorted at every level, so equal content hashes equally.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function sorted( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return array_map( array( self::class, 'sorted' ), $value );
	}
}
