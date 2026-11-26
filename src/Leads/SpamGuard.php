<?php
/**
 * Spam limits for lead submissions (BR-46, FR-24).
 *
 * Silently rejected: a filled hidden field, or a submission less than 2 seconds after
 * the form appeared. Rate limit: 5 submissions per form per source per 10 minutes,
 * where the source is a salted hash of the IP address, kept no longer than the window
 * and never stored with the lead.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Leads;

/**
 * Applies the limits and counts what they reject.
 */
final class SpamGuard {

	public const MIN_ELAPSED_MS = 2000;
	/**
	 * Submissions allowed per form per source per window. BR-46 marks this [confirm].
	 */
	public const RATE_LIMIT     = 5;
	public const WINDOW_SECONDS = 600;
	public const OPTION_COUNTS  = 'campaignbandit_spam_counts';

	/**
	 * View and conversion events allowed per source per window (05 §8).
	 *
	 * Generous on purpose: one visitor reading several pages with a campaign on each
	 * fires a handful of events a minute at most, so this is far above anything a
	 * browser does and far below what it takes to matter.
	 */
	public const EVENT_LIMIT = 120;

	/**
	 * Whether the submission looks automated: honeypot filled or too fast.
	 *
	 * @param string $honeypot   Value of the hidden field.
	 * @param int    $elapsed_ms Time from the form appearing to submit.
	 */
	public static function is_bot( string $honeypot, int $elapsed_ms ): bool {
		return '' !== trim( $honeypot ) || $elapsed_ms < self::MIN_ELAPSED_MS;
	}

	/**
	 * Record one submission for a source and campaign; false once over the limit.
	 *
	 * @param string $ip           Visitor IP address (hashed here, never kept).
	 * @param string $campaign_uid Campaign ULID (the form).
	 */
	public static function within_rate_limit( string $ip, string $campaign_uid ): bool {
		$key   = 'campaignbandit_rl_' . substr( hash_hmac( 'sha256', $ip . '|' . $campaign_uid, wp_salt( 'nonce' ) ), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::WINDOW_SECONDS );
		return true;
	}

	/**
	 * Whether this source may record another view or conversion.
	 *
	 * `/events` takes no authentication, because the visitor runtime has none to give.
	 * Without a limit, anyone can post events for any live campaign all day: the counts
	 * a site makes decisions on stop meaning anything, and because the page group
	 * travels with the event, each new value writes another row.
	 *
	 * The IP is hashed with a site salt, as it is for submissions, so nothing here keeps
	 * an address (05 §7).
	 *
	 * @param string $ip Visitor IP address.
	 */
	public static function within_event_limit( string $ip ): bool {
		$key   = 'campaignbandit_el_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= self::EVENT_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::WINDOW_SECONDS );
		return true;
	}

	/**
	 * Count a rejected submission for today; spam is reported as a count, never as a lead.
	 */
	public static function count(): void {
		$counts = get_option( self::OPTION_COUNTS, array() );
		$counts = is_array( $counts ) ? $counts : array();
		$today  = gmdate( 'Y-m-d' );

		$counts[ $today ] = (int) ( $counts[ $today ] ?? 0 ) + 1;
		// Keep 30 days of counts.
		$counts = array_slice( $counts, -30, null, true );
		update_option( self::OPTION_COUNTS, $counts, false );
	}
}
