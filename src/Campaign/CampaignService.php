<?php
/**
 * Campaign operations shared by admin screens, REST and abilities (decision 0004).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

use WP_Post;
use WP_Query;

/**
 * Reads campaigns. Write operations arrive with the editor and publishing (M1, M3).
 */
final class CampaignService {

	public const MAX_PER_PAGE = 100;

	/**
	 * List campaigns that are not archived, most recently edited first.
	 *
	 * @param int $page     Page number, from 1.
	 * @param int $per_page Campaigns per page, 1 to 100.
	 * @return Campaign[]
	 */
	public function list( int $page = 1, int $per_page = 20 ): array {
		$query = new WP_Query(
			array(
				'post_type'      => CampaignPostType::POST_TYPE,
				'post_status'    => array( 'draft', 'pending', 'publish', 'private', 'future' ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'paged'          => max( 1, $page ),
				'posts_per_page' => min( self::MAX_PER_PAGE, max( 1, $per_page ) ),
				'no_found_rows'  => true,
			)
		);

		$campaigns = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$campaign = $this->from_post( $post );
				if ( null !== $campaign ) {
					$campaigns[] = $campaign;
				}
			}
		}
		return $campaigns;
	}

	/**
	 * Find one campaign by its ULID.
	 *
	 * @param string $uid Campaign ULID.
	 */
	public function get( string $uid ): ?Campaign {
		$posts = get_posts(
			array(
				'post_type'      => CampaignPostType::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_key'       => CampaignPostType::META_UID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $uid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return isset( $posts[0] ) && $posts[0] instanceof WP_Post ? $this->from_post( $posts[0] ) : null;
	}

	/**
	 * Build the read model from a post. Campaigns not yet saved once have no ULID and are skipped.
	 *
	 * @param WP_Post $post Campaign post.
	 */
	private function from_post( WP_Post $post ): ?Campaign {
		$uid = (string) get_post_meta( $post->ID, CampaignPostType::META_UID, true );
		if ( '' === $uid ) {
			return null;
		}
		return new Campaign(
			$uid,
			$post->ID,
			$post->post_title,
			// Campaign posts are drafts, whose GMT dates can be empty; this falls back to the local date.
			gmdate( DATE_RFC3339, (int) get_post_timestamp( $post, 'modified' ) ),
			CampaignPostType::ARCHIVED === $post->post_status,
		);
	}
}
