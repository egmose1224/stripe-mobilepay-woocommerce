<?php
defined( 'ABSPATH' ) || exit;

/** WooCommerce log, source "stripe-mobilepay-woocommerce". Never pass keys, secrets or client secrets. */
final class SMPW_Log {

	public static function info( string $message, array $context = array() ): void {
		self::write( 'info', $message, $context );
	}

	public static function warning( string $message, array $context = array() ): void {
		self::write( 'warning', $message, $context );
	}

	public static function error( string $message, array $context = array() ): void {
		self::write( 'error', $message, $context );
	}

	private static function write( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message . ( array() !== $context ? ' ' . wp_json_encode( $context ) : '' ), array( 'source' => 'stripe-mobilepay-woocommerce' ) );
	}
}
