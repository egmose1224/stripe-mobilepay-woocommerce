<?php
defined( 'ABSPATH' ) || exit;

/**
 * The third path into sync(): follow-ups after each attempt (+6 min — just after MobilePay's 5-minute
 * window —, +20 min, +2 h) and an hourly sweep of every MobilePay order that isn't settled yet.
 * Action Scheduler, group "smpw".
 */
final class SMPW_Reconcile {

	public const GROUP = 'smpw';

	public static function hooks(): void {
		add_action( 'smpw_followup', array( __CLASS__, 'followup' ), 10, 2 );
		add_action( 'smpw_sweep', array( __CLASS__, 'sweep' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) ); // Re-creates the sweep if it went missing.
	}

	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'smpw_sweep', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, 'smpw_sweep', array(), self::GROUP );
		}
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'smpw_sweep' ); // Hook only: with array() args plus a group Action Scheduler matches args exactly and cancels nothing.
		}
	}

	public static function schedule_followups( int $order_id, string $intent_id ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		foreach ( array( 6 * MINUTE_IN_SECONDS, 20 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS ) as $delay ) {
			as_schedule_single_action( time() + $delay, 'smpw_followup', array( $order_id, $intent_id ), self::GROUP );
		}
	}

	public static function followup( $order_id, $intent_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! SMPW_Payments::is_ours( $order ) ) {
			SMPW_Payments::close_orphans( $order, 'followup' ); // Paid another way: close our attempts.
			return;
		}
		// This attempt, whatever the order's status: a late second approval on a paid order is released here too.
		SMPW_Payments::sync( $order, 'followup', (string) $intent_id );
	}

	public static function sweep(): void {
		$ids = wc_get_orders(
			array(
				'payment_method' => SMPW_Plugin::GATEWAY_ID,
				'status'         => array( 'wc-pending', 'wc-on-hold', 'wc-processing', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed' ),
				'date_modified'  => '>' . ( time() - 10 * DAY_IN_SECONDS ),
				'limit'          => 200,
				'return'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$data     = new SMPW_Order_Data( $order );
			$refunded = SMPW_Money::to_minor( $order->get_total_refunded() );
			// Money still to give back: the capture minus the refunds booked after it (SMPW_Payments::refund_rest()'s arithmetic).
			$refund_due = SMPW_Money::still_to_refund( $data->captured_amount(), $refunded, $data->refunded_at_capture(), SMPW_Money::to_minor( $order->get_total() ) - $refunded ) > 0;
			if ( self::needs_sync( $order->get_status(), count( $data->attempts() ), $data->authorized(), $data->captured(), $data->status(), $refund_due ) ) {
				SMPW_Payments::sync( $order, 'sweep' );
			}
		}
	}

	/** Pure: can this order's MobilePay state still change? $refund_due: money taken and not yet refunded in full. */
	public static function needs_sync( string $order_status, int $attempts, bool $authorized, bool $captured, string $last_status, bool $refund_due = false ): bool {
		if ( 0 === $attempts || 'canceled' === $last_status ) {
			return false;
		}
		// A hold to release or an attempt still open.
		$held_or_open = in_array( $last_status, array( 'requires_capture', 'requires_action', 'requires_confirmation', 'requires_payment_method', 'processing' ), true );
		switch ( $order_status ) {
			case 'pending':
			case 'on-hold':
				return true;
			case 'failed':
				return ! $authorized; // Paid again after its hold was gone (unpaid, like pending); a failed capture waits for the shop.
			case 'processing':
			case 'completed':
				return $authorized && ! $captured;
			case 'cancelled':
				return $held_or_open || ( 'succeeded' === $last_status && $refund_due );
			case 'refunded':
				return $held_or_open; // "Refunded" moves no captured money (the state table): holds and open attempts only.
			default:
				return false;
		}
	}
}
