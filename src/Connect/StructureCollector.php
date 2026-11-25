<?php
/**
 * Collects this site's public structure for its hub (BR-35, decision 0021). Only public
 * post types and taxonomies and published pages: no drafts, no private items, no
 * content, nothing about visitors.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

/**
 * Structure collector.
 */
final class StructureCollector {

	/** Cache of the collected structure, cleared when pages or terms change. */
	public const CACHE = 'campaignbandit_site_structure';

	/**
	 * Hook into WordPress: forget the cached structure when pages or terms change.
	 */
	public static function register(): void {
		$forget = static function (): void {
			delete_transient( self::CACHE );
		};
		foreach ( array( 'save_post_page', 'deleted_post', 'trashed_post', 'created_term', 'edited_term', 'delete_term', 'permalink_structure_changed' ) as $hook ) {
			add_action( $hook, $forget );
		}
	}

	/**
	 * The structure, collected at most twice a day unless something changed.
	 *
	 * @return array<string, mixed>
	 */
	public static function structure(): array {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$structure = SiteStructure::clean( self::collect() );
		set_transient( self::CACHE, $structure, 12 * HOUR_IN_SECONDS );
		return $structure;
	}

	/**
	 * A page's path from the site's home, as page rules use it: `/services/roofing`.
	 *
	 * @param int $id Page ID.
	 */
	public static function page_path( int $id ): string {
		$link = (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && str_starts_with( strtolower( $link ), strtolower( $home ) ) ) {
			$link = substr( $link, strlen( $home ) );
		}
		// Plain permalinks (?page_id=) have no path; fall back to the page's slugs.
		if ( '' === trim( $link, '/' ) && (int) get_option( 'page_on_front' ) !== $id ) {
			$link = (string) get_page_uri( $id );
		}
		return SiteStructure::path( $link ) ?? '/';
	}

	/**
	 * Read the structure from WordPress.
	 *
	 * @return array<string, mixed>
	 */
	private static function collect(): array {
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$post_types[] = array(
					'name'  => $type->name,
					'label' => $type->label,
				);
			}
		}

		$taxonomies = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( 'post_format' === $taxonomy->name ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => false,
					'number'     => SiteStructure::MAX_TERMS + 1,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			$terms = is_array( $terms ) ? $terms : array();
			$list  = array();
			foreach ( array_slice( $terms, 0, SiteStructure::MAX_TERMS ) as $term ) {
				if ( $term instanceof \WP_Term ) {
					$list[] = array(
						'slug'  => $term->slug,
						'name'  => $term->name,
						'count' => (int) $term->count,
					);
				}
			}
			$taxonomies[] = array(
				'name'       => $taxonomy->name,
				'label'      => $taxonomy->label,
				'post_types' => array_values( (array) $taxonomy->object_type ),
				'terms'      => $list,
				'partial'    => count( $terms ) > SiteStructure::MAX_TERMS,
			);
		}//end foreach

		$ids   = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => SiteStructure::MAX_PAGES + 1,
				'orderby'          => 'menu_order title',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		$pages = array();
		foreach ( array_slice( $ids, 0, SiteStructure::MAX_PAGES ) as $id ) {
			$id      = (int) $id;
			$pages[] = array(
				'id'     => $id,
				'title'  => get_the_title( $id ),
				'path'   => self::page_path( $id ),
				'parent' => (int) wp_get_post_parent_id( $id ),
			);
		}

		return array(
			'post_types'    => $post_types,
			'taxonomies'    => $taxonomies,
			'pages'         => $pages,
			'pages_partial' => count( $ids ) > SiteStructure::MAX_PAGES,
		);
	}
}
