<?php
/**
 * Encryption at rest for secrets: destination secrets and headers (decision 0013)
 * and the private keys of hub and site connections (decision 0014).
 *
 * WordPress-free: the key material is passed in (the site's salts in production),
 * so it is unit-tested. WordPress ships a sodium fallback when the extension is
 * missing.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Support;

/**
 * Seals and opens short secrets with libsodium secretbox.
 */
final class SecretBox {

	private const PREFIX = 'cbsb1:';

	/**
	 * Create a box.
	 *
	 * @param string $key_material Long-lived secret material (for example the site's salts).
	 */
	public function __construct( private readonly string $key_material ) {}

	/**
	 * Seal a value.
	 *
	 * @param string $plain Value to protect.
	 */
	public function seal( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text.
	}

	/**
	 * Open a sealed value; null when it can't be read (wrong key or damaged).
	 *
	 * @param string $sealed Sealed value.
	 */
	public function open( string $sealed ): ?string {
		if ( ! str_starts_with( $sealed, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $sealed, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see seal().
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$this->key()
		);
		return false === $plain ? null : $plain;
	}

	/**
	 * A 32-byte key derived from the key material.
	 */
	private function key(): string {
		return hash( 'sha256', 'campaign-bandit-secretbox|' . $this->key_material, true );
	}
}
