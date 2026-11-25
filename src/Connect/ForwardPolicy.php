<?php
/**
 * Site → hub lead forwarding schedule (BR-41): at once, then increasing gaps, for up to
 * 7 days; after that the lead is "Not forwarded" and stays on the site.
 *
 * WordPress-free, so the schedule is unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

/**
 * When to try forwarding a lead next.
 */
final class ForwardPolicy {

	public const NONE          = 'none';
	public const PENDING       = 'pending';
	public const FORWARDED     = 'forwarded';
	public const NOT_FORWARDED = 'not_forwarded';

	/**
	 * Forwarding stops this long after the lead was submitted.
	 */
	public const LIMIT = 7 * 86400;

	/**
	 * Gaps after each failed attempt, in seconds; the last repeats.
	 */
	public const GAPS = array( 60, 300, 1800, 7200, 21600 );

	/**
	 * Leads sent in one request.
	 */
	public const BATCH = 50;

	/**
	 * The next attempt after a failure, or null when the lead is out of time.
	 *
	 * @param int $attempts     Failed attempts so far, including this one.
	 * @param int $submitted_at When the lead was submitted (Unix time).
	 * @param int $now          Current Unix time.
	 */
	public static function next( int $attempts, int $submitted_at, int $now ): ?int {
		$gap  = self::GAPS[ min( max( $attempts, 1 ), count( self::GAPS ) ) - 1 ];
		$next = $now + $gap;
		return $next >= $submitted_at + self::LIMIT ? null : $next;
	}

	/**
	 * Whether a lead waiting this long has run out of time.
	 *
	 * @param int $submitted_at When the lead was submitted (Unix time).
	 * @param int $now          Current Unix time.
	 */
	public static function expired( int $submitted_at, int $now ): bool {
		return $now >= $submitted_at + self::LIMIT;
	}

	/**
	 * Whether the hub accepts a lead from a site in this state: a connected site
	 * always; a removed or disconnected one for 7 days, so queued leads still arrive
	 * (BR-41, FR-11).
	 *
	 * @param bool     $active     Whether the site is still connected.
	 * @param int|null $removed_at When it was removed (Unix time), if it was.
	 * @param int      $now        Current Unix time.
	 */
	public static function hub_accepts( bool $active, ?int $removed_at, int $now ): bool {
		return $active || ( null !== $removed_at && $now < $removed_at + self::LIMIT );
	}
}
