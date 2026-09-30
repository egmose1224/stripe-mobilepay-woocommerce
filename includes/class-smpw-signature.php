<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stripe's webhook signature: "t=<unix time>,v1=<hex HMAC-SHA256 of '<t>.<payload>'>" (several v1 during a
 * secret rotation). Pure. https://docs.stripe.com/webhooks#verify-manually
 */
final class SMPW_Signature {

	public static function verify( string $payload, string $header, string $secret, int $now, int $tolerance = 300 ): bool {
		if ( '' === $secret || '' === $header ) {
			return false;
		}
		$timestamp  = null;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] && ctype_digit( $pair[1] ) ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] && '' !== $pair[1] ) {
				$signatures[] = $pair[1];
			}
		}
		if ( null === $timestamp || array() === $signatures || abs( $now - $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/** A valid header for $payload — tests and self-checks only. */
	public static function header( string $payload, string $secret, int $timestamp ): string {
		return 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
	}
}
