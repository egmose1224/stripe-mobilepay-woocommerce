<?php
declare( strict_types = 1 );

test(
	'return: where the customer goes after MobilePay',
	static function (): void {
		$rows = array(
			array( 'processing', 'requires_capture', 0, true, 'thanks' ),
			array( 'completed', 'succeeded', 0, true, 'thanks' ),
			array( 'on-hold', 'requires_capture', 0, true, 'thanks' ),
			array( 'on-hold', 'requires_action', 0, false, 'wait' ),
			array( 'on-hold', 'canceled', 0, false, 'failed' ),
			array( 'pending', 'requires_action', 0, false, 'wait' ),
			array( 'pending', 'requires_action', 9, false, 'wait' ),
			array( 'pending', 'requires_action', 10, false, 'pending' ),
			array( 'pending', 'locked', 3, false, 'wait' ),
			array( 'pending', 'error', 10, false, 'pending' ),
			array( 'pending', 'requires_capture', 2, false, 'wait' ),
			array( 'pending', 'requires_payment_method', 0, false, 'failed' ),
			array( 'pending', 'canceled', 0, false, 'failed' ),
			array( 'cancelled', 'requires_capture', 0, false, 'failed' ),
			array( 'failed', 'requires_payment_method', 0, false, 'failed' ),
			// A Failed order paid again (its gone hold forgotten, the status kept): waits like pending.
			array( 'failed', 'requires_action', 0, false, 'wait' ),
			array( 'failed', 'requires_action', 10, false, 'pending' ),
			array( 'failed', 'requires_capture', 0, true, 'failed' ),
		);
		foreach ( $rows as [ $order, $intent, $try, $authorized, $expected ] ) {
			assert_same( $expected, SMPW_Return::outcome( $order, $intent, $try, $authorized ), "{$order} + {$intent} (try {$try}, authorized " . ( $authorized ? 'yes' : 'no' ) . ')' );
		}
	}
);

test(
	'return: an attempt started on the order-pay page says so in its return URL, and a retry goes back there while the order can be paid',
	static function (): void {
		$r = 'SMPW_Return';
		assert_same( 'https://shop.example/?wc-api=smpw_return&order=5&key=wc_order_x&attempt=2&pay=1', $r::build_url( 'https://shop.example/', 5, 'wc_order_x', 2, true ) );
		assert_same( 'https://shop.example/?wc-api=smpw_return&order=5&key=wc_order_x&attempt=2', $r::build_url( 'https://shop.example/', 5, 'wc_order_x', 2 ), 'the checkout: no flag' );
		assert_same( 'order-pay', $r::retry_page( true, true ), 'a Failed order paid again, a payment link: its own payment page (the cart may be empty)' );
		assert_same( 'checkout', $r::retry_page( true, false ), 'no longer payable (e.g. cancelled meanwhile)' );
		assert_same( 'checkout', $r::retry_page( false, true ), 'the checkout: the cart is intact' );
	}
);

test(
	'return: the checkout notice after a failed or unanswered attempt',
	static function (): void {
		$failed = SMPW_Return::notice_html( 'failed' );
		assert_contains( 'is-error', $failed );
		assert_contains( 'role="alert"', $failed );
		assert_contains( 'The MobilePay payment was not completed', $failed );
		$pending = SMPW_Return::notice_html( 'pending' );
		assert_contains( 'is-info', $pending );
		assert_contains( 'We are still waiting for an answer from MobilePay', $pending );
	}
);
