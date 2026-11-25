<?php
/**
 * One starter pattern file (decision 0018): a header comment, then block markup.
 * WordPress-free, so the format is unit-tested; the same files can later be served
 * by the remote pattern library unchanged.
 *
 * Header lines are "Key: value": Title, Slug, Description, Keywords (comma list) and
 * Settings (JSON: the campaign settings the starter brings, such as its format and
 * appearance). Images are written as {{image:name.webp}} and replaced with the URL of
 * the folder the pattern came from.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Patterns;

/**
 * Parses a pattern file.
 */
final class PatternFile {

	/**
	 * Settings a starter may bring; anything else is ignored.
	 */
	private const SETTINGS = array( 'format', 'goal', 'corner', 'bar_position', 'appearance' );

	/**
	 * Parse a pattern file's text.
	 *
	 * @param string $text      File contents.
	 * @param string $image_url URL of the folder that holds its images, without a trailing slash.
	 * @return array{slug: string, title: string, description: string, keywords: string[], settings: array<string, mixed>, content: string}|null Null when the file is not a pattern.
	 */
	public static function parse( string $text, string $image_url ): ?array {
		if ( 1 !== preg_match( '/^\s*<!--(.*?)-->\s*(.*)$/s', $text, $parts ) ) {
			return null;
		}
		$header = array();
		foreach ( preg_split( '/\R/', trim( $parts[1] ) ) as $line ) {
			if ( 1 === preg_match( '/^([A-Za-z]+):\s*(.*)$/', trim( $line ), $m ) ) {
				$header[ strtolower( $m[1] ) ] = trim( $m[2] );
			}
		}
		$slug    = $header['slug'] ?? '';
		$content = trim( $parts[2] );
		if ( 1 !== preg_match( '/^[a-z0-9-]{1,64}$/', $slug ) || '' === ( $header['title'] ?? '' ) || ! str_starts_with( $content, '<!-- wp:' ) ) {
			return null;
		}
		$settings = json_decode( $header['settings'] ?? '', true );
		return array(
			'slug'        => $slug,
			'title'       => $header['title'],
			'description' => $header['description'] ?? '',
			'keywords'    => array_values( array_filter( array_map( 'trim', explode( ',', $header['keywords'] ?? '' ) ) ) ),
			'settings'    => is_array( $settings ) ? array_intersect_key( $settings, array_flip( self::SETTINGS ) ) : array(),
			'content'     => self::images( $content, $image_url ),
		);
	}

	/**
	 * Replace {{image:name}} with the image's URL. Names are plain file names only.
	 *
	 * @param string $content   Block markup.
	 * @param string $image_url Folder URL.
	 */
	public static function images( string $content, string $image_url ): string {
		return (string) preg_replace_callback(
			'/\{\{image:([A-Za-z0-9._-]+)\}\}/',
			static fn ( array $m ): string => str_contains( $m[1], '..' ) ? '' : $image_url . '/' . $m[1],
			$content
		);
	}
}
