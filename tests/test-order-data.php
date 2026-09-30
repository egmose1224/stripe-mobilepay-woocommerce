<?php
declare( strict_types = 1 );

if ( ! class_exists( 'WC_Order' ) ) {
	/** Just enough of WC_Order for the order-data tests: meta in memory. */
	class WC_Order {
		public array $meta   = array();
		public int $saves    = 0;
		public function get_meta( string $key ) {
			return $this->meta[ $key ][0] ?? '';
		}
		public function update_meta_data( string $key, $value ): void {
			$this->meta[ $key ] = array( $value );
		}
		public function add_meta_data( string $key, $value ): void {
			$this->meta[ $key ][] = $value;
		}
		public function delete_meta_data( string $key ): void {
			unset( $this->meta[ $key ] );
		}
		public function save(): int {
			return ++$this->saves;
		}
	}
}

test(
	'order data: attempts, current and paying attempt, roles',
	static function (): void {
		$order = new WC_Order();
		$data  = new SMPW_Order_Data( $order );
		assert_same( 1, $data->next_attempt() );
		$data->add_attempt( 'pi_a', 1, 66300, 'test' );
		$data->add_attempt( 'pi_b', 2, 66300, 'test' );
		assert_same( 3, $data->next_attempt() );
		assert_same( 'pi_b', $data->current() );
		assert_same( array( 'pi_a', 'pi_b' ), $order->meta[ SMPW_Order_Data::INTENT_ID ], 'one lookup row per attempt' );
		assert_same( 66300, $data->attempt( 'pi_a' )['amount'] );
		assert_same( null, $data->attempt( 'pi_x' ) );
		// Unpaid order: newest attempt is "current", older ones "other".
		assert_same( 'current', $data->role( 'pi_b', true ) );
		assert_same( 'other', $data->role( 'pi_a', true ) );
		// Authorized by the older attempt: it pays, the other is "other".
		$data->set_paying( 'pi_a' );
		assert_same( 'pi_a', $data->current() );
		assert_same( 'paying', $data->role( 'pi_a', false ) );
		assert_same( 'other', $data->role( 'pi_b', false ) );
	}
);

test(
	'order data: an order paid without a recorded paying attempt adopts the current one',
	static function (): void {
		$data = new SMPW_Order_Data( new WC_Order() );
		$data->add_attempt( 'pi_a', 1, 1000, 'test' );
		assert_same( 'paying', $data->role( 'pi_a', false ) );
	}
);

test(
	'order data: authorization, capture, pre-capture refunds, flags',
	static function (): void {
		$data = new SMPW_Order_Data( new WC_Order() );
		assert_same( false, $data->authorized() );
		$data->mark_authorized( 66300, 1790000000 );
		assert_true( $data->authorized() );
		assert_same( 66300, $data->authorized_amount() );
		assert_same( 1790000000 + 7 * DAY_IN_SECONDS, $data->capture_before() );
		assert_same( false, $data->captured() );
		$data->add_precapture_refund( 10000 );
		$data->add_precapture_refund( 2500 );
		assert_same( 12500, $data->precapture_refunded() );
		$data->mark_captured( 53800 );
		assert_true( $data->captured() );
		assert_same( 53800, $data->captured_amount() );
		assert_same( false, $data->flag( 'x' ) );
		$data->set_flag( 'x' );
		$data->set_flag( 'x' );
		assert_true( $data->flag( 'x' ) );
	}
);

test(
	'order data: a Failed order paid again forgets the gone hold — attempts, status and flags stay',
	static function (): void {
		$data = new SMPW_Order_Data( new WC_Order() );
		$data->add_attempt( 'pi_a', 1, 66300, 'test' );
		$data->set_paying( 'pi_a' );
		$data->mark_authorized( 66300, 1790000000 );
		$data->add_precapture_refund( 10000 );
		$data->mark_captured( 56300 );
		$data->set_status( 'canceled' );
		$data->set_flag( 'hold_gone_pi_a' );
		$data->clear_authorization();
		assert_same( '', $data->paying() );
		assert_same( false, $data->authorized() );
		assert_same( 0, $data->authorized_amount() );
		assert_same( 0, $data->capture_before() );
		assert_same( false, $data->captured() );
		assert_same( 0, $data->captured_amount() );
		assert_same( 0, $data->precapture_refunded(), 'the old hold\'s refund bookkeeping is gone: the new hold is for what is still owed' );
		assert_same( array( 'pi_a' ), array_column( $data->attempts(), 'id' ), 'attempts stay' );
		assert_same( 'pi_a', $data->current() );
		assert_same( 'canceled', $data->status() );
		assert_true( $data->flag( 'hold_gone_pi_a' ), 'flags stay' );
		// Unpaid again: the next attempt is "current", and its approval pays the order.
		assert_same( 2, $data->next_attempt() );
		$data->add_attempt( 'pi_b', 2, 56300, 'test' );
		assert_same( 'current', $data->role( 'pi_b', true ) );
		assert_same( 'other', $data->role( 'pi_a', true ) );
	}
);

