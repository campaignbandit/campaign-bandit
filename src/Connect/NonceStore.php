<?php
/**
 * Single-use request nonces (decision 0014). A nonce is remembered a little longer
 * than the signing window, so a captured request cannot be replayed.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

/**
 * Remembers seen nonces in non-autoloaded options; add_option() fails on a name that
 * exists, so two racing replays cannot both succeed.
 */
final class NonceStore {

	private const PREFIX = 'campaignbandit_nonce_';
	private const HOOK   = 'campaignbandit_nonce_cleanup';

	/**
	 * Hook the hourly clean-up.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'cleanup' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Use a nonce once.
	 *
	 * @param string $sender Who signed it (site ID, or "hub").
	 * @param string $nonce  Nonce from the request.
	 * @return bool False when the nonce was seen before.
	 */
	public static function use( string $sender, string $nonce ): bool {
		return add_option( self::PREFIX . md5( $sender . '|' . $nonce ), (string) time(), '', false );
	}

	/**
	 * Forget nonces older than twice the signing window.
	 */
	public static function cleanup(): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d',
				$wpdb->options,
				$wpdb->esc_like( self::PREFIX ) . '%',
				time() - 2 * RequestSigner::WINDOW
			)
		);
	}
}
