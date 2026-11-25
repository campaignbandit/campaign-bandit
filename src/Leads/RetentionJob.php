<?php
/**
 * The daily lead cleanup (BR-50), on WP-Cron.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Leads;

use CampaignBandit\Plugin;
use CampaignBandit\Settings\DataSettings;

/**
 * Schedules and runs the retention cleanup.
 */
final class RetentionJob {

	public const HOOK = 'campaignbandit_daily_cleanup';

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( 'campaignbandit_deactivated', array( self::class, 'unschedule' ) );
	}

	/**
	 * Make sure the daily run is scheduled.
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Remove the schedule (on deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Delete leads past the retention period. An action callback, so it returns
	 * nothing; LeadManager::delete_expired() returns the count.
	 */
	public static function run(): void {
		Plugin::lead_manager()->delete_expired( DataSettings::retention_days(), time() );
		// The audit trail is kept for 365 days (BR-71).
		( new \CampaignBandit\Audit\AuditLog() )->prune( time() );
	}
}
