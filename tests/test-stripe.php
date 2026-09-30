<?php
declare( strict_types = 1 );

/** A transport that records calls and answers from a queue. */
function smpw_test_client( array &$calls, array $responses, array &$sleeps, string $key_test = 'test-key-123' ): SMPW_Stripe {
	return new SMPW_Stripe(
		static function ( string $method, string $url, array $args ) use ( &$calls, &$responses ) {
			$calls[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
			return array_shift( $responses );
		},
		static fn( string $mode ): string => 'test' === $mode ? $key_test : 'live-key-999',
		static function ( float $seconds ) use ( &$sleeps ): void {
			$sleeps[] = $seconds;
		}
	);
}

function smpw_test_ok( string $body ): array {
	return array( 'code' => 200, 'body' => $body, 'request_id' => 'req_ok' );
}

test(
	'stripe client: create posts form-encoded params with key, version and idempotency key',
	static function (): void {
		$calls  = array();
		$sleeps = array();
		$client = smpw_test_client( $calls, array( smpw_test_ok( '{"id":"pi_1","status":"requires_action"}' ) ), $sleeps );
		$intent = $client->create_intent(
			array( 'amount' => 100, 'currency' => 'dkk', 'payment_method_types' => array( 'mobilepay' ), 'confirm' => true, 'metadata' => array( 'smpw' => '1' ) ),
			'smpw-shop-1-create-1',
			'test'
		);
		assert_same( 'pi_1', $intent->id );
		assert_same( 1, count( $calls ) );
		assert_same( 'POST', $calls[0]['method'] );
		assert_same( 'https://api.stripe.com/v1/payment_intents', $calls[0]['url'] );
		$headers = $calls[0]['args']['headers'];
		assert_same( 'Bearer test-key-123', $headers['Authorization'] );
		assert_same( 'smpw-shop-1-create-1', $headers['Idempotency-Key'] );
		assert_same( SMPW_Stripe::FALLBACK_VERSION, $headers['Stripe-Version'] );
		assert_contains( 'confirm=true', $calls[0]['args']['body'] );
		assert_contains( 'payment_method_types%5B0%5D=mobilepay', $calls[0]['args']['body'] );
		assert_contains( 'metadata%5Bsmpw%5D=1', $calls[0]['args']['body'] );
	}
);

test(
	'stripe client: GET sends params in the query and no idempotency key; live key for live mode',
	static function (): void {
		$calls  = array();
		$sleeps = array();
		$client = smpw_test_client( $calls, array( smpw_test_ok( '{"id":"pi_1","status":"requires_capture"}' ) ), $sleeps );
		$client->retrieve_intent( 'pi_1', 'live' );
		assert_same( 'GET', $calls[0]['method'] );
		assert_same( 'https://api.stripe.com/v1/payment_intents/pi_1?expand%5B0%5D=latest_charge', $calls[0]['url'] );
		assert_same( 'Bearer live-key-999', $calls[0]['args']['headers']['Authorization'] );
		assert_same( false, isset( $calls[0]['args']['headers']['Idempotency-Key'] ) );
		assert_same( null, $calls[0]['args']['body'] );
	}
);

test(
	'stripe client: retries 5xx and network errors with the same key, then succeeds',
	static function (): void {
		$calls     = array();
		$sleeps    = array();
		$responses = array(
			array( 'code' => 503, 'body' => '{"error":{"message":"busy"}}', 'request_id' => 'req_1' ),
			new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ),
			smpw_test_ok( '{"id":"pi_1","status":"succeeded"}' ),
		);
		$client = smpw_test_client( $calls, $responses, $sleeps );
		$result = $client->capture_intent( 'pi_1', 5000, 'smpw-shop-1-capture-pi_1', 'test' );
		assert_same( 'succeeded', $result->status );
		assert_same( 3, count( $calls ) );
		assert_same( array( 'smpw-shop-1-capture-pi_1' ), array_values( array_unique( array_map( static fn( $c ) => $c['args']['headers']['Idempotency-Key'], $calls ) ) ) );
		assert_same( array( 0.5, 1.5 ), $sleeps );
		assert_contains( 'amount_to_capture=5000', $calls[0]['args']['body'] );
	}
);

test(
	'stripe client: a 400 is not retried and carries Stripe\'s details',
	static function (): void {
		$calls  = array();
		$sleeps = array();
		$body   = '{"error":{"type":"invalid_request_error","code":"payment_intent_unexpected_state","message":"This PaymentIntent could not be captured"}}';
		$client = smpw_test_client( $calls, array( array( 'code' => 400, 'body' => $body, 'request_id' => 'req_9' ) ), $sleeps );
		$result = $client->capture_intent( 'pi_1', 5000, 'k', 'test' );
		assert_true( is_wp_error( $result ) );
		assert_same( 'smpw_stripe', $result->get_error_code() );
		assert_same( 'This PaymentIntent could not be captured', $result->get_error_message() );
		$data = $result->get_error_data();
		assert_same( 400, $data['status'] );
		assert_same( 'payment_intent_unexpected_state', $data['code'] );
		assert_same( 'req_9', $data['request_id'] );
		assert_same( false, SMPW_Stripe::retryable( $result ) );
		assert_same( 1, count( $calls ) );
		assert_same( array(), $sleeps );
	}
);

