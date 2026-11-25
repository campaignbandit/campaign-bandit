<?php
/**
 * Campaign packages signed by the hub (decision 0014): the site verifies a package
 * came from its own hub before using it (05 §8), and refuses older sequence numbers.
 *
 * WordPress-free, so the envelope and its checks are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

/**
 * Signs and opens package envelopes.
 */
final class SignedPackage {

	public const FORMAT = 1;

	/**
	 * Wrap a package: the payload as an exact JSON string and its signature.
	 *
	 * @param array<string, mixed> $payload Package (must hold "sequence" and "site").
	 * @param string               $secret  Hub's base64 secret key.
	 * @return array{format: int, payload: string, signature: string}
	 */
	public static function sign( array $payload, string $secret ): array {
		$json = (string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-free; the exact bytes are signed.
		return array(
			'format'    => self::FORMAT,
			'payload'   => $json,
			'signature' => KeyPair::sign( $secret, $json ),
		);
	}

	/**
	 * Open an envelope: checks the hub's signature, that it is for this site, and
	 * that it is newer than the last applied package.
	 *
	 * @param mixed  $envelope      Decoded envelope.
	 * @param string $hub_public    Hub's base64 public key, stored at pairing.
	 * @param string $site_uid      This site's ID.
	 * @param int    $last_sequence Sequence number of the last applied package.
	 * @return array<string, mixed>|null The payload, or null when it must not be used.
	 */
	public static function open( $envelope, string $hub_public, string $site_uid, int $last_sequence ): ?array {
		if ( ! is_array( $envelope ) || self::FORMAT !== ( $envelope['format'] ?? null ) || ! is_string( $envelope['payload'] ?? null ) || ! is_string( $envelope['signature'] ?? null ) ) {
			return null;
		}
		if ( ! KeyPair::verify( $hub_public, $envelope['payload'], $envelope['signature'] ) ) {
			return null;
		}
		$payload = json_decode( $envelope['payload'], true );
		if ( ! is_array( $payload ) || ( $payload['site'] ?? null ) !== $site_uid ) {
			return null;
		}
		$sequence = $payload['sequence'] ?? null;
		if ( ! is_int( $sequence ) || $sequence <= $last_sequence ) {
			return null;
		}
		return $payload;
	}
}
