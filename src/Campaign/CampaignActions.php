<?php
/**
 * Campaign operations from the More menu on Campaign detail (S12) and the Campaigns
 * list (S3): duplicate, archive and restore. Shared by screens and, later, REST and
 * abilities (decision 0004).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

use CampaignBandit\Audit\AuditLog;
use CampaignBandit\Publishing\DeploymentRepository;
use CampaignBandit\Security\Capabilities;
use WP_Error;

/**
 * Duplicate, archive, restore.
 */
final class CampaignActions {

	/**
	 * Create the service.
	 *
	 * @param CampaignService      $campaigns   Campaigns.
	 * @param DeploymentRepository $deployments Deployments.
	 * @param AuditLog             $audit       Audit trail.
	 */
	public function __construct(
		private readonly CampaignService $campaigns,
		private readonly DeploymentRepository $deployments,
		private readonly AuditLog $audit,
	) {}

	/**
	 * Copy a campaign's draft into a new campaign: same content and settings, a new ID,
	 * no versions, no deployments and no site settings.
	 *
	 * @param string $uid     Campaign ULID.
	 * @param string $into    Where the copy goes, for an add-on to read (the hub: a client ID); '' for alongside the original.
	 * @param string $channel Audit channel.
	 * @return Campaign|WP_Error The copy.
	 */
	public function duplicate( string $uid, string $into = '', string $channel = AuditLog::CHANNEL_SCREEN ) {
		if ( ! current_user_can( Capabilities::EDIT_CAMPAIGNS ) ) {
			return self::forbidden();
		}
		$campaign = $this->campaigns->get( $uid );
		$post     = null === $campaign ? null : get_post( $campaign->post_id );
		if ( null === $campaign || null === $post ) {
			return self::not_found();
		}
		$post_id = wp_insert_post(
			array(
				'post_type'    => CampaignPostType::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => ArchiveRule::copy_name( $campaign->name, __( '(copy)', 'campaign-bandit' ) ),
				'post_content' => wp_slash( $post->post_content ),
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( $post_id, CampaignPostType::META_SETTINGS, wp_slash( (string) get_post_meta( $post->ID, CampaignPostType::META_SETTINGS, true ) ) );
		$copy_uid = (string) get_post_meta( $post_id, CampaignPostType::META_UID, true );
		$copy     = '' === $copy_uid ? null : $this->campaigns->get( $copy_uid );
		if ( null === $copy ) {
			return self::not_found();
		}
		$this->audit->record( 'campaign.duplicated', 'campaign', $copy->uid, $channel, array( 'from' => $uid ) );

		/**
		 * Fires after a campaign was duplicated. The hub puts the copy in the chosen client.
		 *
		 * @param string $copy_uid The copy.
		 * @param string $uid      The original.
		 * @param string $into     Where the copy goes, or ''.
		 */
		do_action( 'campaignbandit_campaign_duplicated', $copy->uid, $uid, $into );
		return $copy;
	}

	/**
	 * Why a campaign can't be archived now, or null.
	 *
	 * @param string $uid Campaign ULID.
	 */
	public function archive_refusal( string $uid ): ?string {
		$states = array_map( static fn ( $deployment ): string => $deployment->state, $this->deployments->for_campaign( $uid ) );
		return ArchiveRule::refusal( $states, $this->deployments->has_schedule( $uid ) );
	}

	/**
	 * Archive a campaign that is live on no site. It leaves the sites where it was
	 * paused, drops out of the Campaigns list (it stays under Archived) and can't be
	 * edited or published until it is restored.
	 *
	 * @param string $uid     Campaign ULID.
	 * @param string $channel Audit channel.
	 * @return true|WP_Error
	 */
	public function archive( string $uid, string $channel = AuditLog::CHANNEL_SCREEN ) {
		if ( ! current_user_can( Capabilities::PUBLISH_CAMPAIGNS ) ) {
			return self::forbidden();
		}
		$campaign = $this->campaigns->get( $uid );
		if ( null === $campaign ) {
			return self::not_found();
		}
		if ( $campaign->archived ) {
			return true;
		}
		$refusal = $this->archive_refusal( $uid );
		if ( null !== $refusal ) {
			return new WP_Error(
				'campaignbandit_archive_' . $refusal,
				ArchiveRule::LIVE === $refusal
					? __( 'This campaign is live. Pause it on every site before archiving it.', 'campaign-bandit' )
					: __( 'This campaign has a scheduled publish. Cancel it before archiving the campaign.', 'campaign-bandit' ),
				array( 'status' => 409 )
			);
		}
		$sites = array_map( static fn ( $deployment ): int => $deployment->site_id, $this->deployments->for_campaign( $uid ) );
		$this->deployments->remove_campaign( $uid );
		self::set_status( $campaign->post_id, CampaignPostType::ARCHIVED );
		$this->audit->record( 'campaign.archived', 'campaign', $uid, $channel );
		if ( array() !== $sites ) {
			do_action( 'campaignbandit_deployments_changed' );

			/**
			 * Fires after an archived campaign was taken off the sites where it was paused.
			 *
			 * @param string $uid   Campaign ULID.
			 * @param int[]  $sites Site IDs (0 for this install).
			 */
			do_action( 'campaignbandit_campaign_archived', $uid, $sites );
		}
		return true;
	}

	/**
	 * Bring an archived campaign back as a draft. It is on no site until it is published again.
	 *
	 * @param string $uid     Campaign ULID.
	 * @param string $channel Audit channel.
	 * @return true|WP_Error
	 */
	public function restore( string $uid, string $channel = AuditLog::CHANNEL_SCREEN ) {
		if ( ! current_user_can( Capabilities::PUBLISH_CAMPAIGNS ) ) {
			return self::forbidden();
		}
		$campaign = $this->campaigns->get( $uid );
		if ( null === $campaign ) {
			return self::not_found();
		}
		if ( $campaign->archived ) {
			self::set_status( $campaign->post_id, 'draft' );
			$this->audit->record( 'campaign.restored', 'campaign', $uid, $channel );
		}
		return true;
	}

	/**
	 * Change only a campaign post's status. Saving the whole post would run its content
	 * through the content filters again, which can reformat block markup and make the
	 * draft look changed against its latest version.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  New status.
	 */
	private static function set_status( int $post_id, string $status ): void {
		global $wpdb;
		$post = get_post( $post_id );
		if ( null === $post ) {
			return;
		}
		$wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $post_id );
		wp_transition_post_status( $status, $post->post_status, get_post( $post_id ) );
	}

	/**
	 * Not allowed.
	 */
	private static function forbidden(): WP_Error {
		return new WP_Error( 'campaignbandit_forbidden', __( 'You are not allowed to do that.', 'campaign-bandit' ), array( 'status' => 403 ) );
	}

	/**
	 * No such campaign.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'campaignbandit_not_found', __( 'No campaign has that ID.', 'campaign-bandit' ), array( 'status' => 404 ) );
	}
}
