<?php
/**
 * Looks a country up in the packed index built from DB-IP Lite (BR-04, decision 0027).
 *
 * The index is two files of fixed-size records, sorted by the range's first address:
 * IPv4 is a 4-byte address and a 2-byte country code; IPv6 is 16 bytes and 2. DB-IP's
 * ranges cover the whole address space with no gaps, so the country of an address is
 * the one on the last record that starts at or below it. Records are big-endian, so
 * they compare correctly as byte strings whatever the platform's integer size.
 *
 * Lookups read a few hundred bytes with binary search: the file is never loaded.
 *
 * The reads go through fopen/fseek/fread rather than WP_Filesystem, which can only
 * read a file whole: loading 8 MB per lookup is exactly what this index avoids.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Geo;

/**
 * The packed country index.
 */
final class CountryIndex {

	public const V4_FILE   = 'ipv4.idx';
	public const V6_FILE   = 'ipv6.idx';
	public const V4_RECORD = 6;
	public const V6_RECORD = 18;

	/** DB-IP uses ZZ for ranges with no country. */
	private const UNASSIGNED = 'ZZ';

	/**
	 * Create the index reader.
	 *
	 * @param string $directory Folder holding the index files.
	 */
	public function __construct( private readonly string $directory ) {}

	/**
	 * The country for an IP address, or '' when it isn't known: a malformed address, a
	 * missing index, or a range DB-IP marks unassigned.
	 *
	 * @param string $ip IP address.
	 */
	public function country( string $ip ): string {
		$packed = @inet_pton( trim( $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false for a malformed address.
		if ( false === $packed ) {
			return '';
		}
		$ipv4 = 4 === strlen( $packed );
		$file = $this->directory . '/' . ( $ipv4 ? self::V4_FILE : self::V6_FILE );
		$size = $ipv4 ? self::V4_RECORD : self::V6_RECORD;
		$code = self::search( $file, $packed, $size );
		return self::UNASSIGNED === $code ? '' : $code;
	}

	/**
	 * Binary search for the last record starting at or below the address.
	 *
	 * @param string $file    Index file.
	 * @param string $packed  The address, packed (4 or 16 bytes).
	 * @param int    $size    Record size.
	 * @return string Country code, or ''.
	 */
	private static function search( string $file, string $packed, int $size ): string {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- a seeking read of our own index; see the file comment.
		if ( ! is_file( $file ) ) {
			return '';
		}
		$bytes = (int) filesize( $file );
		$count = intdiv( $bytes, $size );
		if ( $count < 1 ) {
			return '';
		}
		$handle = fopen( $file, 'rb' );
		if ( false === $handle ) {
			return '';
		}
		$key   = strlen( $packed );
		$low   = 0;
		$high  = $count - 1;
		$found = '';
		try {
			while ( $low <= $high ) {
				$middle = intdiv( $low + $high, 2 );
				if ( fseek( $handle, $middle * $size ) !== 0 ) {
					return '';
				}
				$record = (string) fread( $handle, $size );
				if ( strlen( $record ) !== $size ) {
					return '';
				}
				if ( strcmp( substr( $record, 0, $key ), $packed ) <= 0 ) {
					$found = substr( $record, $key, 2 );
					$low   = $middle + 1;
				} else {
					$high = $middle - 1;
				}
			}
		} finally {
			fclose( $handle );
		}
		return 1 === preg_match( '/^[A-Z]{2}$/', $found ) ? $found : '';
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fread
	}

	/**
	 * Whether both index files are in place.
	 */
	public function ready(): bool {
		return is_file( $this->directory . '/' . self::V4_FILE ) && is_file( $this->directory . '/' . self::V6_FILE );
	}
}
