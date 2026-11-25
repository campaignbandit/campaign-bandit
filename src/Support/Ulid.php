<?php
/**
 * ULID generator: 26-character, time-sortable unique IDs.
 *
 * Used for campaigns, leads and sites, so IDs compare the same way on the hub
 * and on every site (BR-10 tie-break, BR-44 lead_id).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Support;

/**
 * Generates ULIDs that sort by creation time, monotonic within one process.
 */
final class Ulid {

	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * Millisecond time of the last ULID generated in this process.
	 *
	 * @var int
	 */
	private static int $last_time = -1;

	/**
	 * Random part of the last ULID, as 16 five-bit values.
	 *
	 * @var int[]
	 */
	private static array $last_random = array();

	/**
	 * Generate a new ULID.
	 *
	 * @param int|null $time_ms Unix time in milliseconds; defaults to now.
	 */
	public static function generate( ?int $time_ms = null ): string {
		$time_ms ??= (int) floor( microtime( true ) * 1000 );

		if ( $time_ms === self::$last_time ) {
			// Same millisecond: increment the random part so order is kept.
			$random = self::increment( self::$last_random );
		} else {
			$random = array();
			foreach ( str_split( random_bytes( 16 ) ) as $byte ) {
				$random[] = ord( $byte ) & 31;
			}
		}

		self::$last_time   = $time_ms;
		self::$last_random = $random;

		return self::encode_time( $time_ms ) . self::encode_random( $random );
	}

	/**
	 * Whether a string is a well-formed ULID.
	 *
	 * @param string $value Candidate value.
	 */
	public static function is_valid( string $value ): bool {
		return 1 === preg_match( '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $value );
	}

	/**
	 * Encode a millisecond timestamp as 10 Crockford base32 characters.
	 *
	 * @param int $time_ms Unix time in milliseconds.
	 */
	private static function encode_time( int $time_ms ): string {
		$out = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$out     = self::ALPHABET[ $time_ms % 32 ] . $out;
			$time_ms = intdiv( $time_ms, 32 );
		}
		return $out;
	}

	/**
	 * Encode 16 five-bit values as 16 characters.
	 *
	 * @param int[] $random Five-bit values.
	 */
	private static function encode_random( array $random ): string {
		$out = '';
		foreach ( $random as $value ) {
			$out .= self::ALPHABET[ $value ];
		}
		return $out;
	}

	/**
	 * Add one to the random part, carrying across positions.
	 *
	 * @param int[] $random Five-bit values.
	 * @return int[]
	 */
	private static function increment( array $random ): array {
		for ( $i = count( $random ) - 1; $i >= 0; $i-- ) {
			if ( $random[ $i ] < 31 ) {
				++$random[ $i ];
				return $random;
			}
			$random[ $i ] = 0;
		}
		return $random;
	}
}
