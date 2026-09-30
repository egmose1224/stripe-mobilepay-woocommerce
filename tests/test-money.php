<?php
declare( strict_types = 1 );

test(
	'money: kroner to minor units and back',
	static function (): void {
		assert_same( 66300, SMPW_Money::to_minor( '663.00' ) );
		assert_same( 1999, SMPW_Money::to_minor( 19.99 ) );
		assert_same( 10, SMPW_Money::to_minor( '0.1' ) );
		assert_same( 0, SMPW_Money::to_minor( '' ) );
		assert_same( 663.0, SMPW_Money::from_minor( 66300 ) );
		assert_same( 19.99, SMPW_Money::from_minor( 1999 ) );
	}
);

test(
	'money: Danish formatting',
	static function (): void {
		assert_same( '1.234,50 kr', SMPW_Money::format( 123450 ) );
		assert_same( '29,00 kr', SMPW_Money::format( 2900 ) );
	}
);

test(
	'money: capture never exceeds the hold or what the order still costs',
	static function (): void {
		assert_same( 66300, SMPW_Money::capture_amount( 66300, 66300, 0 ) );
		assert_same( 56300, SMPW_Money::capture_amount( 66300, 66300, 10000 ) );
		assert_same( 50000, SMPW_Money::capture_amount( 66300, 50000, 0 ), 'order total lowered after the hold' );
		assert_same( 66300, SMPW_Money::capture_amount( 66300, 70000, 0 ), 'order total raised: only the hold' );
		assert_same( 0, SMPW_Money::capture_amount( 66300, 66300, 66300 ) );
		assert_same( 0, SMPW_Money::capture_amount( 66300, 66300, 90000 ) );
	}
);

test(
	'money: a capture takes what the order still costs — every refund booked, with or without the gateway — never more than the hold',
	static function (): void {
		$due = static fn( int $capturable, int $total, int $refunded ): int => SMPW_Money::capture_due( $capturable, $total, $refunded );
		assert_same( 66300, $due( 66300, 66300, 0 ) );
		assert_same( 56300, $due( 66300, 66300, 10000 ), 'refunded before the capture: through the gateway, or without it (e.g. "Refund manually" in WooCommerce)' );
		assert_same( 56300, $due( 56300, 66300, 10000 ), 'a re-paid order: the new hold is already total − refunded — no double subtraction' );
		assert_same( 51300, $due( 56300, 66300, 15000 ), 'a re-paid order refunded 50 kr more before shipping' );
		assert_same( 50000, $due( 66300, 50000, 0 ), 'order total lowered after the hold' );
		assert_same( 66300, $due( 66300, 70000, 0 ), 'order total raised: only the hold' );
		assert_same( 0, $due( 66300, 66300, 66300 ), 'all refunded: nothing to capture (the hold is released instead)' );
		assert_same( 0, $due( 66300, 66300, 90000 ), 'never negative' );
	}
);

test(
	'money: a cancelled order gets back the capture minus the refunds booked after it — never more than Stripe can refund',
	static function (): void {
		$rest = static fn( int $captured, int $refunded, int $at_capture, int $refundable = PHP_INT_MAX ): int => SMPW_Money::still_to_refund( $captured, $refunded, $at_capture, $refundable );
		assert_same( 56300, $rest( 56300, 10000, 10000 ), 'a refund before the capture only made it smaller: all of the capture back' );
		assert_same( 51300, $rest( 56300, 15000, 10000 ), '50 kr refunded after the capture' );
		assert_same( 0, $rest( 56300, 66300, 10000 ), 'refunded in full already' );
		assert_same( 46300, $rest( 56300, 10000, 10000, 46300 ), 'Stripe has refunded more than is booked here: its limit' );
		assert_same( 0, $rest( 56300, 10000, 10000, 0 ), 'nothing left at Stripe' );
		assert_same( 56300, $rest( 56300, 0, 10000 ), 'a refund deleted after the capture never makes it more than the capture' );
		assert_same( 0, $rest( 0, 0, 0 ), 'nothing captured' );
	}
);
