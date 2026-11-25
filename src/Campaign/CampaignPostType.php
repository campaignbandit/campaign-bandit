<?php
/**
 * The campaign post type: one post is a campaign and its one draft (BR-20, decision 0006).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

use CampaignBandit\Security\Capabilities;
use CampaignBandit\Support\Ulid;
use WP_Post;

/**
 * Registers the post type and gives every campaign a ULID.
 */
final class CampaignPostType {

	public const POST_TYPE     = 'cbandit_campaign';
	public const META_UID      = '_campaignbandit_uid';
	public const REST_UID      = 'campaign_uid';
	public const META_SETTINGS = '_campaignbandit_settings';
	public const ARCHIVED      = 'cbandit_archived';

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( self::class, 'ensure_uid' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( self::class, 'keep_as_draft' ), 10, 2 );
		add_action( 'before_delete_post', array( self::class, 'remove_deployments' ), 10, 2 );
		add_filter( 'map_meta_cap', array( self::class, 'archived_read_only' ), 10, 4 );
		// "New campaign" is on the Campaigns list, the Dashboard and the Overview; the menu
		// doesn't need its own item. The screen itself stays reachable.
		add_action(
			'admin_menu',
			static function (): void {
				remove_submenu_page( 'edit.php?post_type=' . self::POST_TYPE, 'post-new.php?post_type=' . self::POST_TYPE );
			},
			99
		);
	}

	/**
	 * The campaign post is always the draft (BR-20): what is live comes from versions,
	 * never from the post. Keeping it a draft also keeps it out of public REST responses.
	 *
	 * @param array<string, mixed> $data    Post data about to be saved.
	 * @param array<string, mixed> $postarr Raw post array.
	 * @return array<string, mixed>
	 */
	public static function keep_as_draft( array $data, array $postarr ): array {
		unset( $postarr );
		if ( self::POST_TYPE === ( $data['post_type'] ?? '' ) && in_array( $data['post_status'] ?? '', array( 'publish', 'future', 'private', 'pending' ), true ) ) {
			$data['post_status'] = 'draft';
		}
		return $data;
	}

	/**
	 * An archived campaign can't be edited until it is restored (S12).
	 *
	 * @param string[] $caps    Primitive capabilities.
	 * @param string   $cap     Capability checked.
	 * @param int      $user_id User.
	 * @param mixed[]  $args    Arguments: the post ID first.
	 * @return string[]
	 */
	public static function archived_read_only( array $caps, string $cap, int $user_id, array $args ): array {
		unset( $user_id );
		if ( 'edit_post' !== $cap || ! isset( $args[0] ) ) {
			return $caps;
		}
		$post = get_post( (int) $args[0] );
		return $post instanceof WP_Post && self::POST_TYPE === $post->post_type && self::ARCHIVED === $post->post_status ? array( 'do_not_allow' ) : $caps;
	}

	/**
	 * Register the post type and its meta.
	 */
	public static function register_post_type(): void {
		register_post_status(
			self::ARCHIVED,
			array(
				'label'                     => _x( 'Archived', 'campaign status', 'campaign-bandit' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of campaigns. */
				'label_count'               => _n_noop( 'Archived <span class="count">(%s)</span>', 'Archived <span class="count">(%s)</span>', 'campaign-bandit' ),
			)
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Campaigns', 'campaign-bandit' ),
					'singular_name' => __( 'Campaign', 'campaign-bandit' ),
					/**
					 * Filters the admin menu's name. A white-labelled connected site
					 * shows the agency's name instead (FR-72, decision 0031).
					 *
					 * @param string $name Menu name.
					 */
					'menu_name'     => (string) apply_filters( 'campaignbandit_menu_name', __( 'Campaign Bandit', 'campaign-bandit' ) ),
					'all_items'     => __( 'Campaigns', 'campaign-bandit' ),
					'add_new_item'  => __( 'New campaign', 'campaign-bandit' ),
					'edit_item'     => __( 'Edit campaign', 'campaign-bandit' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_position'       => 58,
				'menu_icon'           => 'dashicons-megaphone',
				'show_in_rest'        => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'editor', 'revisions', 'custom-fields' ),
				'map_meta_cap'        => true,
				'capability_type'     => array( 'campaignbandit_campaign', 'campaignbandit_campaigns' ),
				'capabilities'        => self::capability_map(),
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_UID,
			array(
				'type'          => 'string',
				'single'        => true,
				// Not REST meta: the block editor sends the whole meta object back on
				// save, and a new campaign's empty ID would fail its first save. The ID
				// is exposed read-only as the campaign_uid field instead.
				'show_in_rest'  => false,
				'auth_callback' => '__return_false',
			)
		);

		register_rest_field(
			self::POST_TYPE,
			self::REST_UID,
			array(
				'get_callback' => static fn ( array $post ): string => (string) get_post_meta( (int) $post['id'], self::META_UID, true ),
				'schema'       => array(
					'description' => __( 'Campaign ID (ULID). Set on the first save.', 'campaign-bandit' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_SETTINGS,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '{}',
				'show_in_rest'      => true,
				'sanitize_callback' => static fn ( $value ): string => CampaignSettings::from_json( (string) $value )->to_json(),
				'auth_callback'     => static fn (): bool => current_user_can( Capabilities::EDIT_CAMPAIGNS ),
			)
		);
	}

	/**
	 * Map the post type's primitive capabilities to Campaign Bandit capabilities (decision 0007).
	 * Deleting needs the publish capability, so Editors can't delete (FR-70).
	 *
	 * @return array<string, string>
	 */
	public static function capability_map(): array {
		return array(
			'edit_posts'             => Capabilities::EDIT_CAMPAIGNS,
			'edit_others_posts'      => Capabilities::EDIT_CAMPAIGNS,
			'edit_published_posts'   => Capabilities::EDIT_CAMPAIGNS,
			'edit_private_posts'     => Capabilities::EDIT_CAMPAIGNS,
			'read_private_posts'     => Capabilities::EDIT_CAMPAIGNS,
			'create_posts'           => Capabilities::EDIT_CAMPAIGNS,
			'publish_posts'          => Capabilities::PUBLISH_CAMPAIGNS,
			'delete_posts'           => Capabilities::PUBLISH_CAMPAIGNS,
			'delete_others_posts'    => Capabilities::PUBLISH_CAMPAIGNS,
			'delete_published_posts' => Capabilities::PUBLISH_CAMPAIGNS,
			'delete_private_posts'   => Capabilities::PUBLISH_CAMPAIGNS,
			'read'                   => 'read',
		);
	}

	/**
	 * Deleting a campaign takes it off every site (deletion needs the publish capability, FR-70).
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 */
	public static function remove_deployments( int $post_id, $post = null ): void {
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return;
		}
		$uid = (string) get_post_meta( $post_id, self::META_UID, true );
		if ( '' !== $uid ) {
			\CampaignBandit\Plugin::publishing()->remove_deleted( $uid );
		}
	}

	/**
	 * Give a campaign its ULID the first time it is saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function ensure_uid( int $post_id, WP_Post $post ): void {
		if ( 'auto-draft' === $post->post_status || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( '' === (string) get_post_meta( $post_id, self::META_UID, true ) ) {
			update_post_meta( $post_id, self::META_UID, Ulid::generate() );
		}
	}
}
