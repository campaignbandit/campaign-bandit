<?php
/**
 * When a campaign can be archived (glossary: campaign lifecycle; S3, S12). Pure, so it
 * can be tested without WordPress.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

use CampaignBandit\Publishing\Deployment;

/**
 * "Archive is allowed only when no deployment is live."
 */
final class ArchiveRule {

	public const LIVE      = 'live';
	public const SCHEDULED = 'scheduled';

	/**
	 * Why the campaign can't be archived, or null when it can.
	 *
	 * A paused deployment doesn't block it: archiving takes the campaign off those sites.
	 * A scheduled publish does, since it would make the campaign live later.
	 *
	 * @param array<int, string> $states    States of the campaign's deployments, on every site.
	 * @param bool               $scheduled Whether a publish is scheduled on any site.
	 */
	public static function refusal( array $states, bool $scheduled ): ?string {
		if ( in_array( Deployment::LIVE, $states, true ) ) {
			return self::LIVE;
		}
		return $scheduled || in_array( Deployment::SCHEDULED, $states, true ) ? self::SCHEDULED : null;
	}

	/**
	 * Name for a copy: "Name (copy)".
	 *
	 * @param string $name   Original name.
	 * @param string $suffix Translated "(copy)".
	 */
	public static function copy_name( string $name, string $suffix ): string {
		$name = trim( $name );
		return '' === $name ? $suffix : $name . ' ' . $suffix;
	}
}