test(
	'stripe client: a 409 (the idempotency key still in use by the same request) is retried with the same key',
	static function (): void {
		$calls     = array();
		$sleeps    = array();
		$responses = array(
			array( 'code' => 409, 'body' => '{"error":{"type":"idempotency_error","code":"idempotency_key_in_use","message":"in use"}}', 'request_id' => 'req_1' ),
			smpw_test_ok( '{"id":"pi_1","status":"canceled"}' ),
		);
		$client = smpw_test_client( $calls, $responses, $sleeps );
		$result = $client->cancel_intent( 'pi_1', 'requested_by_customer', 'smpw-shop-1-cancel-pi_1-requested_by_customer-3', 'test' );
		assert_same( 'canceled', $result->status );
		assert_same( 2, count( $calls ) );
		assert_same( array( 'smpw-shop-1-cancel-pi_1-requested_by_customer-3' ), array_values( array_unique( array_map( static fn( $c ) => $c['args']['headers']['Idempotency-Key'], $calls ) ) ) );
	}
);

test(
	'stripe client: gives up after two retries',
	static function (): void {
		$calls  = array();
		$sleeps = array();
		$fail   = array( 'code' => 500, 'body' => '{"error":{"message":"boom"}}', 'request_id' => 'r' );
		$client = smpw_test_client( $calls, array( $fail, $fail, $fail ), $sleeps );
		$result = $client->cancel_intent( 'pi_1', 'abandoned', 'k', 'test' );
		assert_true( is_wp_error( $result ) );
		assert_true( SMPW_Stripe::retryable( $result ) );
		assert_same( 3, count( $calls ) );
	}
);

test(
	'stripe client: without a key nothing is sent',
	static function (): void {
		$calls  = array();
		$sleeps = array();
		$client = smpw_test_client( $calls, array(), $sleeps, '' );
		$result = $client->retrieve_intent( 'pi_1', 'test' );
		assert_same( 'smpw_not_connected', $result->get_error_code() );
		assert_same( 0, count( $calls ) );
	}
);

test(
	'stripe client: encode writes booleans as true/false and drops nulls',
	static function (): void {
		assert_same( 'a=true&b=false&d%5Bx%5D=true', SMPW_Stripe::encode( array( 'a' => true, 'b' => false, 'c' => null, 'd' => array( 'x' => true ) ) ) );
	}
);

test(
	'stripe client: refunds, disputes and webhook endpoints use the right verb, path and keys',
	static function (): void {
		$calls  = array();
		$sleeps = array();
		$ok     = smpw_test_ok( '{"id":"x"}' );
		$client = smpw_test_client( $calls, array( $ok, $ok, $ok, $ok, $ok ), $sleeps );
		$client->create_refund( array( 'payment_intent' => 'pi_1', 'amount' => 500 ), 'smpw-shop-1-refund-9', 'test' );
		$client->list_refunds( 'pi_1', 'test' );
		$client->retrieve_dispute( 'dp_1', 'test' );
		$client->create_webhook_endpoint( array( 'url' => 'https://shop.test/?wc-api=smpw_webhook', 'enabled_events' => array( 'payment_intent.succeeded' ) ), 'smpw-webhook-1', 'test' );
		$client->delete_webhook_endpoint( 'we_test', 'test' );
		assert_same( array( 'POST', 'GET', 'GET', 'POST', 'DELETE' ), array_column( $calls, 'method' ) );
		assert_same( 'https://api.stripe.com/v1/refunds', $calls[0]['url'] );
		assert_same( 'smpw-shop-1-refund-9', $calls[0]['args']['headers']['Idempotency-Key'] );
		assert_contains( 'payment_intent=pi_1', $calls[0]['args']['body'] );
		assert_same( 'https://api.stripe.com/v1/refunds?payment_intent=pi_1&limit=100', $calls[1]['url'] );
		assert_same( 'https://api.stripe.com/v1/disputes/dp_1', $calls[2]['url'] );
		assert_same( 'https://api.stripe.com/v1/webhook_endpoints', $calls[3]['url'] );
		assert_same( 'smpw-webhook-1', $calls[3]['args']['headers']['Idempotency-Key'] );
		assert_contains( 'enabled_events%5B0%5D=payment_intent.succeeded', $calls[3]['args']['body'] );
		assert_same( 'https://api.stripe.com/v1/webhook_endpoints/we_test', $calls[4]['url'] );
		assert_same( false, isset( $calls[4]['args']['headers']['Idempotency-Key'] ) );
		assert_same( null, $calls[4]['args']['body'] );
	}
);
