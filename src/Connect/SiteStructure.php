<?php
/**
 * A connected site's public structure (BR-35, decision 0021): post types, taxonomies
 * with their terms, and the page tree. The site reports it at sync; the hub checks page
 * rules against it and offers it to pick from.
 *
 * WordPress-free, so the shape is unit-tested on both sides.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

/**
 * Structure shape, limits and hash.
 *
 * @phpstan-type Structure array{post_types: array<int, array{name: string, label: string}>, taxonomies: array<int, array{name: string, label: string, post_types: array<int, string>, terms: array<int, array{slug: string, name: string, count: int}>, partial: bool}>, pages: array<int, array{id: int, title: string, path: string, parent: int}>, pages_partial: bool}
 */
final class SiteStructure {

	/** Most pages reported (decision D4 in requirements/08). */
	public const MAX_PAGES = 2000;

	/** Most terms reported per taxonomy. */
	public const MAX_TERMS = 500;

	/** Most post types and taxonomies reported. */
	private const MAX_TYPES = 100;

	/**
	 * Clean a structure, as reported or as received: anything unexpected is dropped.
	 *
	 * @param mixed $raw Candidate.
	 * @return Structure
	 */
	public static function clean( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array(
			'post_types'    => array(),
			'taxonomies'    => array(),
			'pages'         => array(),
			'pages_partial' => true === ( $raw['pages_partial'] ?? false ),
		);
		foreach ( array_slice( (array) ( $raw['post_types'] ?? array() ), 0, self::MAX_TYPES ) as $type ) {
			$name = is_array( $type ) ? self::key( $type['name'] ?? null ) : null;
			if ( null !== $name ) {
				$out['post_types'][] = array(
					'name'  => $name,
					'label' => self::text( $type['label'] ?? '', $name ),
				);
			}
		}
		foreach ( array_slice( (array) ( $raw['taxonomies'] ?? array() ), 0, self::MAX_TYPES ) as $taxonomy ) {
			$name = is_array( $taxonomy ) ? self::key( $taxonomy['name'] ?? null ) : null;
			if ( null === $name ) {
				continue;
			}
			$terms = array();
			foreach ( array_slice( (array) ( $taxonomy['terms'] ?? array() ), 0, self::MAX_TERMS ) as $term ) {
				$slug = is_array( $term ) && is_string( $term['slug'] ?? null ) ? strtolower( trim( $term['slug'] ) ) : '';
				if ( 1 === preg_match( '/^[a-z0-9_%-]{1,200}$/', $slug ) ) {
					$terms[] = array(
						'slug'  => $slug,
						'name'  => self::text( $term['name'] ?? '', $slug ),
						'count' => max( 0, (int) ( $term['count'] ?? 0 ) ),
					);
				}
			}
			$out['taxonomies'][] = array(
				'name'       => $name,
				'label'      => self::text( $taxonomy['label'] ?? '', $name ),
				'post_types' => array_values( array_filter( array_map( array( self::class, 'key' ), (array) ( $taxonomy['post_types'] ?? array() ) ) ) ),
				'terms'      => $terms,
				'partial'    => true === ( $taxonomy['partial'] ?? false ) || count( (array) ( $taxonomy['terms'] ?? array() ) ) > self::MAX_TERMS,
			);
		}//end foreach
		foreach ( array_slice( (array) ( $raw['pages'] ?? array() ), 0, self::MAX_PAGES ) as $page ) {
			$path = is_array( $page ) ? self::path( $page['path'] ?? null ) : null;
			if ( null === $path ) {
				continue;
			}
			$out['pages'][] = array(
				'id'     => max( 0, (int) ( $page['id'] ?? 0 ) ),
				'title'  => self::text( $page['title'] ?? '', $path ),
				'path'   => $path,
				'parent' => max( 0, (int) ( $page['parent'] ?? 0 ) ),
			);
		}
		if ( count( (array) ( $raw['pages'] ?? array() ) ) > self::MAX_PAGES ) {
			$out['pages_partial'] = true;
		}
		return $out;
	}

	/**
	 * A stable fingerprint, so a site sends its structure only when it changed.
	 *
	 * @param array<string, mixed> $structure Clean structure.
	 */
	public static function hash( array $structure ): string {
		return hash( 'sha256', (string) json_encode( $structure ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-free.
	}

	/**
	 * A page path as rules use it: lowercase, from the site's home, no trailing slash.
	 *
	 * @param mixed $value Candidate such as "/Services/" or "services/roofing".
	 */
	public static function path( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$path = '/' . trim( strtolower( trim( $value ) ), '/' );
		return 1 === preg_match( '#^/[a-z0-9/_%.~-]{0,200}$#', $path ) ? $path : null;
	}

	/**
	 * A post type or taxonomy name, or null.
	 *
	 * @param mixed $value Candidate.
	 */
	private static function key( $value ): ?string {
		return is_string( $value ) && 1 === preg_match( '/^[a-z0-9_-]{1,40}$/', $value ) ? $value : null;
	}

	/**
	 * Short plain text, or a fallback.
	 *
	 * @param mixed  $value    Candidate.
	 * @param string $fallback When empty.
	 */
	private static function text( $value, string $fallback ): string {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- WordPress-free.
		$text = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $value ) ) );
		return '' === $text ? $fallback : mb_substr( $text, 0, 120 );
	}
}
