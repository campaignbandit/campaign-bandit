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
	 * Display name for a role, stored as English on purpose.
	 *
	 * WordPress keeps a role's display name in the database as it was given, so
	 * translating it here would freeze whatever language was active at install and leave
	 * it wrong after the site changes language. Core stores its own role names in
	 * English for that reason and translates them on display with
	 * `translate_user_role()`.
	 *
	 * Translating here also called `__()` during `plugins_loaded`, before `init`, which
	 * is what WordPress 6.7 and later warn about with
	 * `_load_textdomain_just_in_time`. `install()` runs from `Plugin::boot()` whenever
	 * the schema changes, so a fresh install produced that notice.
	 *
	 * Our own screens never show these strings: Settings › Roles has its own labels in
	 * `RoleAssignments::labels()`, translated where they are rendered.
	 *
	 * @param string $role Role name.
	 */
	private static function label( string $role ): string {
		return Capabilities::ROLE_MANAGER === $role
			? 'Campaign Bandit Manager'
			: 'Campaign Bandit Editor';
	}
}
