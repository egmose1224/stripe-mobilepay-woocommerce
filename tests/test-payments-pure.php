<?php
declare( strict_types = 1 );

function smpw_test_facts(): array {
	return array(
		'id'       => 101,
		'number'   => '101',
		'name'     => 'Test Customer',
		'email'    => 'k@example.com',
		'phone'    => '+4512345678',
		'line1'    => 'Test Street 1',
		'line2'    => '',
		'postcode' => '1000',
		'city'     => 'Copenhagen',
		'country'  => 'DK',
		'shop'     => 'Test Shop',
	);
}

test(
	'payments: the PaymentIntent for an attempt — manual capture, MobilePay only, our metadata only',
	static function (): void {
		$return = SMPW_Return::build_url( 'https://shop.test/', 101, 'wc_order_abc', 2 );
		$params = SMPW_Payments::intent_params( smpw_test_facts(), 2, 66300, $return, 'shop.test' );
		assert_same(
			array(
				'amount'               => 66300,
				'currency'             => 'dkk',
				'payment_method_types' => array( 'mobilepay' ),
				'capture_method'       => 'manual',
				'confirm'              => true,
				'payment_method_data'  => array(
					'type'            => 'mobilepay',
					'billing_details' => array(
						'name'    => 'Test Customer',
						'email'   => 'k@example.com',
						'phone'   => '+4512345678',
						'address' => array( 'line1' => 'Test Street 1', 'postal_code' => '1000', 'city' => 'Copenhagen', 'country' => 'DK' ),
					),
				),
				'return_url'           => 'https://shop.test/?wc-api=smpw_return&order=101&key=wc_order_abc&attempt=2',
				'description'          => 'Test Shop order #101',
				'metadata'             => array(
					'smpw'              => '1',
					'smpw_site'         => 'shop.test',
					'smpw_order_id'     => '101',
					'smpw_order_number' => '101',
					'smpw_attempt'      => '2',
				),
			),
			$params
		);
		assert_same( false, isset( $params['metadata']['order_id'] ) || isset( $params['metadata']['signature'] ), 'never the Stripe plugin\'s keys' );
	}
);

test(
	'payments: empty billing fields are left out',
	static function (): void {
		$facts = array_merge( smpw_test_facts(), array( 'phone' => '', 'line1' => '', 'postcode' => '', 'city' => '', 'country' => '' ) );
		$data  = SMPW_Payments::intent_params( $facts, 1, 100, 'https://x/', 's' )['payment_method_data'];
		assert_same( array( 'type' => 'mobilepay', 'billing_details' => array( 'name' => 'Test Customer', 'email' => 'k@example.com' ) ), $data );
	}
);

test(
	'payments: idempotency keys name site, order and operation',
	static function (): void {
		assert_same( 'smpw-shop.example-101-create-1', SMPW_Payments::idempotency_key( 'shop.example', 101, 'create-1' ) );
		assert_same( 'smpw-staging.shop.example-7-capture-pi_1', SMPW_Payments::idempotency_key( 'staging.shop.example', 7, 'capture-pi_1' ) );
	}
);

test(
	'payments: a PaymentIntent must be ours, for this order, this site, this amount and currency',
	static function (): void {
		$attempt = array( 'id' => 'pi_1', 'attempt' => 1, 'amount' => 66300, 'currency' => 'dkk', 'mode' => 'test' );
		$intent  = static fn( array $meta, int $amount = 66300, string $currency = 'dkk' ): object => json_decode( wp_json_encode( array( 'id' => 'pi_1', 'amount' => $amount, 'currency' => $currency, 'metadata' => $meta ) ) );
		$meta    = array( 'smpw' => '1', 'smpw_site' => 'shop.test', 'smpw_order_id' => '101' );
		assert_same( '', SMPW_Payments::mismatch( $intent( $meta ), 101, $attempt, 'shop.test' ) );
		assert_same( 'not a MobilePay payment from this plugin', SMPW_Payments::mismatch( $intent( array( 'order_id' => '101' ) ), 101, $attempt, 'shop.test' ) );
		assert_same( 'a different order', SMPW_Payments::mismatch( $intent( $meta ), 102, $attempt, 'shop.test' ) );
		assert_same( 'a different website', SMPW_Payments::mismatch( $intent( $meta ), 101, $attempt, 'other.example' ) );
		assert_same( 'a different amount', SMPW_Payments::mismatch( $intent( $meta, 1 ), 101, $attempt, 'shop.test' ) );
		assert_same( 'a different currency', SMPW_Payments::mismatch( $intent( $meta, 66300, 'eur' ), 101, $attempt, 'shop.test' ) );
	}
);

