<?php
declare( strict_types = 1 );

test(
	'crypto: round trip, and anything tampered opens to nothing',
	static function (): void {
		$box = SMPW_Crypto::encrypt( 'webhook-secret-abc' );
		assert_same( 'webhook-secret-abc', SMPW_Crypto::decrypt( $box ) );
		assert_same( false, str_contains( $box, 'webhook-secret-abc' ) );
		assert_same( '', SMPW_Crypto::decrypt( 'not-base64-%%%' ) );
		$raw      = base64_decode( $box );
		$raw[-1]  = chr( ord( $raw[-1] ) ^ 1 );
		assert_same( '', SMPW_Crypto::decrypt( base64_encode( $raw ) ) );
	}
);

test(
	'webhook store: saves per mode, keeps the secret encrypted, records events, forgets',
	static function (): void {
		assert_same( '', SMPW_Webhook_Store::get( 'test' )['id'] );
		SMPW_Webhook_Store::save( 'test', 'we_test', 'https://shop.test/?wc-api=smpw_webhook', 'webhook-secret-xyz' );
		$stored = SMPW_Webhook_Store::get( 'test' );
		assert_same( 'we_test', $stored['id'] );
		assert_same( false, str_contains( wp_json_encode( $GLOBALS['smpw_options'] ), 'webhook-secret-xyz' ), 'plain secret in options' );
		assert_same( 'webhook-secret-xyz', SMPW_Webhook_Store::secret( 'test' ) );
		assert_same( '', SMPW_Webhook_Store::secret( 'live' ), 'modes are separate' );
		assert_same( 0, $stored['last_event_at'] );
		SMPW_Webhook_Store::touch( 'test' );
		assert_true( SMPW_Webhook_Store::get( 'test' )['last_event_at'] > 0 );
		SMPW_Webhook_Store::forget( 'test' );
		assert_same( '', SMPW_Webhook_Store::get( 'test' )['id'] );
	}
);
