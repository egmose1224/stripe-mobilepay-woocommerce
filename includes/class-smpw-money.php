<?php
defined( 'ABSPATH' ) || exit;

/**
 * Amounts: WooCommerce's decimal kroner ↔ Stripe's minor units (1/100 krone), and what a capture may take. Pure.
 * The plugin takes DKK only.
 */
final class SMPW_Money {

	/** @param string|float|int $amount Kroner with at most two decimals. */
	public static function to_minor( $amount ): int {
		return (int) round( (float) $amount * 100 );
	}

	public static function from_minor( int $minor ): float {
		return round( $minor / 100, 2 );
	}

	/** "1.234,50 kr" (the Danish way of writing kroner) — for order notes and e-mails. */
	public static function format( int $minor ): string {
		return number_format( $minor / 100, 2, ',', '.' ) . ' kr';
	}

	/**
	 * What may be captured now: never more than the hold, never more than the order still costs.
	 *
	 * @param int $capturable          Stripe's amount_capturable (minor units).
	 * @param int $order_total         The order total now (minor units).
	 * @param int $precapture_refunded Refunds booked before the capture (minor units).
	 */
	public static function capture_amount( int $capturable, int $order_total, int $precapture_refunded ): int {
		return max( 0, min( $capturable, $order_total - $precapture_refunded ) );
	}

	/**
	 * What a capture takes: what the order still costs — its total minus every refund booked in WooCommerce, with
	 * or without the gateway (a refund booked before the capture moved no money) — never more than the hold.
	 *
	 * @param int $capturable     Stripe's amount_capturable (minor units).
	 * @param int $order_total    The order total now (minor units).
	 * @param int $total_refunded WooCommerce's refunded total now (minor units).
	 */
	public static function capture_due( int $capturable, int $order_total, int $total_refunded ): int {
		return max( 0, min( $capturable, $order_total - $total_refunded ) );
	}

	/**
	 * What a cancelled order still has to get back: the capture minus the refunds booked after it (the ones booked
	 * before it only made it smaller), never more than Stripe can still refund.
	 *
	 * @param int $captured            What was captured (minor units).
	 * @param int $total_refunded      WooCommerce's refunded total now (minor units).
	 * @param int $refunded_at_capture WooCommerce's refunded total when the capture was recorded (minor units).
	 * @param int $refundable          What Stripe can still refund: amount_received − the charge's amount_refunded
	 *                                 (minor units).
	 */
	public static function still_to_refund( int $captured, int $total_refunded, int $refunded_at_capture, int $refundable = PHP_INT_MAX ): int {
		return max( 0, min( $captured - max( 0, $total_refunded - $refunded_at_capture ), $refundable ) );
	}
}
