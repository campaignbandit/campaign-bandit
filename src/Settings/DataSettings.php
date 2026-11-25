<?php
/**
 * Settings › Data (S8): lead retention (BR-50) and what happens on uninstall (FR-01).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Settings;

use CampaignBandit\Leads\Retention;

/**
 * Reads the Data settings.
 */
final class DataSettings {

	public const OPTION_DELETE_ON_UNINSTALL = 'campaignbandit_delete_data_on_uninstall';
	public const OPTION_RETENTION_DAYS      = 'campaignbandit_lead_retention_days';

	/**
	 * Whether uninstall should delete Campaign Bandit data. Default: keep (FR-01).
	 */
	public static function delete_on_uninstall(): bool {
		return (bool) get_option( self::OPTION_DELETE_ON_UNINSTALL, false );
	}

	/**
	 * Days leads are kept; 0 means until someone deletes them (BR-50).
	 */
	public static function retention_days(): int {
		return Retention::days( get_option( self::OPTION_RETENTION_DAYS, Retention::DEFAULT_DAYS ) );
	}
}
