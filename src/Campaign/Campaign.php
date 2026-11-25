<?php
/**
 * A campaign as the rest of the plugin sees it.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

/**
 * Read model for a campaign. More fields arrive with the editor (M1).
 */
final class Campaign {

	/**
	 * Create a campaign read model.
	 *
	 * @param string $uid         Campaign ULID.
	 * @param int    $post_id     Post ID on this install.
	 * @param string $name        Campaign name.
	 * @param string $modified_at Last edit, ISO 8601 UTC.
	 * @param bool   $archived    Archived (lifecycle: draft only, published, archived).
	 */
	public function __construct(
		public readonly string $uid,
		public readonly int $post_id,
		public readonly string $name,
		public readonly string $modified_at,
		public readonly bool $archived = false,
	) {}

	/**
	 * Array form for REST responses and abilities.
	 *
	 * @return array{uid: string, name: string, modified_at: string, archived: bool}
	 */
	public function to_array(): array {
		return array(
			'uid'         => $this->uid,
			'name'        => $this->name,
			'modified_at' => $this->modified_at,
			'archived'    => $this->archived,
		);
	}
}
