<?php
/**
 * Signed requests between a hub and its sites (decision 0014).
 *
 * WordPress-free, so the canonical form and the checks are unit-tested. Nonce
 * replay is checked by the caller, which has storage.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

/**
 * Builds and checks request signatures.
 */
final class RequestSigner {

	public const HEADER_SITE      = 'X-Campaign-Bandit-Site';
	public const HEADER_TIMESTAMP = 'X-Campaign-Bandit-Timestamp';
	public const HEADER_NONCE     = 'X-Campaign-Bandit-Nonce';
	public const HEADER_SIGNATURE = 'X-Campaign-Bandit-Request-Signature';

	/**
	 * Requests more than this many seconds off are refused.
	 */
	public const WINDOW = 300;

	/**
	 * The signed text: method, path, timestamp, nonce and body hash, one per line.
	 *
	 * @param string $method    HTTP method.
	 * @param string $path      Request path, such as /wp-json/campaign-bandit/v1/hub/sync.
	 * @param int    $timestamp Unix time.
	 * @param string $nonce     Random nonce.
	 * @param string $body      Raw body ('' for none).
	 */
	public static function canonical( string $method, string $path, int $timestamp, string $nonce, string $body ): string {
		return implode( "\n", array( strtoupper( $method ), self::normalise_path( $path ), (string) $timestamp, $nonce, hash( 'sha256', $body ) ) );
	}

	/**
	 * Headers for an outgoing request.
	 *
	 * @param string $site_uid  Site ID (on hub → site requests, the receiving site's ID).
	 * @param string $secret    Sender's base64 secret key.
	 * @param string $method    HTTP method.
	 * @param string $path      Request path.
	 * @param string $body      Raw body.
	 * @param int    $now       Current Unix time.
	 * @return array<string, string>
	 */
	public static function headers( string $site_uid, string $secret, string $method, string $path, string $body, int $now ): array {
		$nonce = bin2hex( random_bytes( 16 ) );
		return array(
			self::HEADER_SITE      => $site_uid,
			self::HEADER_TIMESTAMP => (string) $now,
			self::HEADER_NONCE     => $nonce,
			self::HEADER_SIGNATURE => KeyPair::sign( $secret, self::canonical( $method, $path, $now, $nonce, $body ) ),
		);
	}

	/**
	 * Check an incoming request's signature and time. The caller then checks the
	 * nonce has not been used before.
	 *
	 * @param string                $public_key  Sender's base64 public key.
	 * @param string                $method  HTTP method.
	 * @param string                $path    Request path.
	 * @param array<string, string> $headers Header values by the HEADER_* names (any case).
	 * @param string                $body    Raw body.
	 * @param int                   $now     Current Unix time.
	 */
	public static function verify( string $public_key, string $method, string $path, array $headers, string $body, int $now ): bool {
		$headers   = array_change_key_case( $headers, CASE_LOWER );
		$timestamp = (string) ( $headers[ strtolower( self::HEADER_TIMESTAMP ) ] ?? '' );
		$nonce     = (string) ( $headers[ strtolower( self::HEADER_NONCE ) ] ?? '' );
		$signature = (string) ( $headers[ strtolower( self::HEADER_SIGNATURE ) ] ?? '' );

		if ( ! ctype_digit( $timestamp ) || abs( $now - (int) $timestamp ) > self::WINDOW ) {
			return false;
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/', $nonce ) ) {
			return false;
		}
		return KeyPair::verify( $public_key, self::canonical( $method, $path, (int) $timestamp, $nonce, $body ), $signature );
	}

	/**
	 * The path without its query string, with one leading slash.
	 *
	 * @param string $path Path or URL path.
	 */
	private static function normalise_path( string $path ): string {
		$path = (string) strtok( $path, '?' );
		return '/' . ltrim( $path, '/' );
	}
}
