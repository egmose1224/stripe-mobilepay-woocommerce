<?php
defined( 'ABSPATH' ) || exit;

/**
 * The state table: what to do when Stripe reports a PaymentIntent status for an order in a given WooCommerce status.
 * Pure — SMPW_Payments::apply() performs the actions in order.
 */
final class SMPW_Decision {

	public const AUTHORIZE          = 'authorize';
	public const MARK_CAPTURED      = 'mark_captured';
	public const CAPTURE            = 'capture';
	public const CANCEL_INTENT      = 'cancel_intent';
	public const REFUND_INTENT      = 'refund_intent';
	public const REFUND_ORDER       = 'refund_order';
	public const FAIL_CAPTURE       = 'fail_capture';
	public const NOTE_NOT_COMPLETED = 'note_not_completed';
	public const NOTE_DUPLICATE     = 'note_duplicate';
	public const NOTE_RELEASED      = 'note_released';
	public const NOTE_HOLD_GONE     = 'note_hold_gone';
	public const NOTE_HOLD_ACTIVE   = 'note_hold_active';
	public const EMAIL_SHOP         = 'email_shop';
	public const WAIT               = 'wait';

	/** PaymentIntent statuses in which an attempt is still open (not authorized, not final). */
	public const OPEN = array( 'requires_action', 'requires_confirmation', 'requires_payment_method', 'processing' );

	/**
	 * @param string $intent_status Stripe PaymentIntent status.
	 * @param string $order_status  WooCommerce order status without "wc-".
	 * @param string $role          'paying' | 'current' | 'other'.
	 * @param bool   $authorized    The order has been authorized at some point.
	 * @param bool   $captured      The order's payment has been captured.
	 * @return string[] Actions, in order.
	 */
	public static function decide( string $intent_status, string $order_status, string $role, bool $authorized, bool $captured ): array {
		$open = in_array( $intent_status, self::OPEN, true );

		// 1. Cancelled/refunded orders: close every attempt, give back what was taken.
		if ( 'cancelled' === $order_status || 'refunded' === $order_status ) {
			if ( 'requires_capture' === $intent_status ) {
				if ( 'other' === $role && $authorized ) {
					return array( self::CANCEL_INTENT, self::NOTE_DUPLICATE, self::EMAIL_SHOP ); // A second hold on an order that had been paid.
				}
				return 'cancelled' === $order_status && ! $authorized
					? array( self::CANCEL_INTENT, self::NOTE_RELEASED, self::EMAIL_SHOP )
					: array( self::CANCEL_INTENT, self::NOTE_RELEASED );
			}
			if ( 'succeeded' === $intent_status ) {
				if ( 'other' === $role ) {
					return array( self::REFUND_INTENT, self::NOTE_DUPLICATE, self::EMAIL_SHOP );
				}
				$actions = $captured ? array() : array( self::MARK_CAPTURED ); // Record a capture made outside the shop first.
				if ( 'cancelled' === $order_status ) {
					$actions[] = self::REFUND_ORDER;
				}
				return $actions;
			}
			return $open ? array( self::CANCEL_INTENT ) : array();
		}

		// 2. Not paid yet: whichever attempt the customer approved pays for the order.
		if ( self::unpaid( $order_status, $authorized ) ) {
			switch ( $intent_status ) {
				case 'requires_capture':
					return array( self::AUTHORIZE );
				case 'succeeded':
					return array( self::AUTHORIZE, self::MARK_CAPTURED );
				case 'requires_payment_method':
				case 'canceled':
					return 'current' === $role ? array( self::NOTE_NOT_COMPLETED ) : array();
				default:
					return 'current' === $role ? array( self::WAIT ) : array();
			}
		}

		// 3. Paid orders: every attempt but the paying one is closed, its money given back.
		if ( 'other' === $role ) {
			if ( 'requires_capture' === $intent_status ) {
				return array( self::CANCEL_INTENT, self::NOTE_DUPLICATE, self::EMAIL_SHOP );
			}
			if ( 'succeeded' === $intent_status ) {
				return array( self::REFUND_INTENT, self::NOTE_DUPLICATE, self::EMAIL_SHOP );
			}
			return $open ? array( self::CANCEL_INTENT ) : array();
		}

		// 4. Failed after a failed capture (authorized): never moved automatically — only told whether the hold lives.
		if ( 'failed' === $order_status ) {
			if ( 'requires_capture' === $intent_status ) {
				return array( self::NOTE_HOLD_ACTIVE );
			}
			if ( $captured ) {
				return array();
			}
			if ( 'succeeded' === $intent_status ) {
				return array( self::MARK_CAPTURED );
			}
			return 'canceled' === $intent_status ? array( self::NOTE_HOLD_GONE ) : array();
		}

		// 5. The paying attempt of an authorized order (Processing, On hold, Completed).
		$shipped = 'completed' === $order_status;
		if ( 'requires_capture' === $intent_status ) {
			return $shipped ? array( self::CAPTURE ) : array();
		}
		if ( $captured ) {
			return array();
		}
		if ( 'succeeded' === $intent_status ) {
			return array( self::MARK_CAPTURED ); // Captured outside the shop (e.g. in the Stripe dashboard): recorded.
		}
		if ( 'canceled' === $intent_status ) {
			return $shipped ? array( self::FAIL_CAPTURE ) : array( self::NOTE_HOLD_GONE, self::EMAIL_SHOP );
		}
		return array();
	}

	/**
	 * Not paid yet: pending, a checkout draft, or "On hold" / "Failed" without an authorization. A failed capture
	 * always has one; a Failed order without one is being paid again (SMPW_Payments::reopen() forgot its gone hold —
	 * it stays Failed meanwhile) or never got a hold (another plugin failed it): an approval pays it.
	 */
	public static function unpaid( string $order_status, bool $authorized ): bool {
		return in_array( $order_status, array( 'pending', 'checkout-draft' ), true ) || ( in_array( $order_status, array( 'on-hold', 'failed' ), true ) && ! $authorized );
	}
}
