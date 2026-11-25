<?php
/**
 * Settings › Country targeting (BR-04, decision 0027): where a visitor's country comes
 * from. Headers only (the default), or also the DB-IP Lite database, which the site
 * downloads and keeps up to date once an admin turns it on.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Settings;

/**
 * Reads the Country targeting setting.
 */
final class CountrySettings {

	public const OPTION = 'campaignbandit_country_source';

	/** Only a country header from the host or CDN (no download). */
	public const HEADERS = 'headers';

	/** That header, then the DB-IP Lite database. */
	public const DATABASE = 'database';

	public const MODES = array( self::HEADERS, self::DATABASE );

	/**
	 * The chosen source. Default: headers only, so nothing is downloaded until an
	 * admin asks for it (BR-04).
	 */
	public static function mode(): string {
		return self::clean( get_option( self::OPTION, self::HEADERS ) );
	}

	/**
	 * Whether the site downloads and uses the DB-IP Lite database.
	 */
	public static function database_enabled(): bool {
		return self::DATABASE === self::mode();
	}

	/**
	 * A valid mode, or the default.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function clean( $value ): string {
		return in_array( $value, self::MODES, true ) ? $value : self::HEADERS;
	}
}
