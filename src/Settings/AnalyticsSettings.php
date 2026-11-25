<?php
/**
 * Settings › Analytics events (FR-62): whether the runtime tells the site's own Tag
 * Manager or Google tag when a campaign is shown, closed or converts.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Settings;

/**
 * Reads the Analytics events setting.
 */
final class AnalyticsSettings {

	public const OPTION = 'campaignbandit_analytics_events';

	public const OFF       = 'off';
	public const DATALAYER = 'datalayer';
	public const BOTH      = 'both';

	public const MODES = array( self::OFF, self::DATALAYER, self::BOTH );

	/**
	 * The chosen mode. Default: Tag Manager and Google tag (product owner, Nov 12, 2026).
	 */
	public static function mode(): string {
		return self::clean( get_option( self::OPTION, self::BOTH ) );
	}

	/**
	 * A valid mode, or the default.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function clean( $value ): string {
		return in_array( $value, self::MODES, true ) ? $value : self::BOTH;
	}
}
