<?php
/**
 * Campaign Bandit capabilities and which role holds each (BR-70, decision 0007).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Security;

/**
 * Capability names and the role matrix. WordPress-free so it can be unit-tested.
 */
final class Capabilities {

	public const EDIT_CAMPAIGNS      = 'campaignbandit_edit_campaigns';
	public const PUBLISH_CAMPAIGNS   = 'campaignbandit_publish_campaigns';
	public const VIEW_LEADS          = 'campaignbandit_view_leads';
	public const MANAGE_LEADS        = 'campaignbandit_manage_leads';
	public const MANAGE_SITES        = 'campaignbandit_manage_sites';
	public const MANAGE_DESTINATIONS = 'campaignbandit_manage_destinations';
	public const MANAGE_SECRETS      = 'campaignbandit_manage_secrets';
	public const MANAGE_SETTINGS     = 'campaignbandit_manage_settings';

	public const ROLE_MANAGER = 'campaignbandit_manager';
	public const ROLE_EDITOR  = 'campaignbandit_editor';

	/**
	 * Every Campaign Bandit capability. Administrators hold all of them.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::EDIT_CAMPAIGNS,
			self::PUBLISH_CAMPAIGNS,
			self::VIEW_LEADS,
			self::MANAGE_LEADS,
			self::MANAGE_SITES,
			self::MANAGE_DESTINATIONS,
			self::MANAGE_SECRETS,
			self::MANAGE_SETTINGS,
		);
	}

	/**
	 * Capabilities held by a Campaign Bandit role.
	 *
	 * @param string $role Role name.
	 * @return string[]
	 */
	public static function for_role( string $role ): array {
		return match ( $role ) {
			self::ROLE_MANAGER => array(
				self::EDIT_CAMPAIGNS,
				self::PUBLISH_CAMPAIGNS,
				self::VIEW_LEADS,
				self::MANAGE_LEADS,
				self::MANAGE_SITES,
				self::MANAGE_DESTINATIONS,
			),
			self::ROLE_EDITOR  => array(
				self::EDIT_CAMPAIGNS,
			),
			default            => array(),
		};
	}
}