test(
	'payments: sync() reports the attempt asked about, else the primary one — never another attempt\'s status',
	static function (): void {
		$p = 'SMPW_Payments';
		// Checked newest first: the retry is still waiting, the first attempt was closed.
		$seen = array( 'pi_2' => 'requires_action', 'pi_1' => 'canceled' );
		assert_same( 'requires_action', $p::sync_result( $seen, '', '', 'pi_2' ), 'the current attempt, not the oldest' );
		assert_same( 'canceled', $p::sync_result( $seen, 'pi_1', '', 'pi_2' ), 'the attempt asked about' );
		assert_same( 'error', $p::sync_result( array( 'pi_2' => 'error', 'pi_1' => 'canceled' ), '', '', 'pi_2' ), 'the primary attempt could not be read' );
		assert_same( 'requires_capture', $p::sync_result( array( 'pi_1' => 'requires_capture', 'pi_2' => 'error' ), '', 'pi_1', 'pi_1' ), 'the paying attempt; another one\'s error is only logged' );
		assert_same( '', $p::sync_result( array( 'pi_x' => '' ), 'pi_x', '', 'pi_2' ), 'not an attempt of this order' );
		assert_same( '', $p::sync_result( array(), '', '', '' ), 'nothing to check' );
	}
);

test(
	'payments: the hold runs from Stripe\'s authorization, not from when the shop first heard of it',
	static function (): void {
		$intent = static fn( array $fields ): object => json_decode( wp_json_encode( $fields ) );
		$now    = 1790099999;
		assert_same( 1790000100, SMPW_Payments::authorized_at( $intent( array( 'created' => 1790000000, 'latest_charge' => array( 'id' => 'ch_1', 'created' => 1790000100 ) ) ), $now ), 'the charge (expanded)' );
		assert_same( 1790000000, SMPW_Payments::authorized_at( $intent( array( 'created' => 1790000000, 'latest_charge' => 'ch_1' ) ), $now ), 'the PaymentIntent when the charge isn\'t expanded' );
		assert_same( $now, SMPW_Payments::authorized_at( $intent( array( 'id' => 'pi_1' ) ), $now ), 'no time from Stripe' );
	}
);

test(
	'payments: a capture retry chain waits 5 min, 30 min, 2 h — then it is over',
	static function (): void {
		assert_same( 300, SMPW_Payments::retry_delay( 1 ) );
		assert_same( 1800, SMPW_Payments::retry_delay( 2 ) );
		assert_same( 7200, SMPW_Payments::retry_delay( 3 ) );
		assert_same( 0, SMPW_Payments::retry_delay( 4 ), 'the third retry is the last' );
		assert_same( 0, SMPW_Payments::retry_delay( 0 ) );
	}
);

test(
	'payments: transient trouble (a later try may work) — network, 409, 429, 5xx, the Stripe plugin briefly without keys; not a refusal',
	static function (): void {
		$p     = 'SMPW_Payments';
		$error = static fn( int $status, string $code = '' ): WP_Error => SMPW_Stripe::parse(
			array(
				'code' => $status,
				'body' => wp_json_encode( array( 'error' => array( 'code' => $code, 'message' => 'x' ) ) ),
			)
		);
		assert_true( $p::transient( SMPW_Stripe::parse( new WP_Error( 'http_request_failed', 'cURL error 28' ) ) ), 'network' );
		assert_true( $p::transient( $error( 409, 'idempotency_key_in_use' ) ), '409' );
		assert_true( $p::transient( new WP_Error( 'smpw_stripe', 'in use', array( 'status' => 400, 'code' => 'idempotency_key_in_use', 'retryable' => false ) ) ), 'the in-use code, whatever the status' );
		assert_true( $p::transient( $error( 429, 'rate_limit' ) ), '429' );
		assert_true( $p::transient( $error( 502 ) ), '5xx' );
		assert_true( $p::transient( new WP_Error( 'smpw_not_connected', 'Stripe is not connected in live mode.', array( 'retryable' => false ) ) ), 'not connected' );
		assert_same( false, $p::transient( $error( 400, 'payment_intent_unexpected_state' ) ), 'a refusal' );
		assert_same( false, $p::transient( $error( 402, 'card_declined' ) ) );
		assert_same( false, $p::transient( new WP_Error( 'smpw_stripe', 'Keys for idempotent requests can only be used with the same parameters', array( 'status' => 400, 'type' => 'idempotency_error', 'retryable' => false ) ) ), 'a key reused with other parameters' );
	}
);

test(
	'return: the return URL carries order, key and attempt',
	static function (): void {
		assert_same( 'https://shop.example/?wc-api=smpw_return&order=5&key=wc_order_x%2By&attempt=3', SMPW_Return::build_url( 'https://shop.example', 5, 'wc_order_x+y', 3 ) );
	}
);
