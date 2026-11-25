<?php
/**
 * Campaign images in hub content (decision 0002): find the hub's upload URLs in a
 * version's block markup, and rewrite them to the copies on the client site.
 *
 * WordPress-free, so both are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

/**
 * Finds and rewrites image URLs.
 */
final class MediaUrls {

	/**
	 * File types copied to sites. SVG is left out: it can carry scripts.
	 */
	public const EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );

	/**
	 * Largest image copied, in bytes.
	 */
	public const MAX_BYTES = 10 * 1024 * 1024;

	/**
	 * Image URLs under the hub's uploads that the content uses: in src, srcset, block
	 * attributes and inline background images. Each appears once, in order of use.
	 *
	 * @param string $content     Block markup.
	 * @param string $uploads_url The hub's uploads base URL, such as https://hub.example/wp-content/uploads.
	 * @return string[]
	 */
	public static function find( string $content, string $uploads_url ): array {
		$base = rtrim( $uploads_url, '/' );
		if ( '' === $base ) {
			return array();
		}
		// Block attributes may store slashes escaped as "\/".
		$content = str_replace( '\\/', '/', $content );
		$pattern = '~' . preg_quote( $base, '~' ) . '/[^\s"\'<>()\\\\,]+?\.(' . implode( '|', self::EXTENSIONS ) . ')(?=[\s"\'<>()\\\\,?#]|$)~i';
		if ( ! preg_match_all( $pattern, $content, $matches ) ) {
			return array();
		}
		return array_values( array_unique( $matches[0] ) );
	}

	/**
	 * Swap hub URLs for local ones, in plain and escaped ("\/") form.
	 *
	 * @param string                $content Block markup.
	 * @param array<string, string> $map     Hub URL => local URL.
	 */
	public static function rewrite( string $content, array $map ): string {
		if ( array() === $map ) {
			return $content;
		}
		// Longest first, so a URL is never cut by a shorter one it starts with.
		uksort( $map, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		$pairs = array();
		foreach ( $map as $from => $to ) {
			$pairs[ $from ]                            = $to;
			$pairs[ str_replace( '/', '\\/', $from ) ] = str_replace( '/', '\\/', $to );
		}
		return strtr( $content, $pairs );
	}

	/**
	 * The file name a copy is stored under: the start of its hash, then its own name,
	 * so two images with one name never collide and a changed image gets a new URL.
	 *
	 * @param string $url    Hub URL.
	 * @param string $sha256 Content hash.
	 */
	public static function local_name( string $url, string $sha256 ): string {
		$path = (string) parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress-free.
		$name = strtolower( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', basename( $path ) ) );
		return substr( $sha256, 0, 12 ) . '-' . ltrim( $name, '.-' );
	}

	/**
	 * Whether a listed image is acceptable: a hub upload of an allowed type, a 64-hex
	 * hash and a size within the limit.
	 *
	 * @param mixed  $item        Media entry from a package.
	 * @param string $hub_uploads Hub uploads base URL as the site knows it.
	 */
	public static function valid( $item, string $hub_uploads ): bool {
		if ( ! is_array( $item ) || ! is_string( $item['url'] ?? null ) || ! is_string( $item['sha256'] ?? null ) || ! is_int( $item['size'] ?? null ) ) {
			return false;
		}
		$extension = strtolower( pathinfo( (string) parse_url( $item['url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress-free.
		return str_starts_with( $item['url'], rtrim( $hub_uploads, '/' ) . '/' )
			&& in_array( $extension, self::EXTENSIONS, true )
			&& 1 === preg_match( '/^[0-9a-f]{64}$/', $item['sha256'] )
			&& $item['size'] > 0 && $item['size'] <= self::MAX_BYTES;
	}
}
