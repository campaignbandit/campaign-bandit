<?php
/**
 * Ed25519 key pairs for hub and site connections (decision 0014).
 *
 * WordPress-free. Keys travel and are stored as base64.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

/**
 * Generate keys, sign and verify.
 */
final class KeyPair {

	/**
	 * A new key pair.
	 *
	 * @return array{public: string, secret: string} Base64 keys.
	 */
	public static function generate(): array {
		$pair = sodium_crypto_sign_keypair();
		return array(
			'public' => base64_encode( sodium_crypto_sign_publickey( $pair ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary keys as text.
			'secret' => base64_encode( sodium_crypto_sign_secretkey( $pair ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);
	}

	/**
	 * Sign a message.
	 *
	 * @param string $secret  Base64 secret key.
	 * @param string $message Message.
	 * @return string Base64 signature, or '' when the key is unusable.
	 */
	public static function sign( string $secret, string $message ): string {
		$key = self::decode( $secret, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES );
		return null === $key ? '' : base64_encode( sodium_crypto_sign_detached( $message, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Check a signature.
	 *
	 * @param string $public_key Base64 public key.
	 * @param string $message   Message.
	 * @param string $signature Base64 signature.
	 */
	public static function verify( string $public_key, string $message, string $signature ): bool {
		$key = self::decode( $public_key, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES );
		$sig = self::decode( $signature, SODIUM_CRYPTO_SIGN_BYTES );
		return null !== $key && null !== $sig && sodium_crypto_sign_verify_detached( $sig, $message, $key );
	}

	/**
	 * Whether a value is a base64 public key.
	 *
	 * @param string $public_key Candidate.
	 */
	public static function is_public_key( string $public_key ): bool {
		return null !== self::decode( $public_key, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES );
	}

	/**
	 * Base64 to bytes of an exact length, or null.
	 *
	 * @param string $value  Base64.
	 * @param int    $length Expected byte length.
	 */
	private static function decode( string $value, int $length ): ?string {
		$raw = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary keys as text.
		return false !== $raw && strlen( $raw ) === $length ? $raw : null;
	}
}
