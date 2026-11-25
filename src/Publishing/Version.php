<?php
/**
 * A frozen, numbered snapshot of a campaign (BR-20).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Publishing;

use CampaignBandit\Campaign\CampaignSettings;

/**
 * Read model for a version row. Versions never change once written.
 */
final class Version {

	/**
	 * Create a version.
	 *
	 * @param int              $id             Row ID on this install.
	 * @param string           $campaign_uid   Campaign ULID.
	 * @param int              $number         Version number (1, 2…).
	 * @param string           $content        Block markup.
	 * @param CampaignSettings $settings       Settings as published.
	 * @param string           $change_summary What changed.
	 * @param int              $published_by   User ID.
	 * @param string           $published_at   UTC "Y-m-d H:i:s".
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $campaign_uid,
		public readonly int $number,
		public readonly string $content,
		public readonly CampaignSettings $settings,
		public readonly string $change_summary,
		public readonly int $published_by,
		public readonly string $published_at,
	) {}
}
