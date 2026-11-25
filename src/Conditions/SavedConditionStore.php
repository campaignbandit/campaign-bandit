<?php
/**
 * The targeting library (BR-07, FR-36, decision 0024): saved conditions as a private
 * post type, with their ID, rules and category in post meta.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Conditions;

use CampaignBandit\Campaign\CampaignPostType;
use CampaignBandit\Campaign\CampaignSettings;
use CampaignBandit\Publishing\Deployment;
use CampaignBandit\Publishing\DeploymentRepository;
use CampaignBandit\Publishing\VersionRepository;
use CampaignBandit\Support\Ulid;
use WP_Error;
use WP_Post;

/**
 * Saved conditions.
 *
 * @phpstan-type Saved array{uid: string, name: string, category: string, condition: array<string, mixed>, post_id: int, modified_at: string, modified_by: string, workspace: int, sites: array<int, array<string, mixed>>}
 */
final class SavedConditionStore {

	public const POST_TYPE     = 'cbandit_condition';
	public const META_UID      = '_campaignbandit_uid';
	public const META_RULES    = '_campaignbandit_condition';
	public const META_CATEGORY = '_campaignbandit_category';

	/** On a hub: the client it belongs to, 0 for every client (BR-07). */
	public const META_WORKSPACE = '_campaignbandit_workspace';

	/** On a hub: its own definition for some sites, by site ID (requirements/08 P4). */
	public const META_SITES = '_campaignbandit_sites';

	/**
	 * Register the post type.
	 */
	public static function register(): void {
		add_action(
			'init',
			static function (): void {
				register_post_type(
					self::POST_TYPE,
					array(
						'label'        => __( 'Saved conditions', 'campaign-bandit' ),
						'public'       => false,
						'show_ui'      => false,
						'show_in_rest' => false,
						'rewrite'      => false,
						'query_var'    => false,
						'supports'     => array( 'title', 'author', 'revisions' ),
					)
				);
			}
		);
	}

	/**
	 * Every saved condition, by name.
	 *
	 * @return array<int, Saved>
	 */
	public function all(): array {
		$posts = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		return array_values( array_filter( array_map( array( $this, 'from_post' ), $posts ) ) );
	}

	/**
	 * Saved conditions by ID, as SavedConditions::refresh() takes them.
	 *
	 * @return array<string, array{name: string, condition: array<string, mixed>}>
	 */
	public function library(): array {
		$out = array();
		foreach ( $this->all() as $saved ) {
			$out[ $saved['uid'] ] = array(
				'name'      => $saved['name'],
				'condition' => $saved['condition'],
			);
		}
		return $out;
	}

