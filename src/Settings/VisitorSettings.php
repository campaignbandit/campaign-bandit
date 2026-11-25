<?php
/**
 * Settings › Visitors (S8): what a new campaign starts with — how often a visitor sees
 * it (BR-11), how long a dismissal lasts (BR-12), whether converting hides it (BR-13) —
 * and the pages every campaign stays off (BR-01).
 *
 * These are defaults, not rules: a campaign that has set its own keeps it. They reach a
 * new campaign through `default_post_metadata`, so a campaign nobody has configured
 * reads the site's defaults, and the moment it saves it carries its own.
 *
 * Configurable on Pro and above; Free shows each campaign once every 7 days (BR-63).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Settings;

use CampaignBandit\Campaign\CampaignPostType;
use CampaignBandit\Campaign\CampaignSettings;
use CampaignBandit\Licensing\Plan;

/**
 * The site's defaults for new campaigns.
 */
final class VisitorSettings {

	public const OPTION_FREQUENCY_TYPE = 'campaignbandit_default_frequency_type';
	public const OPTION_FREQUENCY_DAYS = 'campaignbandit_default_frequency_days';
	public const OPTION_DISMISS_DAYS   = 'campaignbandit_default_dismiss_days';
	public const OPTION_SUPPRESS       = 'campaignbandit_default_suppress_conversion';

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_filter( 'default_post_metadata', array( self::class, 'default_settings' ), 10, 4 );
	}

	/**
	 * A campaign that has never saved its settings reads the site's defaults.
	 *
	 * @param mixed  $value   The default value.
	 * @param int    $post_id Post.
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether one value was asked for.
	 * @return mixed
	 */
	public static function default_settings( $value, $post_id, $key, $single = true ) {
		if ( CampaignPostType::META_SETTINGS !== $key || CampaignPostType::POST_TYPE !== get_post_type( $post_id ) ) {
			return $value;
		}
		$defaults = self::defaults();
		if ( array() === $defaults ) {
			return $value;
		}
		$json = (string) wp_json_encode( $defaults );
		// get_post_meta() with $single false expects a list, as WordPress stores them.
		return $single ? $json : array( $json );
	}

	/**
	 * The settings a new campaign starts with. Free keeps the fixed frequency (BR-63).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		if ( ! Plan::current()->includes( Plan::Pro ) ) {
			return array();
		}
		return array(
			'frequency'                 => array(
				'type' => self::frequency_type(),
				'days' => self::frequency_days(),
			),
			'dismiss_days'              => self::dismiss_days(),
			'suppress_after_conversion' => self::suppress_after_conversion(),
		);
	}

	/**
	 * How often a visitor sees a campaign (BR-11).
	 */
	public static function frequency_type(): string {
		$value = (string) get_option( self::OPTION_FREQUENCY_TYPE, 'every_n_days' );
		return in_array( $value, CampaignSettings::FREQUENCIES, true ) ? $value : 'every_n_days';
	}

	/**
	 * Days between shows, when the frequency counts days (BR-11).
	 */
	public static function frequency_days(): int {
		return self::clamp( get_option( self::OPTION_FREQUENCY_DAYS, 7 ), 1, 365, 7 );
	}

	/**
	 * Days a dismissal lasts (BR-12).
	 */
	public static function dismiss_days(): int {
		return self::clamp( get_option( self::OPTION_DISMISS_DAYS, 30 ), 0, 365, 30 );
	}

	/**
	 * Whether converting hides the campaign from that visitor (BR-13).
	 */
	public static function suppress_after_conversion(): bool {
		return '0' !== (string) get_option( self::OPTION_SUPPRESS, '1' );
	}

	/**
	 * A whole number in range, or the fallback.
	 *
	 * @param mixed $value    Candidate.
	 * @param int   $min      Lowest.
	 * @param int   $max      Highest.
	 * @param int   $fallback Value when it isn't a number in range.
	 */
	public static function clamp( $value, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) $value ) );
	}
}
