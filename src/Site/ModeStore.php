<?php
/**
 * Reads and changes this install's site mode.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Site;

use DomainException;

/**
 * Stores the site mode in an option and enforces allowed transitions.
 */
final class ModeStore {

	public const OPTION = 'campaignbandit_site_mode';

	/**
	 * Current mode. Defaults to standalone.
	 */
	public static function current(): SiteMode {
		return SiteMode::tryFrom( (string) get_option( self::OPTION, SiteMode::Standalone->value ) ) ?? SiteMode::Standalone;
	}

	/**
	 * Move to another mode.
	 *
	 * @param SiteMode $to Target mode.
	 * @throws DomainException When the transition is not allowed.
	 */
	public static function change_to( SiteMode $to ): void {
		$from = self::current();
		if ( $from === $to ) {
			return;
		}
		if ( ! $from->can_become( $to ) ) {
			// Developer-facing exception message built from enum values, not output to a page.
			throw new DomainException( sprintf( 'A %s site cannot become a %s site.', $from->value, $to->value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		update_option( self::OPTION, $to->value );

		/**
		 * Fires after the site mode changes.
		 *
		 * @param SiteMode $to   New mode.
		 * @param SiteMode $from Previous mode.
		 */
		do_action( 'campaignbandit_site_mode_changed', $to, $from );
	}
}
