<?php
/**
 * Settings › Roles (S8, BR-70): which WordPress users are a Campaign Bandit Manager or
 * Editor. Only Administrators can change this, and every change is recorded (BR-71).
 *
 * Campaign Bandit's roles sit alongside a user's own WordPress role: giving someone
 * Manager doesn't take away Author or Subscriber, and taking it back leaves that role
 * untouched. Administrators already have everything (Roles::grant_administrators), so
 * they are never listed here.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Security;

use CampaignBandit\Audit\AuditLog;
use WP_Error;
use WP_User;

/**
 * Reads and changes Campaign Bandit role assignments.
 */
final class RoleAssignments {

	/** Nobody's role: the user has neither Campaign Bandit role. */
	public const NONE = '';

	/**
	 * The roles that can be given, with their names.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		return array(
			self::NONE                 => __( 'No Campaign Bandit role', 'campaign-bandit' ),
			Capabilities::ROLE_MANAGER => __( 'Manager', 'campaign-bandit' ),
			Capabilities::ROLE_EDITOR  => __( 'Editor', 'campaign-bandit' ),
		);
	}

	/**
	 * The users who have a Campaign Bandit role, by display name.
	 *
	 * @return array<int, array{id: int, name: string, email: string, role: string}>
	 */
	public static function assigned(): array {
		$users = get_users(
			array(
				'role__in' => array( Capabilities::ROLE_MANAGER, Capabilities::ROLE_EDITOR ),
				'orderby'  => 'display_name',
				'number'   => 200,
			)
		);
		return array_map(
			static fn ( WP_User $user ): array => array(
				'id'    => $user->ID,
				'name'  => $user->display_name,
				'email' => $user->user_email,
				'role'  => self::role_of( $user ),
			),
			$users
		);
	}

	/**
	 * A user's Campaign Bandit role, or NONE. Manager wins if somehow both are set.
	 *
	 * @param WP_User $user User.
	 */
	public static function role_of( WP_User $user ): string {
		foreach ( array( Capabilities::ROLE_MANAGER, Capabilities::ROLE_EDITOR ) as $role ) {
			if ( in_array( $role, (array) $user->roles, true ) ) {
				return $role;
			}
		}
		return self::NONE;
	}

	/**
	 * Give a user a Campaign Bandit role, or take it away with NONE.
	 *
	 * @param int    $user_id User.
	 * @param string $role    One of the keys of `choices()`.
	 * @param string $channel Audit channel.
	 * @return true|WP_Error
	 */
	public static function set( int $user_id, string $role, string $channel = AuditLog::CHANNEL_SCREEN ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'campaignbandit_forbidden', __( 'Only Administrators can change roles.', 'campaign-bandit' ), array( 'status' => 403 ) );
		}
		if ( ! array_key_exists( $role, self::choices() ) ) {
			return new WP_Error( 'campaignbandit_role', __( 'That is not a Campaign Bandit role.', 'campaign-bandit' ), array( 'status' => 400 ) );
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'campaignbandit_user', __( 'No such user.', 'campaign-bandit' ), array( 'status' => 404 ) );
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return new WP_Error( 'campaignbandit_administrator', __( 'Administrators already have everything Campaign Bandit can do.', 'campaign-bandit' ), array( 'status' => 409 ) );
		}

		$before = self::role_of( $user );
		if ( $before === $role ) {
			return true;
		}
		foreach ( array( Capabilities::ROLE_MANAGER, Capabilities::ROLE_EDITOR ) as $existing ) {
			if ( in_array( $existing, (array) $user->roles, true ) ) {
				$user->remove_role( $existing );
			}
		}
		if ( self::NONE !== $role ) {
			$user->add_role( $role );
		}

		( new AuditLog() )->record(
			'settings.role_changed',
			'setting',
			(string) $user_id,
			$channel,
			array(
				'user'   => $user->user_login,
				'before' => '' === $before ? 'none' : $before,
				'after'  => '' === $role ? 'none' : $role,
			)
		);
		return true;
	}
}
