<?php
/**
 * Applies a package from the hub on a connected site (decision 0014, BR-30, BR-34).
 *
 * Hub versions are stored as ordinary versions and deployed on this site (site 0), so
 * the package file, leads and metrics treat them like local campaigns. The last
 * package is kept, so a URL change can pause hub campaigns without the hub.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

use CampaignBandit\Campaign\CampaignSettings;
use CampaignBandit\Publishing\Deployment;
use CampaignBandit\Publishing\DeploymentRepository;
use CampaignBandit\Publishing\PublishingService;
use CampaignBandit\Publishing\VersionRepository;

/**
 * Stores hub campaigns and keeps their deployments in step.
 */
final class PackageApplier {

	/**
	 * The last package's campaigns (non-autoloaded: holds block markup).
	 */
	public const OPTION_PACKAGE = 'campaignbandit_hub_package';

	/**
	 * Hub campaigns on this site: ID => {name, version, state}. Small and autoloaded,
	 * for names in the package file and the hub-managed list (S9).
	 */
	public const OPTION_CAMPAIGNS = 'campaignbandit_hub_campaigns';

	/**
	 * Apply an opened package.
	 *
	 * @param array<string, mixed> $payload Payload from SignedPackage::open().
	 * @return int Images that could not be copied (their campaigns keep the version they had).
	 */
	public static function apply( array $payload ): int {
		if ( true === ( $payload['removed'] ?? false ) ) {
			// The hub disconnected this site (FR-11): hub campaigns stop, the site is standalone.
			update_option( self::OPTION_PACKAGE, array(), false );
			self::reconcile();
			Connector::forget();
			return 0;
		}

		$fields = array( 'last_sequence' => (int) $payload['sequence'] );
		// Promoted to live, or marked as staging, on the hub (BR-33).
		if ( in_array( $payload['connection'] ?? null, array( 'live', 'staging' ), true ) ) {
			$fields['connection'] = $payload['connection'];
		}
		// The hub confirmed a move to this URL (BR-34): hub campaigns resume here.
		if ( is_string( $payload['paired_url'] ?? null ) && Connection::current_url() === $payload['paired_url'] ) {
			$fields['paired_url'] = $payload['paired_url'];
		}
		Connection::update( $fields );

		$previous  = HubPackage::campaigns( get_option( self::OPTION_PACKAGE, array() ) );
		$campaigns = HubPackage::campaigns( $payload['campaigns'] ?? array() );
		$uploads   = is_string( $payload['uploads'] ?? null ) ? $payload['uploads'] : '';

		// The agency's own name and logo, when the hub white labels this site (FR-72).
		// The logo is copied here like a campaign's images, so nothing points at the hub.
		$label  = $payload['white_label'] ?? null;
		$logo   = WhiteLabel::logo_item( $label );
		$copied = null === $logo ? array( 'map' => array() ) : MediaCopier::copy( array( $logo ), $uploads );
		WhiteLabel::apply( $label, (string) ( $copied['map'][ $logo['url'] ?? '' ] ?? '' ) );
		$failed = 0;
		foreach ( $campaigns as $uid => $campaign ) {
			// Images first: a version switches only once all its images are here (decision 0002).
			$copy = MediaCopier::copy( $campaign['media'], $uploads );
			if ( array() !== $copy['failed'] ) {
				$failed += count( $copy['failed'] );
				if ( isset( $previous[ $uid ] ) ) {
					$campaigns[ $uid ] = $previous[ $uid ];
				} else {
					unset( $campaigns[ $uid ] );
				}
				continue;
			}
			$campaigns[ $uid ]['content'] = MediaUrls::rewrite( $campaign['content'], $copy['map'] );
		}

		update_option( self::OPTION_PACKAGE, array_values( $campaigns ), false );
		Connection::update( array( 'media_failed' => $failed ) );
		self::reconcile();
		self::remove_unused_media( $campaigns );
		return $failed;
	}

	/**
	 * Remove image copies no stored hub campaign uses.
	 *
	 * @param array<string, array<string, mixed>> $campaigns Stored campaigns.
	 */
	private static function remove_unused_media( array $campaigns ): void {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}
		$base = set_url_scheme( $uploads['baseurl'] ) . '/' . MediaCopier::DIR;
		$keep = array();
		foreach ( $campaigns as $campaign ) {
			foreach ( MediaUrls::find( (string) $campaign['content'], $base ) as $url ) {
				$keep[] = basename( $url );
			}
		}
		// The white label logo is a copy too, and no campaign's content mentions it.
		$logo = WhiteLabel::logo();
		if ( '' !== $logo ) {
			$keep[] = basename( $logo );
		}
		MediaCopier::remove_unused( $keep );
	}

	/**
	 * Bring deployments in line with the last package and the URL check.
	 */
	public static function reconcile(): void {
		$stored      = get_option( self::OPTION_PACKAGE, array() );
		$campaigns   = HubPackage::campaigns( $stored );
		$connection  = Connection::get();
		$url_changed = null !== $connection && Connection::current_url() !== $connection['paired_url'];
		$plan        = HubPackage::plan( $campaigns, array_keys( self::known() ), $url_changed );

		$versions    = new VersionRepository();
		$deployments = new DeploymentRepository();
		$known       = array();

		foreach ( $plan['deploy'] as $uid => $c ) {
			$version  = $versions->store_received( $uid, $c['version'], $c['content'], new CampaignSettings( $c['settings'] ), '' !== $c['published_at'] ? $c['published_at'] : gmdate( 'Y-m-d H:i:s' ) );
			$existing = $deployments->find( $uid, 0 );
			$reset    = null !== $c['shows_reset_at'] && ( null === $existing || (string) $existing->shows_reset_at < $c['shows_reset_at'] );
			$current  = $existing;
			if ( null === $existing || $existing->version_number !== $c['version'] || $reset || ! in_array( $existing->state, array( Deployment::LIVE, Deployment::PAUSED ), true ) ) {
				$current = $deployments->deploy( $version, 0, $reset );
			}
			if ( null !== $current && $current->state !== $c['state'] ) {
				$deployments->set_state( $current, $c['state'] );
			}
			$known[ $uid ] = array(
				'name'    => $c['name'],
				'version' => $c['version'],
				'state'   => $c['state'],
			);
		}

		foreach ( $plan['remove'] as $uid ) {
			$existing = $deployments->find( $uid, 0 );
			if ( null !== $existing ) {
				$deployments->set_state( $existing, Deployment::REMOVED );
			}
		}

		update_option( self::OPTION_CAMPAIGNS, $known, true );

		/** This action is documented in src/Publishing/PublishingService.php */
		do_action( 'campaignbandit_deployments_changed' );
	}

	/**
	 * Hub campaigns on this site.
	 *
	 * @return array<string, array{name: string, version: int, state: string}>
	 */
	public static function known(): array {
		$known = get_option( self::OPTION_CAMPAIGNS, array() );
		return is_array( $known ) ? $known : array();
	}

	/**
	 * A hub campaign's name, or ''.
	 *
	 * @param string $uid Campaign ID.
	 */
	public static function name( string $uid ): string {
		return (string) ( self::known()[ $uid ]['name'] ?? '' );
	}

	/**
	 * Whether a campaign is managed by the hub.
	 *
	 * @param string $uid Campaign ID.
	 */
	public static function is_hub_campaign( string $uid ): bool {
		return isset( self::known()[ $uid ] );
	}
}
