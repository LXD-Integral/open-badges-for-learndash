<?php
/**
 * Provider credential storage.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Credentials;

use OBLD\Api\ApiClient;
use OBLD\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Stores per-provider API credentials.
 *
 * The client secret is encrypted at rest (libsodium secretbox) with a key
 * derived from the site's auth salts, stored in a non-autoloaded option, and
 * treated as write-only by the UI. If the salts are ever rotated the secret
 * becomes undecryptable; that state is surfaced as needs_reentry() — a health
 * notice, never a fatal.
 */
final class CredentialStore {

	private const CIPHER_VERSION = 1;

	/**
	 * Option name for a provider's credentials.
	 *
	 * @param Provider $provider Provider.
	 */
	public static function option_key( Provider $provider ): string {
		return 'obld_credentials_' . $provider->value;
	}

	/**
	 * Saved client id, or empty string.
	 *
	 * @param Provider $provider Provider.
	 */
	public function client_id( Provider $provider ): string {
		$row = $this->row( $provider );
		return is_array( $row ) ? (string) ( $row['client_id'] ?? '' ) : '';
	}

	/**
	 * Whether a secret has been saved (decryptable or not).
	 *
	 * @param Provider $provider Provider.
	 */
	public function has_secret( Provider $provider ): bool {
		$row = $this->row( $provider );
		return is_array( $row ) && ! empty( $row['secret'] );
	}

	/**
	 * Whether both halves of the credentials are present.
	 *
	 * @param Provider $provider Provider.
	 */
	public function is_configured( Provider $provider ): bool {
		return '' !== $this->client_id( $provider ) && $this->has_secret( $provider );
	}

	/**
	 * Decrypted client secret, or null when absent or undecryptable.
	 *
	 * @param Provider $provider Provider.
	 */
	public function secret( Provider $provider ): ?string {
		$row = $this->row( $provider );
		if ( ! is_array( $row ) || empty( $row['secret'] ) || ! is_array( $row['secret'] ) ) {
			return null;
		}

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storage decoding of binary ciphertext, not obfuscation.
		$cipher = base64_decode( (string) ( $row['secret']['cipher'] ?? '' ), true );
		$nonce  = base64_decode( (string) ( $row['secret']['nonce'] ?? '' ), true );
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $cipher || false === $nonce || SODIUM_CRYPTO_SECRETBOX_NONCEBYTES !== strlen( $nonce ) ) {
			return null;
		}

		$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key() );

		return false === $plain ? null : $plain;
	}

	/**
	 * A secret exists but cannot be decrypted (typically: rotated salts).
	 * The admin must re-enter the credentials.
	 *
	 * @param Provider $provider Provider.
	 */
	public function needs_reentry( Provider $provider ): bool {
		return $this->has_secret( $provider ) && null === $this->secret( $provider );
	}

	/**
	 * Save credentials. A null/empty $new_secret keeps the existing secret
	 * (write-only field semantics). Any change invalidates the provider's
	 * cached token and its recorded connection status — the old plugin's
	 * stale-token-after-rotation bug is designed out here.
	 *
	 * @param Provider    $provider   Provider.
	 * @param string      $client_id  Client id (may be empty to clear).
	 * @param string|null $new_secret New secret, or null/'' to keep current.
	 */
	public function save( Provider $provider, string $client_id, ?string $new_secret ): void {
		$row = array( 'client_id' => $client_id );

		if ( null !== $new_secret && '' !== $new_secret ) {
			$nonce         = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$row['secret'] = array(
				'v'      => self::CIPHER_VERSION,
				'cipher' => base64_encode( sodium_crypto_secretbox( $new_secret, $nonce, $this->key() ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding for binary ciphertext.
				'nonce'  => base64_encode( $nonce ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding for binary nonce.
			);
		} else {
			$existing = $this->row( $provider );
			if ( is_array( $existing ) && ! empty( $existing['secret'] ) ) {
				$row['secret'] = $existing['secret'];
			}
		}

		// Third arg false: never autoload credentials on every request.
		update_option( self::option_key( $provider ), $row, false );

		delete_transient( ApiClient::token_transient_key( $provider ) );
		ConnectionStatus::clear( $provider );
	}

	/**
	 * Raw stored row.
	 *
	 * @param Provider $provider Provider.
	 *
	 * @return mixed
	 */
	private function row( Provider $provider ): mixed {
		return get_option( self::option_key( $provider ), null );
	}

	/**
	 * 32-byte encryption key derived from the site's auth salts. Filterable
	 * for hosts that want to supply key material from outside the database.
	 */
	private function key(): string {
		$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );

		/**
		 * Filters the raw key material used to derive the secret-encryption key.
		 *
		 * @param string $material Key material (hashed to 32 bytes before use).
		 */
		$material = (string) apply_filters( 'obld_encryption_key_material', $material );

		return hash( 'sha256', $material, true );
	}
}
