<?php
/**
 * A lead's source (BR-47): what the visitor's browser recorded about where they came
 * from, cleaned to the BR-44 `source` shape. Used on the site that stores the lead,
 * on the hub that receives it, and for the payload, CSV and lead detail.
 *
 * WordPress-free, so the rules are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Leads;

/**
 * Lead source cleaning and summaries.
 */
final class LeadSource {

	public const UTM_PARTS = array( 'source', 'medium', 'campaign', 'term', 'content' );
	public const CLICK_IDS = array( 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid' );

	private const MAX_PAGES = 10;
	private const MAX_TEXT  = 200;

	/**
	 * The BR-44 `source` shape from untrusted input, or [] when nothing usable was sent.
	 *
	 * @param mixed $raw Source as sent.
	 * @return array{first?: array<string, mixed>, last?: array<string, mixed>, click_ids?: array<string, string>, visits?: int, pages?: string[]}
	 */
	public static function clean( $raw ): array {
		if ( ! is_array( $raw ) || ! is_array( $raw['last'] ?? null ) ) {
			return array();
		}
		$last  = self::visit( $raw['last'] );
		$first = is_array( $raw['first'] ?? null ) ? self::visit( $raw['first'] ) : $last;
		$ids   = array();
		foreach ( self::CLICK_IDS as $name ) {
			$value = $raw['click_ids'][ $name ] ?? null;
			if ( is_scalar( $value ) ) {
				// Click IDs are opaque tokens: letters, digits and a few separators.
				$value = substr( (string) preg_replace( '/[^A-Za-z0-9._~\-]/', '', (string) $value ), 0, self::MAX_TEXT );
				if ( '' !== $value ) {
					$ids[ $name ] = $value;
				}
			}
		}
		$pages = array();
		foreach ( array_slice( is_array( $raw['pages'] ?? null ) ? array_values( $raw['pages'] ) : array(), -self::MAX_PAGES ) as $page ) {
			$path = self::path( $page );
			if ( '' !== $path ) {
				$pages[] = $path;
			}
		}
		$visits = is_numeric( $raw['visits'] ?? null ) ? (int) $raw['visits'] : 1;

		return array(
			'first'     => $first,
			'last'      => $last,
			'click_ids' => $ids,
			'visits'    => max( 1, min( 100000, $visits ) ),
			'pages'     => $pages,
		);
	}

	/**
	 * A source from stored JSON, cleaned again so old or hand-edited rows can't break
	 * screens or the payload.
	 *
	 * @param mixed $json Stored JSON, or null.
	 * @return array<string, mixed>
	 */
	public static function from_json( $json ): array {
		$value = is_string( $json ) ? json_decode( $json, true ) : null;
		return self::clean( $value );
	}

	/**
	 * "google / cpc" for a visit, or "(direct)".
	 *
	 * @param array<string, mixed> $visit A cleaned visit.
	 */
	public static function label( array $visit ): string {
		$utm = (array) ( $visit['utm'] ?? array() );
		if ( '' !== (string) ( $utm['source'] ?? '' ) ) {
			return trim( $utm['source'] . ( '' !== (string) ( $utm['medium'] ?? '' ) ? ' / ' . $utm['medium'] : '' ) );
		}
		if ( '' !== (string) ( $visit['referrer'] ?? '' ) ) {
			return $visit['referrer'] . ' / referral';
		}
		return '(direct)';
	}

	/**
	 * One visit, cleaned.
	 *
	 * @param array<string, mixed> $raw Visit as sent.
	 * @return array{utm: array<string, string>, referrer: string, landing_page: string, at: string}
	 */
	private static function visit( array $raw ): array {
		$utm = array();
		foreach ( self::UTM_PARTS as $part ) {
			$value = $raw['utm'][ $part ] ?? null;
			if ( is_scalar( $value ) ) {
				$value = self::text( (string) $value );
				if ( '' !== $value ) {
					$utm[ $part ] = $value;
				}
			}
		}
		$referrer = is_string( $raw['referrer'] ?? null ) ? strtolower( (string) preg_replace( '/[^A-Za-z0-9.\-]/', '', $raw['referrer'] ) ) : '';
		$at       = is_string( $raw['at'] ?? null ) ? strtotime( $raw['at'] ) : false;
		$path     = self::path( $raw['landing_page'] ?? '' );

		return array(
			'utm'          => $utm,
			'referrer'     => substr( $referrer, 0, self::MAX_TEXT ),
			'landing_page' => '' !== $path ? $path : '/',
			'at'           => false === $at ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $at ),
		);
	}

	/**
	 * A path without query string or fragment, starting with a slash; '' when not a path.
	 *
	 * @param mixed $value Candidate.
	 */
	private static function path( $value ): string {
		if ( ! is_string( $value ) || '' === $value || '/' !== $value[0] || str_starts_with( $value, '//' ) ) {
			return '';
		}
		$path = (string) preg_replace( '/[?#].*$/s', '', $value );
		return self::text( $path );
	}

	/**
	 * Plain text: no tags or control characters, length-limited.
	 *
	 * @param string $value Text.
	 */
	private static function text( string $value ): string {
		$value = (string) preg_replace( '/<[^>]*>|[\x00-\x1F\x7F]/u', '', $value );
		return trim( mb_substr( $value, 0, self::MAX_TEXT ) );
	}
}
