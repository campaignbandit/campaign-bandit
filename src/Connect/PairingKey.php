<?php
/**
 * Pairing keys (FR-10, decision 0014): "cbpair_" + base64url(hub URL) + "." + a
 * random token. The site needs only the key to find its hub; the hub stores only a
 * hash of the token.
 *
 * WordPress-free, so the format is unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

/**
 * Builds and reads pairing keys.
 */
final class PairingKey {

	private const PREFIX = 'cbpair_';

	/**
	 * Keys last 24 hours (FR-10).
	 */
	public const LIFETIME = 86400;

	/**
	 * A new key for a hub URL.
	 *
	 * @param string $hub_url Hub home URL.
	 * @return array{key: string, token: string} The key to show once, and its token.
	 */
	public static function create( string $hub_url ): array {
		$token = self::base64url( random_bytes( 32 ) );
		return array(
			'key'   => self::PREFIX . self::base64url( rtrim( $hub_url, '/' ) ) . '.' . $token,
			'token' => $token,
		);
	}

	/**
	 * Read a key pasted by a person: whitespace is ignored.
	 *
	 * @param string $key Key.
	 * @return array{hub_url: string, token: string}|null Null when it isn't a pairing key.
	 */
	public static function parse( string $key ): ?array {
		$key = (string) preg_replace( '/\s+/', '', $key );
		if ( ! str_starts_with( $key, self::PREFIX ) ) {
			return null;
		}
		$parts = explode( '.', substr( $key, strlen( self::PREFIX ) ) );
		if ( 2 !== count( $parts ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $parts[1] ) ) {
			return null;
		}
		$url = base64_decode( strtr( $parts[0], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- URL-safe encoding of the hub URL.
		if ( false === $url || 1 !== preg_match( '~^https?://[^\s/?#]+(/[^\s?#]*)?$~', $url ) ) {
			return null;
		}
		return array(
			'hub_url' => $url,
			'token'   => $parts[1],
		);
	}

	/**
	 * The stored form of a token.
	 *
	 * @param string $token Token.
	 */
	public static function hash( string $token ): string {
		return hash( 'sha256', 'campaign-bandit-pairing|' . $token );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Bytes.
	 */
	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe key text.
	}
}
