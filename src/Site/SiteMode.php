<?php
/**
 * Site modes (FR-03): every install is in exactly one.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Site;

/**
 * Standalone (Free or Pro), hub, or connected site.
 */
enum SiteMode: string {

	case Standalone = 'standalone';
	case Hub        = 'hub';
	case Connected  = 'connected';

	/**
	 * Whether this mode can move to another.
	 *
	 * - Standalone → Connected: pairing to a hub (FR-10).
	 * - Connected → Standalone: disconnecting from either side (FR-11).
	 * - Standalone → Hub: an Agency or Agency Plus licence (FR-02).
	 * - Hub → Standalone: the hub licence is removed.
	 * A hub never pairs to another hub, and a connected site never becomes a hub without disconnecting first.
	 *
	 * @param SiteMode $to Target mode.
	 */
	public function can_become( SiteMode $to ): bool {
		return match ( $this ) {
			self::Standalone => self::Connected === $to || self::Hub === $to,
			self::Connected  => self::Standalone === $to,
			self::Hub        => self::Standalone === $to,
		};
	}
}
