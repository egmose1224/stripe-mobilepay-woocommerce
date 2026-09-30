<?php
defined( 'ABSPATH' ) || exit;

/** This site's webhook endpoint at Stripe, per mode: id, URL, encrypted signing secret, health. */
final class SMPW_Webhook_Store {

	private const OPTION = 'smpw_webhook_';

	/** @return array{id: string, url: string, secret: string, created: int, last_event_at: int} */
	public static function get( string $mode ): array {
		$stored = get_option( self::OPTION . self::mode( $mode ), array() );
		return array_merge(
			array( 'id' => '', 'url' => '', 'secret' => '', 'created' => 0, 'last_event_at' => 0 ),
			is_array( $stored ) ? $stored : array()
		);
	}

	public static function save( string $mode, string $id, string $url, string $secret ): void {
		update_option(
			self::OPTION . self::mode( $mode ),
			array( 'id' => $id, 'url' => $url, 'secret' => SMPW_Crypto::encrypt( $secret ), 'created' => time(), 'last_event_at' => 0 ),
			false
		);
	}

	public static function secret( string $mode ): string {
		$stored = self::get( $mode )['secret'];
		return '' === $stored ? '' : SMPW_Crypto::decrypt( $stored );
	}

	/** A verified event arrived (written at most once a minute). */
	public static function touch( string $mode ): void {
		$data = self::get( $mode );
		if ( '' === $data['id'] || time() - (int) $data['last_event_at'] < 60 ) {
			return;
		}
		$data['last_event_at'] = time();
		update_option( self::OPTION . self::mode( $mode ), $data, false );
	}

	public static function forget( string $mode ): void {
		delete_option( self::OPTION . self::mode( $mode ) );
	}

	private static function mode( string $mode ): string {
		return 'test' === $mode ? 'test' : 'live';
	}
}
