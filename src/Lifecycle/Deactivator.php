<?php
/**
 * Plugin deactivation.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Lifecycle;

/**
 * Deactivation deletes nothing (FR-01). Visitor output stops because the plugin no longer loads.
 */
final class Deactivator {

	/**
	 * Run on deactivation.
	 */
	public static function deactivate(): void {
		/**
		 * Fires when Campaign Bandit is deactivated, so scheduled work can be unscheduled.
		 */
		do_action( 'campaignbandit_deactivated' );
	}
}
