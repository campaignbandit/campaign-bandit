<?php
/**
 * The visitor's country from a header the host or CDN already sends (BR-04, decision
 * 0027). Most sites behind a CDN get a country this way, with no database and no
 * lookup of any kind.
 *
 * The raw IP address is never read or stored here.
 *
 * WordPress-free, so the rules are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Geo;

/**
 * Country headers.
 */
final class CountryHeaders {

	/**
	 * Headers that carry a two-letter country code, as PHP names them in $_SERVER.
	 * First match wins, so the most specific come first.
	 */
	public const HEADERS = array(
		// Cloudflare (BR-04).
		'HTTP_CF_IPCOUNTRY',
		// AWS CloudFront.
		'HTTP_CLOUDFRONT_VIEWER_COUNTRY',
		// Vercel.
		'HTTP_X_VERCEL_IP_COUNTRY',
		// Google App Engine and Cloud CDN.
		'HTTP_X_APPENGINE_COUNTRY',
		'HTTP_X_CLIENT_GEO_LOCATION',
		// Fastly, and several managed WordPress hosts.
		'HTTP_FASTLY_CLIENT_COUNTRY',
		'HTTP_X_COUNTRY_CODE',
		'HTTP_X_GEO_COUNTRY',
		// Sucuri.
		'HTTP_X_SUCURI_COUNTRY',
		// The nginx and Apache GeoIP modules, which set these on the request.
		'GEOIP_COUNTRY_CODE',
		'HTTP_X_GEOIP_COUNTRY',
	);

	/**
	 * Codes that mean "not a country": Cloudflare's unknown and Tor, and the reserved
	 * codes DB-IP uses for unassigned ranges.
	 */
	public const NOT_A_COUNTRY = array( 'XX', 'T1', 'ZZ', 'A1', 'A2', 'O1', 'AP', 'EU' );

	/**
	 * The country a request's headers give, or '' when none does.
	 *
	 * @param array<string, mixed> $server Request variables ($_SERVER).
	 */
	public static function from( array $server ): string {
		foreach ( self::HEADERS as $name ) {
			$code = self::code( $server[ $name ] ?? null );
			if ( '' !== $code ) {
				return $code;
			}
		}
		// Akamai sends one header of key=value pairs, among them country_code=US.
		$edgescape = $server['HTTP_X_AKAMAI_EDGESCAPE'] ?? null;
		if ( is_string( $edgescape ) && 1 === preg_match( '/country_code=([A-Za-z]{2})\b/', $edgescape, $found ) ) {
			return self::code( $found[1] );
		}
		return '';
	}

	/**
	 * A header value as a country code, or '' when it isn't one.
	 *
	 * @param mixed $value Header value.
	 */
	public static function code( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$code = strtoupper( trim( $value ) );
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) || in_array( $code, self::NOT_A_COUNTRY, true ) ) {
			return '';
		}
		return $code;
	}
}
