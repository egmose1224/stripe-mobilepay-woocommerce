<?php
defined( 'ABSPATH' ) || exit;

/**
 * The webhook signing secret at rest: sodium secretbox, the key derived from the site's AUTH salt. New salts = create
 * the webhook again.
 */
final class SMPW_Crypto {

	private static function key(): string {
		return sodium_crypto_generichash( wp_salt( 'auth' ) . '|stripe-mobilepay-woocommerce', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	public static function decrypt( string $stored ): string {
		$raw = base64_decode( $stored, true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		return false === $plain ? '' : $plain;
	}
}
