<?php
declare( strict_types = 1 );

test(
	'decision: the state table, row by row',
	static function (): void {
		$d    = 'SMPW_Decision';
		$rows = array(
			// intent status, order status, role, authorized, captured, expected actions.
			array( 'requires_action', 'pending', 'current', false, false, array( $d::WAIT ) ),
			array( 'processing', 'pending', 'current', false, false, array( $d::WAIT ) ),
			array( 'requires_action', 'checkout-draft', 'current', false, false, array( $d::WAIT ) ),
			array( 'requires_action', 'pending', 'other', false, false, array() ),
			array( 'requires_payment_method', 'pending', 'current', false, false, array( $d::NOTE_NOT_COMPLETED ) ),
			array( 'canceled', 'pending', 'current', false, false, array( $d::NOTE_NOT_COMPLETED ) ),
			array( 'requires_payment_method', 'pending', 'other', false, false, array() ),
			array( 'requires_capture', 'pending', 'current', false, false, array( $d::AUTHORIZE ) ),
			array( 'requires_capture', 'pending', 'other', false, false, array( $d::AUTHORIZE ) ),
			array( 'succeeded', 'pending', 'current', false, false, array( $d::AUTHORIZE, $d::MARK_CAPTURED ) ),
			array( 'requires_capture', 'on-hold', 'current', false, false, array( $d::AUTHORIZE ) ),
			array( 'requires_capture', 'processing', 'paying', true, false, array() ),
			array( 'requires_capture', 'on-hold', 'paying', true, false, array() ),
			array( 'requires_capture', 'completed', 'paying', true, false, array( $d::CAPTURE ) ),
			array( 'succeeded', 'processing', 'paying', true, false, array( $d::MARK_CAPTURED ) ),
			array( 'succeeded', 'completed', 'paying', true, false, array( $d::MARK_CAPTURED ) ),
			array( 'succeeded', 'completed', 'paying', true, true, array() ),
			array( 'canceled', 'processing', 'paying', true, false, array( $d::NOTE_HOLD_GONE, $d::EMAIL_SHOP ) ),
			array( 'canceled', 'completed', 'paying', true, false, array( $d::FAIL_CAPTURE ) ),
			array( 'canceled', 'completed', 'paying', true, true, array() ),
			array( 'requires_capture', 'processing', 'other', true, false, array( $d::CANCEL_INTENT, $d::NOTE_DUPLICATE, $d::EMAIL_SHOP ) ),
			array( 'succeeded', 'processing', 'other', true, false, array( $d::REFUND_INTENT, $d::NOTE_DUPLICATE, $d::EMAIL_SHOP ) ),
			array( 'succeeded', 'completed', 'other', true, true, array( $d::REFUND_INTENT, $d::NOTE_DUPLICATE, $d::EMAIL_SHOP ) ),
			array( 'requires_action', 'processing', 'other', true, false, array( $d::CANCEL_INTENT ) ),
			array( 'canceled', 'processing', 'other', true, false, array() ),
			array( 'requires_capture', 'cancelled', 'paying', true, false, array( $d::CANCEL_INTENT, $d::NOTE_RELEASED ) ),
			array( 'requires_capture', 'cancelled', 'current', false, false, array( $d::CANCEL_INTENT, $d::NOTE_RELEASED, $d::EMAIL_SHOP ) ),
			array( 'succeeded', 'cancelled', 'paying', true, true, array( $d::REFUND_ORDER ) ),
			array( 'succeeded', 'cancelled', 'other', true, false, array( $d::REFUND_INTENT, $d::NOTE_DUPLICATE, $d::EMAIL_SHOP ) ),
			array( 'requires_payment_method', 'cancelled', 'current', false, false, array( $d::CANCEL_INTENT ) ),
			array( 'canceled', 'cancelled', 'paying', true, false, array() ),
			array( 'requires_capture', 'refunded', 'paying', true, false, array( $d::CANCEL_INTENT, $d::NOTE_RELEASED ) ),
			array( 'succeeded', 'refunded', 'paying', true, true, array() ),
			array( 'requires_capture', 'failed', 'paying', true, false, array( $d::NOTE_HOLD_ACTIVE ) ),
			array( 'canceled', 'failed', 'paying', true, false, array( $d::NOTE_HOLD_GONE ) ),
			array( 'succeeded', 'failed', 'paying', true, false, array( $d::MARK_CAPTURED ) ),
			array( 'requires_capture', 'failed', 'other', true, false, array( $d::CANCEL_INTENT, $d::NOTE_DUPLICATE, $d::EMAIL_SHOP ) ),
			array( 'requires_capture', 'cancelled', 'other', true, false, array( $d::CANCEL_INTENT, $d::NOTE_DUPLICATE, $d::EMAIL_SHOP ) ),
			array( 'requires_capture', 'cancelled', 'other', false, false, array( $d::CANCEL_INTENT, $d::NOTE_RELEASED, $d::EMAIL_SHOP ) ),
			array( 'succeeded', 'cancelled', 'paying', true, false, array( $d::MARK_CAPTURED, $d::REFUND_ORDER ) ),
			array( 'succeeded', 'refunded', 'paying', true, false, array( $d::MARK_CAPTURED ) ),
			array( 'requires_capture', 'on-hold', 'other', true, false, array( $d::CANCEL_INTENT, $d::NOTE_DUPLICATE, $d::EMAIL_SHOP ) ),
			array( 'succeeded', 'pending', 'other', false, false, array( $d::AUTHORIZE, $d::MARK_CAPTURED ) ),
			// Failed without an authorization (paid again after its hold was gone, or failed by another plugin): unpaid.
			array( 'requires_capture', 'failed', 'current', false, false, array( $d::AUTHORIZE ) ),
			array( 'succeeded', 'failed', 'current', false, false, array( $d::AUTHORIZE, $d::MARK_CAPTURED ) ),
			array( 'requires_action', 'failed', 'current', false, false, array( $d::WAIT ) ),
			array( 'requires_payment_method', 'failed', 'current', false, false, array( $d::NOTE_NOT_COMPLETED ) ),
			array( 'canceled', 'failed', 'other', false, false, array() ),
			// "Refunded": a hold is released and open attempts closed; captured money isn't moved.
			array( 'requires_capture', 'refunded', 'current', false, false, array( $d::CANCEL_INTENT, $d::NOTE_RELEASED ) ),
			array( 'requires_action', 'refunded', 'current', false, false, array( $d::CANCEL_INTENT ) ),
			array( 'requires_capture', 'refunded', 'other', true, false, array( $d::CANCEL_INTENT, $d::NOTE_DUPLICATE, $d::EMAIL_SHOP ) ),
			array( 'succeeded', 'refunded', 'other', true, true, array( $d::REFUND_INTENT, $d::NOTE_DUPLICATE, $d::EMAIL_SHOP ) ),
		);
		foreach ( $rows as $row ) {
			[ $intent, $order, $role, $authorized, $captured, $expected ] = $row;
			assert_same( $expected, $d::decide( $intent, $order, $role, $authorized, $captured ), "{$intent} × {$order} ({$role}" . ( $authorized ? ', authorized' : '' ) . ( $captured ? ', captured' : '' ) . ')' );
		}
	}
);

test(
	'decision: unpaid = pending, draft, or on-hold / failed without an authorization',
	static function (): void {
		assert_true( SMPW_Decision::unpaid( 'pending', false ) );
		assert_true( SMPW_Decision::unpaid( 'pending', true ) );
		assert_true( SMPW_Decision::unpaid( 'checkout-draft', false ) );
		assert_true( SMPW_Decision::unpaid( 'on-hold', false ) );
		assert_same( false, SMPW_Decision::unpaid( 'on-hold', true ) );
		assert_same( false, SMPW_Decision::unpaid( 'processing', false ) );
		assert_true( SMPW_Decision::unpaid( 'failed', false ), 'Failed without an authorization: paid again' );
		assert_same( false, SMPW_Decision::unpaid( 'failed', true ), 'a failed capture: the hold may still be there' );
		assert_same( false, SMPW_Decision::unpaid( 'cancelled', false ) );
		assert_same( false, SMPW_Decision::unpaid( 'refunded', false ) );
	}
);
