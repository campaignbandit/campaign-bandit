<?php
/**
 * Registers the Campaign Bandit roles and grants administrators every capability.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Security;

/**
 * WordPress glue for decision 0007.
 */
final class Roles {

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant_administrators' ), 10, 1 );
	}

	/**
	 * Add or refresh the two Campaign Bandit roles. Runs on activation and schema upgrades.
	 */
	public static function install(): void {
		foreach ( array( Capabilities::ROLE_MANAGER, Capabilities::ROLE_EDITOR ) as $role ) {
			remove_role( $role );
			add_role( $role, self::label( $role ), array_fill_keys( Capabilities::for_role( $role ), true ) );
		}
	}

	/**
	 * Remove the roles. Used on uninstall when the user chose to delete data.
	 */
	public static function uninstall(): void {
		remove_role( Capabilities::ROLE_MANAGER );
		remove_role( Capabilities::ROLE_EDITOR );
	}

	/**
	 * Give anyone who can manage options every Campaign Bandit capability.
	 *
	 * @param array<string, bool> $allcaps Capabilities the user has.
	 * @return array<string, bool>
	 */
	public static function grant_administrators( array $allcaps ): array {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			foreach ( Capabilities::all() as $cap ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}

	/**
	 * Display name for a role.
	 *
	 * @param string $role Role name.
	 */
	private static function label( string $role ): string {
		return Capabilities::ROLE_MANAGER === $role
			? __( 'Campaign Bandit Manager', 'campaign-bandit' )
			: __( 'Campaign Bandit Editor', 'campaign-bandit' );
	}
}
