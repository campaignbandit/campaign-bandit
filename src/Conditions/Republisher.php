<?php
/**
 * "Republish N campaigns" (BR-07, S11): after a saved condition changes, publish the
 * live campaigns that run its older rules. Each gets a new version made from the
 * version it runs, with the saved condition refreshed, not from its draft, which may
 * hold other changes not ready to go out. The new version goes to the sites where the
 * older one is live; paused sites keep what they have.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Conditions;

use CampaignBandit\Audit\AuditLog;
use CampaignBandit\Publishing\Deployment;
use CampaignBandit\Publishing\DeploymentRepository;
use CampaignBandit\Publishing\PublishingService;
use CampaignBandit\Publishing\VersionRepository;
use WP_Error;

/**
 * Republisher.
 */
final class Republisher {

	/**
	 * Republish every live campaign running older rules of a saved condition.
	 *
	 * @param string $condition_uid Saved condition ID.
	 * @return array{done: string[], failed: array<string, string>} Campaign names.
	 */
	public static function run( string $condition_uid ): array {
		$store = new SavedConditionStore();
		$saved = $store->get( $condition_uid );
		$out   = array(
			'done'   => array(),
			'failed' => array(),
		);
		if ( null === $saved ) {
			return $out;
		}
		$versions    = new VersionRepository();
		$deployments = new DeploymentRepository();
		$user        = wp_get_current_user();
		foreach ( $store->usage( $condition_uid ) as $use ) {
			if ( ! $use['outdated'] ) {
				continue;
			}
			// Sites where an older copy is live, by the version they run.
			$by_version = array();
			foreach ( $deployments->for_campaign( (string) $use['campaign'] ) as $deployment ) {
				if ( Deployment::LIVE !== $deployment->state ) {
					continue;
				}
				$version = $versions->find( $deployment->version_id );
				if ( null !== $version && SavedConditions::outdated( (array) ( $version->settings->to_array()['condition'] ?? array() ), $condition_uid, $saved['condition'] ) ) {
					$by_version[ $version->number ]['version'] = $version;
					$by_version[ $version->number ]['sites'][] = $deployment->site_id;
				}
			}
			$result = true;
			foreach ( $by_version as $group ) {
				/* translators: %s: saved condition name. */
				$summary = sprintf( __( 'Updated saved condition “%s”', 'campaign-bandit' ), $saved['name'] );
				try {
					$next = $versions->insert_next(
						(string) $use['campaign'],
						$group['version']->content,
						PublishingService::with_saved_conditions( $group['version']->settings ),
						$summary,
						$user->ID,
						$user->display_name
					);
				} catch ( \RuntimeException $e ) {
					$result = new WP_Error( 'campaignbandit_republish', __( 'The new version could not be saved.', 'campaign-bandit' ) );
					break;
				}
				$result = self::deploy( (string) $use['campaign'], $next, $group['sites'], $summary );
				if ( $result instanceof WP_Error ) {
					break;
				}
			}//end foreach
			if ( $result instanceof WP_Error ) {
				$out['failed'][ (string) $use['name'] ] = $result->get_error_message();
			} else {
				$out['done'][] = (string) $use['name'];
			}
		}//end foreach
		return $out;
	}

	/**
	 * Put a new version live on the sites that ran the older one.
	 *
	 * @param string                             $uid     Campaign ULID.
	 * @param \CampaignBandit\Publishing\Version $version New version.
	 * @param int[]                              $sites   Sites (0: this install).
	 * @param string                             $summary What changed.
	 * @return true|WP_Error
	 */
	private static function deploy( string $uid, $version, array $sites, string $summary ) {
		/**
		 * Filters how a republished version reaches its sites. The hub publishes it to
		 * them through its own publish flow (FR-41).
		 *
		 * @param true|WP_Error|null $result  Null to deploy here.
		 * @param string             $uid     Campaign ULID.
		 * @param int                $number  Version number.
		 * @param int[]              $sites   Sites.
		 * @param string             $summary What changed.
		 */
		$result = apply_filters( 'campaignbandit_republish', null, $uid, $version->number, $sites, $summary );
		if ( null !== $result ) {
			return $result;
		}
		( new DeploymentRepository() )->deploy( $version, 0, false );
		( new AuditLog() )->record( 'campaign.published', 'campaign', $uid, AuditLog::CHANNEL_SCREEN, array( 'version' => $version->number ) );
		do_action( 'campaignbandit_deployments_changed' );
		unset( $sites );
		return true;
	}
}
