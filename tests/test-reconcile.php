<?php
declare( strict_types = 1 );

test(
	'reconcile: only orders whose MobilePay state can still change are swept',
	static function (): void {
		$rows = array(
			array( 'pending', 1, false, false, 'requires_action', true ),
			array( 'pending', 0, false, false, '', false ),
			array( 'on-hold', 1, false, false, 'requires_payment_method', true ),
			array( 'processing', 1, true, false, 'requires_capture', true ),
			array( 'processing', 1, true, true, 'succeeded', false ),
			array( 'processing', 1, true, false, 'canceled', false ),
			array( 'completed', 1, true, false, 'requires_capture', true ),
			array( 'completed', 1, true, true, 'succeeded', false ),
			array( 'cancelled', 1, true, false, 'requires_capture', true ),
			array( 'cancelled', 1, false, false, 'requires_payment_method', true ),
			array( 'cancelled', 1, true, true, 'succeeded', false ),
			array( 'failed', 1, true, false, 'requires_capture', false ),
			array( 'failed', 1, true, false, 'canceled', false ),
			array( 'failed', 2, false, false, 'requires_action', true ),
			array( 'failed', 2, false, false, 'requires_capture', true ),
			array( 'failed', 2, false, false, 'requires_payment_method', true ),
			array( 'failed', 1, false, false, 'canceled', false ),
			array( 'refunded', 1, true, true, 'succeeded', false ),
			array( 'refunded', 1, true, false, 'requires_capture', true ),
			array( 'refunded', 1, false, false, 'requires_action', true ),
			array( 'refunded', 1, true, false, 'canceled', false ),
		);
		foreach ( $rows as [ $status, $attempts, $authorized, $captured, $last, $expected ] ) {
			assert_same( $expected, SMPW_Reconcile::needs_sync( $status, $attempts, $authorized, $captured, $last ), "{$status} / {$last}" . ( $authorized ? ' (authorized)' : '' ) );
		}
		assert_true( SMPW_Reconcile::needs_sync( 'cancelled', 1, true, true, 'succeeded', true ), 'cancelled with money still to refund' );
		assert_same( false, SMPW_Reconcile::needs_sync( 'cancelled', 1, true, true, 'succeeded', false ), 'cancelled and refunded' );
		assert_same( false, SMPW_Reconcile::needs_sync( 'refunded', 1, true, true, 'succeeded', true ), '"Refunded" moves no captured money' );
	}
);
