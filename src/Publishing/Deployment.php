<?php
/**
 * One version of one campaign on one site (glossary: Deployment).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Publishing;

/**
 * Read model for a deployment row. `site_id` 0 is this install (decision 0006).
 */
final class Deployment {

	public const LIVE      = 'live';
	public const PAUSED    = 'paused';
	public const SCHEDULED = 'scheduled';
	public const STOPPED   = 'stopped';
	public const REMOVED   = 'removed';

	/**
	 * Create a deployment.
	 *
	 * @param int         $id             Row ID.
	 * @param string      $campaign_uid   Campaign ULID.
	 * @param int         $site_id        Site, 0 for this install.
	 * @param int         $version_id     Version row ID.
	 * @param int         $version_number Version number.
	 * @param string      $state          One of the state constants.
	 * @param string|null $published_at   When this version went live here, UTC.
	 * @param string|null $shows_reset_at "Show again to visitors who already saw it" point, UTC.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $campaign_uid,
		public readonly int $site_id,
		public readonly int $version_id,
		public readonly int $version_number,
		public readonly string $state,
		public readonly ?string $published_at,
		public readonly ?string $shows_reset_at,
	) {}
}
