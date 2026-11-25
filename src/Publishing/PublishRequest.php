<?php
/**
 * "Request publish" for Editors (S4, BR-70). An Editor writes drafts but never
 * publishes, so instead of a disabled button they ask the people who can.
 *
 * Everyone who may publish is emailed once per request, and the request goes in the
 * audit trail (BR-71), so it is on the record even if the mail never arrives.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Publishing;

use CampaignBandit\Audit\AuditLog;
use CampaignBandit\Campaign\Campaign;
use CampaignBandit\Security\Capabilities;
use WP_Error;
use WP_User;

/**
 * Asks the Managers to publish a draft.
 */
final class PublishRequest {

	/**
	 * Ask for a campaign to be published.
	 *
	 * @param Campaign $campaign Campaign.
	 * @param string   $note     What the Editor wants to say, or ''.
	 * @param string   $channel  Audit channel.
	 * @return array{notified: int}|WP_Error
	 */
	public static function send( Campaign $campaign, string $note = '', string $channel = AuditLog::CHANNEL_SCREEN ) {
		if ( ! current_user_can( Capabilities::EDIT_CAMPAIGNS ) ) {
			return new WP_Error( 'campaignbandit_forbidden', __( 'You are not allowed to edit campaigns.', 'campaign-bandit' ), array( 'status' => 403 ) );
		}
		$note      = mb_substr( trim( wp_strip_all_tags( $note ) ), 0, 500 );
		$asked_by  = wp_get_current_user();
		$addresses = self::publishers();

		( new AuditLog() )->record(
			'campaign.publish_requested',
			'campaign',
			$campaign->uid,
			$channel,
			array(
				'note'     => $note,
				'notified' => count( $addresses ),
			)
		);

		if ( array() !== $addresses ) {
			wp_mail( $addresses, self::subject( $campaign ), self::body( $campaign, $asked_by, $note ) );
		}
		return array( 'notified' => count( $addresses ) );
	}

	/**
	 * Everyone who may publish on this site: Managers and Administrators (BR-70).
	 *
	 * @return string[] Email addresses.
	 */
	public static function publishers(): array {
		$users     = get_users(
			array(
				'role__in' => array( Capabilities::ROLE_MANAGER, 'administrator' ),
				'number'   => 50,
			)
		);
		$addresses = array();
		foreach ( $users as $user ) {
			if ( $user instanceof WP_User && user_can( $user, Capabilities::PUBLISH_CAMPAIGNS ) && is_email( $user->user_email ) ) {
				$addresses[] = $user->user_email;
			}
		}
		return array_values( array_unique( $addresses ) );
	}

	/**
	 * The email's subject.
	 *
	 * @param Campaign $campaign Campaign.
	 */
	private static function subject( Campaign $campaign ): string {
		return sprintf(
			/* translators: %s: campaign name. */
			__( '[Campaign Bandit] Ready to publish: %s', 'campaign-bandit' ),
			$campaign->name
		);
	}

	/**
	 * The email's body.
	 *
	 * @param Campaign $campaign Campaign.
	 * @param WP_User  $asked_by Who asked.
	 * @param string   $note     Their note, or ''.
	 */
	private static function body( Campaign $campaign, WP_User $asked_by, string $note ): string {
		$body = sprintf(
			/* translators: 1: person's name, 2: campaign name. */
			__( '%1$s has finished a draft of "%2$s" and would like it published.', 'campaign-bandit' ),
			$asked_by->display_name,
			$campaign->name
		);
		if ( '' !== $note ) {
			$body .= "\n\n" . $note;
		}
		return $body . "\n\n" . (string) get_edit_post_link( $campaign->post_id, 'url' );
	}
}
