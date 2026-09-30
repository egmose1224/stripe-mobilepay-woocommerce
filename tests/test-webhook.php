<?php
declare( strict_types = 1 );

function smpw_test_event( string $type, array $object ): object {
	return json_decode( wp_json_encode( array( 'id' => 'evt_1', 'type' => $type, 'livemode' => false, 'data' => array( 'object' => $object ) ) ) );
}

test(
	'webhook: events are routed to the PaymentIntent they concern',
	static function (): void {
		$w = 'SMPW_Webhook';
		assert_same( array( 'intent', 'pi_a' ), $w::route( smpw_test_event( 'payment_intent.amount_capturable_updated', array( 'id' => 'pi_a' ) ) ) );
		assert_same( array( 'intent', 'pi_a' ), $w::route( smpw_test_event( 'payment_intent.canceled', array( 'id' => 'pi_a' ) ) ) );
		assert_same( array( 'refunds', 'pi_b' ), $w::route( smpw_test_event( 'charge.refunded', array( 'id' => 'ch_1', 'payment_intent' => 'pi_b' ) ) ) );
		assert_same( array( 'refunds', 'pi_b' ), $w::route( smpw_test_event( 'charge.refund.updated', array( 'id' => 're_1', 'payment_intent' => 'pi_b' ) ) ) );
		assert_same( array( 'refunds', 'pi_c' ), $w::route( smpw_test_event( 'refund.created', array( 'id' => 're_2', 'payment_intent' => 'pi_c' ) ) ) );
		assert_same( array( 'refunds', 'pi_c' ), $w::route( smpw_test_event( 'charge.captured', array( 'id' => 'ch_2', 'payment_intent' => 'pi_c' ) ) ) );
		assert_same( array( 'dispute', 'pi_d' ), $w::route( smpw_test_event( 'charge.dispute.created', array( 'id' => 'dp_1', 'payment_intent' => 'pi_d' ) ) ) );
		assert_same( array( 'ignore', '' ), $w::route( smpw_test_event( 'account.updated', array( 'id' => 'acct_test' ) ) ) );
		assert_same( array( 'ignore', '' ), $w::route( json_decode( '{"id":"evt_2","type":"payment_intent.succeeded","data":{}}' ) ) );
	}
);

test(
	'webhook: the endpoint listens to every event the state machine needs, once',
	static function (): void {
		$events = SMPW_Webhook::EVENTS;
		foreach ( array( 'payment_intent.amount_capturable_updated', 'payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled', 'charge.refunded', 'refund.failed', 'charge.dispute.created' ) as $needed ) {
			assert_true( in_array( $needed, $events, true ), $needed );
		}
		assert_same( count( $events ), count( array_unique( $events ) ) );
	}
);