test(
	'order data: what a capture was worked out from, the payment lookup, capture tries',
	static function (): void {
		$data = new SMPW_Order_Data( new WC_Order() );
		$data->add_attempt( 'pi_a', 1, 66300, 'test' );
		assert_same( '', $data->payment(), 'nothing authorized or captured' );
		// Captured on the newest attempt of an order marked Completed before its approval was synced (no paying attempt).
		$data->mark_captured( 56300 );
		$data->set_capture_basis( 'pi_a', 10000, array( 71, '72' ) );
		assert_same( 'pi_a', $data->payment(), 'the attempt the capture used' );
		assert_same( 10000, $data->refunded_at_capture() );
		assert_same( array( 71, 72 ), $data->capture_refund_ids() );
		$data->set_paying( 'pi_b' );
		assert_same( 'pi_b', $data->payment(), 'the paying attempt first' );
		assert_same( 1, $data->next_capture_try() );
		assert_same( 2, $data->next_capture_try(), 'every try its own number' );
		assert_same( 1, $data->next_cancel_try(), 'releases count on their own' );
		assert_same( 2, $data->next_cancel_try() );
		assert_same( 1, $data->next_dupe_try(), 'and refunds of duplicates' );
		$data->clear_authorization();
		assert_same( '', $data->payment(), 'a forgotten capture is no payment' );
		assert_same( 3, $data->next_cancel_try(), 'the numbers stay: a key is never used twice' );
		assert_same( 2, $data->next_dupe_try() );
	}
);

test(
	'order data: a hold released at "Completed" with nothing due is settled — never "captured = yes"',
	static function (): void {
		$order = new WC_Order();
		$data  = new SMPW_Order_Data( $order );
		$data->mark_authorized( 66300, 1790000000 );
		$data->mark_released();
		assert_true( $data->captured(), 'settled: the state table never turns the released hold into a failed capture' );
		assert_true( $data->released() );
		assert_same( 0, $data->captured_amount() );
		assert_same( 'released', $order->meta[ SMPW_Order_Data::CAPTURED ][0], 'code reading the meta directly sees no payment: only "yes" means captured' );
		$data->mark_captured( 1000 );
		assert_same( false, $data->released() );
		$data->mark_authorized( 66300, 1790000000 ); // A new authorization starts over.
		assert_same( false, $data->captured() );
	}
);

test(
	'lock: re-entrant within a request, one MySQL lock per order',
	static function (): void {
		$GLOBALS['wpdb'] = new class() {
			public array $sql = array();
			public function prepare( string $query, ...$args ): string {
				return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query ), $args );
			}
			public function get_var( string $query ) {
				$this->sql[] = $query;
				return '1';
			}
			public function query( string $query ) {
				$this->sql[] = $query;
				return 1;
			}
		};
		assert_true( SMPW_Lock::acquire( 42 ) );
		assert_true( SMPW_Lock::acquire( 42 ) );
		SMPW_Lock::release( 42 );
		assert_same( 1, count( $GLOBALS['wpdb']->sql ), 'still held once' );
		SMPW_Lock::release( 42 );
		assert_same( 2, count( $GLOBALS['wpdb']->sql ) );
		assert_contains( 'GET_LOCK', $GLOBALS['wpdb']->sql[0] );
		assert_contains( 'RELEASE_LOCK', $GLOBALS['wpdb']->sql[1] );
		assert_true( strlen( SMPW_Lock::name( 42 ) ) <= 64 );
		assert_same( false, SMPW_Lock::name( 42 ) === SMPW_Lock::name( 43 ) );
	}
);

test(
	'plugin: MobilePay goes first in the payment order, before every other method',
	static function (): void {
		update_option( 'woocommerce_gateway_order', array( 'stripe' => 0, 'bacs' => 3, 'smpw_mobilepay' => 7 ) );
		SMPW_Plugin::put_first();
		$order = get_option( 'woocommerce_gateway_order' );
		assert_same( -1, $order['smpw_mobilepay'] );
		assert_same( 0, $order['stripe'] );
		SMPW_Plugin::put_first();
		assert_same( -1, get_option( 'woocommerce_gateway_order' )['smpw_mobilepay'], 'stable on re-activation' );
	}
);
