<?php
/**
 * Settings › Cookie consent (BR-48): follow the site's consent tool automatically,
 * always wait for consent, or never wait.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Settings;

/**
 * Reads the Cookie consent setting.
 */
final class ConsentSettings {

	public const OPTION = 'campaignbandit_cookie_consent';

	public const AUTO   = 'auto';
	public const ALWAYS = 'always';
	public const NEVER  = 'never';

	public const MODES = array( self::AUTO, self::ALWAYS, self::NEVER );

	/**
	 * The chosen mode. Default: Automatic (product owner, Nov 12, 2026).
	 */
	public static function mode(): string {
		return self::clean( get_option( self::OPTION, self::AUTO ) );
	}

	/**
	 * A valid mode, or the default.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function clean( $value ): string {
		return in_array( $value, self::MODES, true ) ? $value : self::AUTO;
	}
}
