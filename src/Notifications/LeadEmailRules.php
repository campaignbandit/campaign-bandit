<?php
/**
 * Who gets the new-lead email (FR-56).
 *
 * WordPress-free, so the rules are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Notifications;

/**
 * New-lead email recipients.
 */
final class LeadEmailRules {

	public const MODES         = array( 'site', 'custom', 'off' );
	public const MAX_ADDRESSES = 10;

	/**
	 * The addresses an email goes to.
	 *
	 * - Settings › New leads off: nobody.
	 * - The campaign says Don't send: nobody.
	 * - A hub site with its own addresses: those, for leads from that site.
	 * - The campaign says Send to these addresses: those.
	 * - Otherwise: the addresses in Settings.
	 *
	 * @param bool                                $enabled  Settings › New leads is on.
	 * @param string[]                            $site     Addresses in Settings.
	 * @param array{mode?: string, to?: string[]} $campaign The campaign's Notifications setting.
	 * @param string[]                            $override A hub site's own addresses, or [].
	 * @return string[]
	 */
	public static function recipients( bool $enabled, array $site, array $campaign, array $override = array() ): array {
		$mode = in_array( $campaign['mode'] ?? null, self::MODES, true ) ? $campaign['mode'] : 'site';
		if ( ! $enabled || 'off' === $mode ) {
			return array();
		}
		$override = self::addresses( $override );
		if ( array() !== $override ) {
			return $override;
		}
		return 'custom' === $mode ? self::addresses( $campaign['to'] ?? array() ) : self::addresses( $site );
	}

	/**
	 * Valid, distinct addresses, at most ten. Accepts a list, or text with one address
	 * per line or separated by commas.
	 *
	 * @param mixed $raw Addresses.
	 * @return string[]
	 */
	public static function addresses( $raw ): array {
		$list = is_string( $raw ) ? (array) preg_split( '/[\s,;]+/', $raw ) : ( is_array( $raw ) ? $raw : array() );
		$out  = array();
		foreach ( $list as $address ) {
			$address = is_string( $address ) ? trim( $address ) : '';
			if ( '' !== $address && false !== filter_var( $address, FILTER_VALIDATE_EMAIL ) && ! isset( $out[ strtolower( $address ) ] ) ) {
				$out[ strtolower( $address ) ] = $address;
			}
		}
		return array_slice( array_values( $out ), 0, self::MAX_ADDRESSES );
	}

	/**
	 * A campaign's Notifications setting, normalised.
	 *
	 * @param mixed $raw Setting.
	 * @return array{mode: string, to: string[]}
	 */
	public static function campaign( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'mode' => in_array( $raw['mode'] ?? null, self::MODES, true ) ? $raw['mode'] : 'site',
			'to'   => self::addresses( $raw['to'] ?? array() ),
		);
	}
}