	/**
	 * One saved condition.
	 *
	 * @param string $uid ID.
	 * @return Saved|null
	 */
	public function get( string $uid ): ?array {
		if ( ! Ulid::is_valid( $uid ) ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => 1,
				'meta_key'    => self::META_UID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one indexed lookup.
				'meta_value'  => $uid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one indexed lookup.
			)
		);
		return array() === $posts ? null : $this->from_post( $posts[0] );
	}

	/**
	 * Create or update one.
	 *
	 * @param string|null          $uid       ID to update, or null for a new one.
	 * @param string               $name      Name.
	 * @param string               $category  Category, or ''.
	 * @param array<string, mixed> $condition Rules.
	 * @return Saved|WP_Error
	 */
	public function save( ?string $uid, string $name, string $category, array $condition ) {
		$name = trim( wp_strip_all_tags( $name ) );
		if ( '' === $name ) {
			return new WP_Error( 'campaignbandit_condition_name', __( 'Give the saved condition a name.', 'campaign-bandit' ), array( 'status' => 400 ) );
		}
		$rules = SavedConditions::definition( $condition );
		if ( array() === $rules['groups'] ) {
			return new WP_Error( 'campaignbandit_condition_empty', __( 'Add at least one rule.', 'campaign-bandit' ), array( 'status' => 400 ) );
		}
		$existing = null === $uid ? null : $this->get( $uid );
		if ( null !== $uid && null === $existing ) {
			return new WP_Error( 'campaignbandit_not_found', __( 'No saved condition has that ID.', 'campaign-bandit' ), array( 'status' => 404 ) );
		}
		$post_id = wp_insert_post(
			array(
				'ID'          => null === $existing ? 0 : $existing['post_id'],
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => mb_substr( $name, 0, 100 ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$uid = $existing['uid'] ?? Ulid::generate();
		update_post_meta( $post_id, self::META_UID, $uid );
		update_post_meta( $post_id, self::META_RULES, wp_slash( (string) wp_json_encode( $rules ) ) );
		update_post_meta( $post_id, self::META_CATEGORY, mb_substr( trim( wp_strip_all_tags( $category ) ), 0, 60 ) );
		return $this->get( $uid ) ?? new WP_Error( 'campaignbandit_condition_save', __( 'The saved condition could not be saved.', 'campaign-bandit' ) );
	}

	/**
	 * On a hub: which client it belongs to, 0 for every client (BR-07).
	 *
	 * @param string $uid       ID.
	 * @param int    $workspace Workspace, or 0.
	 */
	public function set_workspace( string $uid, int $workspace ): void {
		$saved = $this->get( $uid );
		if ( null !== $saved ) {
			update_post_meta( $saved['post_id'], self::META_WORKSPACE, max( 0, $workspace ) );
		}
	}

	/**
	 * On a hub: a site's own definition, or null to use the saved condition's (08 P4).
	 *
	 * @param string                    $uid        ID.
	 * @param int                       $site_id    Site.
	 * @param array<string, mixed>|null $definition Rules, or null to remove.
	 * @return true|WP_Error
	 */
	public function set_site_definition( string $uid, int $site_id, ?array $definition ) {
		$saved = $this->get( $uid );
		if ( null === $saved || $site_id < 1 ) {
			return new WP_Error( 'campaignbandit_not_found', __( 'No saved condition has that ID.', 'campaign-bandit' ), array( 'status' => 404 ) );
		}
		$sites = $saved['sites'];
		if ( null === $definition ) {
			unset( $sites[ $site_id ] );
		} else {
			$rules = SavedConditions::definition( $definition );
			if ( array() === $rules['groups'] ) {
				return new WP_Error( 'campaignbandit_condition_empty', __( 'Add at least one rule.', 'campaign-bandit' ), array( 'status' => 400 ) );
			}
			$sites[ $site_id ] = $rules;
		}
		update_post_meta( $saved['post_id'], self::META_SITES, wp_slash( (string) wp_json_encode( (object) $sites ) ) );
		return true;
	}

	/**
	 * Delete one, only when no campaign uses it (BR-07).
	 *
	 * @param string $uid ID.
	 * @return true|WP_Error
	 */
	public function delete( string $uid ) {
		$saved = $this->get( $uid );
		if ( null === $saved ) {
			return new WP_Error( 'campaignbandit_not_found', __( 'No saved condition has that ID.', 'campaign-bandit' ), array( 'status' => 404 ) );
		}
		if ( array() !== $this->usage( $uid ) ) {
			return new WP_Error( 'campaignbandit_condition_in_use', __( 'Campaigns use this saved condition. Take it out of them first.', 'campaign-bandit' ), array( 'status' => 409 ) );
		}
		wp_delete_post( $saved['post_id'], true );
		return true;
	}

	/**
	 * Categories in use, for the category field.
	 *
	 * @return string[]
	 */
	public function categories(): array {
		$categories = array_filter( array_unique( array_column( $this->all(), 'category' ) ) );
		sort( $categories );
		return $categories;
	}

	/**
	 * Campaigns that use a saved condition, in their draft or in a version that is live
	 * or paused somewhere (Check usage, S11). Archived campaigns are left out.
	 *
	 * @param string $uid Saved condition ID.
	 * @return array<int, array{campaign: string, name: string, post_id: int, draft: bool, live: bool, outdated: bool, sites: int[]}>
	 */
	public function usage( string $uid ): array {
		$saved    = $this->get( $uid );
		$versions = new VersionRepository();
		$repo     = new DeploymentRepository();
		$out      = array();
		$posts    = get_posts(
			array(
				'post_type'   => CampaignPostType::POST_TYPE,
				'post_status' => array( 'draft', 'publish', 'pending', 'future', 'private' ),
				'numberposts' => -1,
			)
		);
		foreach ( $posts as $post ) {
			$campaign_uid = (string) get_post_meta( $post->ID, CampaignPostType::META_UID, true );
			if ( '' === $campaign_uid ) {
				continue;
			}
			$draft    = CampaignSettings::from_json( (string) get_post_meta( $post->ID, CampaignPostType::META_SETTINGS, true ) )->to_array();
			$in_draft = SavedConditions::uses( (array) ( $draft['condition'] ?? array() ), $uid );
			$sites    = array();
			$outdated = false;
			foreach ( $repo->for_campaign( $campaign_uid ) as $deployment ) {
				if ( ! in_array( $deployment->state, array( Deployment::LIVE, Deployment::PAUSED ), true ) ) {
					continue;
				}
				$version   = $versions->find( $deployment->version_id );
				$condition = null === $version ? array() : (array) ( $version->settings->to_array()['condition'] ?? array() );
				if ( SavedConditions::uses( $condition, $uid ) ) {
					$sites[] = $deployment->site_id;
					if ( null !== $saved && SavedConditions::outdated( $condition, $uid, $saved['condition'] ) ) {
						$outdated = true;
					}
				}
			}
			if ( $in_draft || array() !== $sites ) {
				$out[] = array(
					'campaign' => $campaign_uid,
					'name'     => $post->post_title,
					'post_id'  => $post->ID,
					'draft'    => $in_draft,
					'live'     => array() !== $sites,
					'outdated' => $outdated,
					'sites'    => array_values( array_unique( $sites ) ),
				);
			}
		}//end foreach
		return $out;
	}

	/**
	 * A post as a saved condition.
	 *
	 * @param WP_Post $post Post.
	 * @return Saved|null
	 */
	private function from_post( WP_Post $post ): ?array {
		$uid = (string) get_post_meta( $post->ID, self::META_UID, true );
		if ( ! Ulid::is_valid( $uid ) ) {
			return null;
		}
		$rules  = json_decode( (string) get_post_meta( $post->ID, self::META_RULES, true ), true );
		$author = get_userdata( (int) get_post_field( 'post_author', $post->ID ) );
		$sites  = array();
		$raw    = json_decode( (string) get_post_meta( $post->ID, self::META_SITES, true ), true );
		foreach ( is_array( $raw ) ? $raw : array() as $site_id => $definition ) {
			if ( (int) $site_id > 0 ) {
				$sites[ (int) $site_id ] = SavedConditions::definition( $definition );
			}
		}
		return array(
			'uid'         => $uid,
			'name'        => $post->post_title,
			'category'    => (string) get_post_meta( $post->ID, self::META_CATEGORY, true ),
			'condition'   => SavedConditions::definition( $rules ),
			'post_id'     => $post->ID,
			'modified_at' => $post->post_modified_gmt,
			'modified_by' => false === $author ? '' : $author->display_name,
			'workspace'   => (int) get_post_meta( $post->ID, self::META_WORKSPACE, true ),
			'sites'       => $sites,
		);
	}
}
