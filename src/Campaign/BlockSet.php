<?php
/**
 * The launch block set (vision › Campaign authoring, FR-21).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

/**
 * Which blocks a campaign may contain. Row, Stack and Grid are variations of Group.
 */
final class BlockSet {

	/**
	 * WordPress core Layout and Content blocks allowed in campaigns, with their inner blocks.
	 *
	 * @return string[]
	 */
	public static function core(): array {
		return array(
			// Layout.
			'core/group',
			'core/columns',
			'core/column',
			'core/cover',
			'core/spacer',
			'core/separator',
			// Content.
			'core/heading',
			'core/paragraph',
			'core/list',
			'core/list-item',
			'core/image',
			'core/buttons',
			'core/button',
			'core/details',
		);
	}

	/**
	 * Campaign Bandit's own blocks allowed inside campaigns (the form blocks).
	 *
	 * @return string[]
	 */
	public static function own(): array {
		return array(
			'campaign-bandit/form',
			'campaign-bandit/text',
			'campaign-bandit/long-text',
			'campaign-bandit/email',
			'campaign-bandit/phone',
			'campaign-bandit/date',
			'campaign-bandit/choice',
			'campaign-bandit/consent',
			'campaign-bandit/hidden',
			'campaign-bandit/success',
		);
	}

	/**
	 * Everything allowed in a campaign.
	 *
	 * @return string[]
	 */
	public static function allowed(): array {
		return array_merge( self::core(), self::own() );
	}

	/**
	 * Campaign Bandit blocks that may appear in normal post and page editors: only the Inline block.
	 *
	 * @return string[]
	 */
	public static function outside_campaigns(): array {
		return array( 'campaign-bandit/inline' );
	}
}
