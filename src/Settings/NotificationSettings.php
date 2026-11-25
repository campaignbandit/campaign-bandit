<?php
/**
 * Settings › Notifications (S8): who gets Campaign Bandit's critical emails, and which
 * events send them. Today that is the stale-sync alert (BR-31), which only a hub sends;
 * the setting lives in the free plugin because Settings does.
 *
 * New-lead emails have their own setting (FR-56, New leads): those go to whoever handles
 * leads, these to whoever keeps the hub running.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Settings;

use CampaignBandit\Notifications\LeadEmailRules;

/**
 * Who hears about problems.
 */
final class NotificationSettings {

	public const OPTION_TO     = 'campaignbandit_notify_to';
	public const OPTION_EVENTS = 'campaignbandit_notify_events';

	/** A site has stopped syncing for 24 hours (BR-31). */
	public const EVENT_STALE_SYNC = 'stale_sync';

	public const EVENTS = array( self::EVENT_STALE_SYNC );

	/**
	 * Who to email. Empty means the site's admin address, so an install that has never
	 * been to Settings still hears about a hub going quiet.
	 *
	 * @return string[]
	 */
	public static function addresses(): array {
		$saved = LeadEmailRules::addresses( get_option( self::OPTION_TO, array() ) );
		if ( array() !== $saved ) {
			return $saved;
		}
		$admin = (string) get_option( 'admin_email' );
		return is_email( $admin ) ? array( $admin ) : array();
	}

	/**
	 * Whether an event sends an email. Unset means on: an alert nobody asked to silence
	 * is worth sending.
	 *
	 * @param string $event One of EVENTS.
	 */
	public static function sends( string $event ): bool {
		if ( ! in_array( $event, self::EVENTS, true ) ) {
			return false;
		}
		$saved = get_option( self::OPTION_EVENTS, null );
		return is_array( $saved ) ? in_array( $event, self::clean( $saved ), true ) : true;
	}

	/**
	 * Clean a chosen set of events.
	 *
	 * @param mixed $raw Candidate.
	 * @return string[]
	 */
	public static function clean( $raw ): array {
		return array_values( array_intersect( self::EVENTS, is_array( $raw ) ? array_map( 'strval', $raw ) : array() ) );
	}
}
