<?php
/**
 * Settings › New leads (FR-56): whether new-lead emails go out, and to whom.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Settings;

use CampaignBandit\Notifications\LeadEmailRules;

/**
 * Reads the New leads settings.
 */
final class LeadEmailSettings {

	public const OPTION_ENABLED = 'campaignbandit_lead_emails';
	public const OPTION_TO      = 'campaignbandit_lead_email_to';
	public const OPTION_FAILED  = 'campaignbandit_lead_email_failed';

	/**
	 * Whether new-lead emails go out. Default: on.
	 */
	public static function enabled(): bool {
		return '0' !== (string) get_option( self::OPTION_ENABLED, '1' );
	}

	/**
	 * The addresses in Settings. Default: the site's admin email.
	 *
	 * @return string[]
	 */
	public static function addresses(): array {
		$saved = get_option( self::OPTION_TO, null );
		return LeadEmailRules::addresses( null === $saved ? (string) get_option( 'admin_email' ) : $saved );
	}

	/**
	 * When the last email failed, and why, or null.
	 *
	 * @return array{at: int, error: string}|null
	 */
	public static function last_failure(): ?array {
		$failed = get_option( self::OPTION_FAILED, null );
		return is_array( $failed ) && isset( $failed['at'] ) ? array(
			'at'    => (int) $failed['at'],
			'error' => (string) ( $failed['error'] ?? '' ),
		) : null;
	}
}
