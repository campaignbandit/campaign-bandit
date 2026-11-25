<?php
/**
 * Plugin uninstall.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Lifecycle;

use CampaignBandit\Campaign\CampaignPostType;
use CampaignBandit\Database\Migrator;
use CampaignBandit\Database\Schema;
use CampaignBandit\Security\Roles;
use CampaignBandit\Settings\DataSettings;

/**
 * Applies the saved uninstall choice (FR-01): keep by default, or delete everything.
 */
final class Uninstaller {

	/**
	 * Run on uninstall.
	 */
	public static function uninstall(): void {
		if ( ! DataSettings::delete_on_uninstall() ) {
			return;
		}

		$campaign_ids = get_posts(
			array(
				'post_type'      => CampaignPostType::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $campaign_ids as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}

		Migrator::drop( new Schema() );
		Roles::uninstall();

		global $wpdb;
		$keep = array( $wpdb->esc_like( 'campaignbandit_pro_' ) . '%' );
		if ( false !== get_option( 'campaignbandit_pro_db_version', false ) ) {
			// The add-on's data is still here; its own uninstall needs the choice.
			$keep[] = DataSettings::OPTION_DELETE_ON_UNINSTALL;
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name <> %s",
				$wpdb->esc_like( 'campaignbandit_' ) . '%',
				$keep[0],
				$keep[1] ?? ''
			)
		);
	}
}
