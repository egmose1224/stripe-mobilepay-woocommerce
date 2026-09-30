<?php
/**
 * Integration test: real WooCommerce orders, a fake Stripe (no money, no network). Run it on a staging site with
 * WooCommerce and this plugin active, from the plugin's folder:
 *   wp eval-file tests/wp-cli/integration.php
 * It creates orders, drives every path, and deletes them again. Mail is suppressed and stock is never touched.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SMPW_Payments' ) ) {
	WP_CLI::error( 'Activate Stripe MobilePay for WooCommerce first.' );
}
require dirname( __DIR__ ) . '/fake-client.php';

$fake = new SMPW_Test_Client();
add_filter( 'smpw_client', static fn() => $fake );
// No mail leaves the test: wp_mail() reports success without sending.
add_filter( 'pre_wp_mail', '__return_true' );
$mails = array(); // Subjects of the (suppressed) e-mails, for the checks.
add_filter(
	'wp_mail',
	static function ( $atts ) use ( &$mails ) {
		$mails[] = (string) ( $atts['subject'] ?? '' );
		return $atts;
	}
);
$captured = array(); // smpw_payment_captured, as fired: [order id, amount in minor units] each.
add_action(
	'smpw_payment_captured',
	static function ( WC_Order $order, int $amount ) use ( &$captured ): void {
		$captured[] = array( $order->get_id(), $amount );
	},
	10,
	2
);

// Test orders never touch stock (real customers may be shopping on the same site).
add_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );
add_filter( 'woocommerce_can_restore_order_stock', '__return_false' );

$product = wc_get_products( array( 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'limit' => 1 ) )[0] ?? null;
if ( ! $product instanceof WC_Product ) {
	WP_CLI::error( 'No simple product in stock.' );
}
$orders = array();
$pass   = 0;
$fail   = 0;

$check     = static function ( string $name, bool $ok, string $detail = '' ) use ( &$pass, &$fail ): void {
	if ( $ok ) {
		++$pass;
		WP_CLI::log( "  ok    {$name}" );
	} else {
		++$fail;
		WP_CLI::log( "  FAIL  {$name}" . ( '' !== $detail ? " — {$detail}" : '' ) );
	}
};
$new_order = static function () use ( $product, &$orders ): WC_Order {
	$order = wc_create_order( array( 'created_via' => 'store-api' ) );
	$order->add_product( $product, 1 );
	$order->set_address(
		array(
			'first_name' => 'Test',
			'last_name'  => 'MobilePay',
			'email'      => 'mobilepay-test@example.invalid',
			'phone'      => '+4512345678',
			'address_1'  => 'Test Street 1',
			'postcode'   => '1000',
			'city'       => 'Copenhagen',
			'country'    => 'DK',
		),
		'billing'
	);
	$order->set_payment_method( 'smpw_mobilepay' );
	$order->calculate_totals();
	$order->set_status( 'pending' );
	$order->save();
	$orders[] = $order->get_id();
	return wc_get_order( $order->get_id() );
};
$fresh    = static fn( WC_Order $o ): WC_Order => wc_get_order( $o->get_id() );
$data     = static fn( WC_Order $o ): SMPW_Order_Data => new SMPW_Order_Data( wc_get_order( $o->get_id() ) );
$notes    = static fn( WC_Order $o ): string => implode( "\n", array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $o->get_id() ) ) ) );
$minor    = static fn( WC_Order $o ): int => SMPW_Money::to_minor( wc_get_order( $o->get_id() )->get_total() );
$captures = static fn( WC_Order $o ): array => array_values( array_filter( $captured, static fn( array $c ): bool => $c[0] === $o->get_id() ) );
$started  = static function ( WC_Order $o ) use ( $data ): string {
	SMPW_Payments::start( $o );
	return $data( $o )->current();
};
$paid     = static function ( WC_Order $o ) use ( $fake, $started ): string {
	$pi = $started( $o );
	$fake->approve( $pi );
	SMPW_Payments::sync( $o, 'test' );
	return $pi;
};

try {
WP_CLI::log( 'A. Approved → Processing → Completed → refunds' );
$o   = $new_order();
$url = SMPW_Payments::start( $o );
$pi  = $data( $o )->current();
$check( 'start returns Stripe\'s redirect URL', str_contains( $url, $pi ) );
$check( 'the attempt is recorded with the order total', 1 === count( $data( $o )->attempts() ) && $minor( $o ) === $data( $o )->attempt( $pi )['amount'] );
$check( 'metadata never uses the Stripe plugin\'s keys', ! isset( $fake->intents[ $pi ]->metadata->order_id ) && '1' === $fake->intents[ $pi ]->metadata->smpw );
$fake->approve( $pi );
SMPW_Payments::sync( $o, 'test' );
$check( 'approved → Processing', 'processing' === $fresh( $o )->get_status(), $fresh( $o )->get_status() );
$check( 'paying attempt and hold recorded', $pi === $data( $o )->paying() && $minor( $o ) === $data( $o )->authorized_amount() );
SMPW_Payments::sync( $o, 'test' );
$check( 'a second sync changes nothing', 'processing' === $fresh( $o )->get_status() && 1 === substr_count( $notes( $o ), 'reserved via MobilePay' ) );
$fresh( $o )->update_status( 'completed' );
$check( 'Completed → captured in full', ( $fake->captures[ $pi ] ?? 0 ) === $minor( $o ) && $data( $o )->captured() );
$check( 'smpw_payment_captured fired once, with the amount', array( array( $o->get_id(), $minor( $o ) ) ) === $captures( $o ) );
$refund = wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => 50, 'reason' => 'test', 'refund_payment' => true ) );
$rid    = is_wp_error( $refund ) ? '' : (string) wc_get_order( $refund->get_id() )->get_meta( SMPW_Order_Data::REFUND_ID );
$check( 'refund button → Stripe refund of 50 kr, id stored', '' !== $rid && 5000 === ( $fake->refunds[ $rid ]->amount ?? 0 ), is_wp_error( $refund ) ? $refund->get_error_message() : '' );
$foreign = $fake->foreign_refund( $pi, 2000 );
SMPW_Payments::sync_refunds( $o );
SMPW_Payments::sync_refunds( $o );
$booked = array_filter( $fresh( $o )->get_refunds(), static fn( $r ) => $foreign === $r->get_meta( SMPW_Order_Data::REFUND_ID ) );
$check( 'a refund made in the Stripe dashboard is booked here, once', 1 === count( $booked ) && 2 === count( $fresh( $o )->get_refunds() ) );

WP_CLI::log( 'B. Declined in the app' );
$o  = $new_order();
$pi = $started( $o );
$fake->decline( $pi );
SMPW_Payments::sync( $o, 'test' );
SMPW_Payments::sync( $o, 'test' );
$check( 'declined → still Pending payment', 'pending' === $fresh( $o )->get_status() );
$check( 'one note, not two', 1 === substr_count( $notes( $o ), 'was not completed' ) );

WP_CLI::log( 'C. A new attempt, and an approval that comes too late' );
$o   = $new_order();
$pi1 = $started( $o );
$fake->decline( $pi1 );
$pi2 = $started( $o );
$check( 'retry = a new attempt; the old one is closed', $pi1 !== $pi2 && 'canceled' === $fake->intents[ $pi1 ]->status );
$fake->approve( $pi2 );
SMPW_Payments::sync( $o, 'test' );
$check( 'the new attempt pays', 'processing' === $fresh( $o )->get_status() && $pi2 === $data( $o )->paying() );
$fake->intents[ $pi1 ]->status            = 'requires_capture';
$fake->intents[ $pi1 ]->amount_capturable = $fake->intents[ $pi1 ]->amount;
SMPW_Payments::sync( $o, 'test', $pi1 );
$check( 'a second hold on a paid order is released at once', 'canceled' === $fake->intents[ $pi1 ]->status && str_contains( $notes( $o ), 'already paid' ) );

WP_CLI::log( 'D. Cancelled after the approval' );
$o  = $new_order();
$pi = $paid( $o );
$fresh( $o )->update_status( 'cancelled' );
$check( 'Cancelled → the hold is released', 'canceled' === $fake->intents[ $pi ]->status && str_contains( $notes( $o ), 'has been released' ) );

WP_CLI::log( 'D2. Refunded (set by hand) after the approval' );
$o  = $new_order();
$pi = $paid( $o );
$check( 'the Stripe plugin never settles a payment on a MobilePay order', array() === apply_filters( 'wc_stripe_allowed_payment_processing_statuses', array( 'pending', 'failed' ), $fresh( $o ) ) );
$fresh( $o )->update_status( 'refunded' );
$check( 'Refunded → the hold is released, nothing refunded at Stripe', 'canceled' === $fake->intents[ $pi ]->status && str_contains( $notes( $o ), 'because the order was refunded' ) && array() === array_filter( $fake->refunds, static fn( $r ) => $r->payment_intent === $pi ) );

WP_CLI::log( 'D3. Cancelled after the capture' );
$o  = $new_order();
$pi = $paid( $o );
$fresh( $o )->update_status( 'completed' );
$r = wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => 10, 'reason' => 'test', 'refund_payment' => true ) );
$check( 'captured, then 10 kr refunded', ( $fake->captures[ $pi ] ?? 0 ) === $minor( $o ) && ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
$mails = array();
$fresh( $o )->update_status( 'cancelled' ); // The rest is refunded → nothing left → WooCommerce sets Refunded; its sync runs inside the cancel's.
$sent_back = static fn(): int => array_sum( array_map( static fn( $r ) => (int) $r->amount, array_filter( $fake->refunds, static fn( $r ) => $r->payment_intent === $pi ) ) );
$check( 'Cancelled → the rest is refunded → WooCommerce sets Refunded', 'refunded' === $fresh( $o )->get_status() && $minor( $o ) === $sent_back() && $minor( $o ) === SMPW_Money::to_minor( $fresh( $o )->get_total_refunded() ) && 2 === count( $fresh( $o )->get_refunds() ), $fresh( $o )->get_status() . ' / ' . $sent_back() );
$check( 'the rest\'s refund noted once', 1 === substr_count( $notes( $o ), SMPW_Money::format( $minor( $o ) - 1000 ) . ' has been refunded via MobilePay' ) );
$before = $notes( $o );
SMPW_Payments::sync_with_refunds( $fresh( $o ), 'test' ); // "Sync with Stripe" / Stripe's charge.refunded.
SMPW_Payments::sync( $fresh( $o ), 'test', $pi );
$mp_mails = array_filter( $mails, static fn( string $subject ): bool => str_contains( $subject, 'MobilePay' ) );
$check( 'nothing repeated: no new note, no second refund, no MobilePay e-mail', $before === $notes( $o ) && $minor( $o ) === $sent_back() && 2 === count( $fresh( $o )->get_refunds() ) && array() === $mp_mails, implode( ' | ', $mp_mails ) );

WP_CLI::log( 'E. Partial refund before the capture' );
$o  = $new_order();
$pi = $paid( $o );
$r  = wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => 10, 'reason' => 'test', 'refund_payment' => true ) );
$check( 'refund before capture is booked against the capture', ! is_wp_error( $r ) && 1000 === $data( $o )->precapture_refunded() );
$fresh( $o )->update_status( 'completed' );
$check( 'Completed captures the total minus the refund', ( $fake->captures[ $pi ] ?? 0 ) === $minor( $o ) - 1000 );

WP_CLI::log( 'F. Full refund before the capture' );
$o  = $new_order();
$pi = $paid( $o );
$r  = wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => SMPW_Money::from_minor( $minor( $o ) ), 'reason' => 'test', 'refund_payment' => true ) );
$check( 'a full refund before capture releases the hold', ! is_wp_error( $r ) && 'canceled' === $fake->intents[ $pi ]->status );
$check( 'WooCommerce sets Refunded itself', 'refunded' === $fresh( $o )->get_status() );
$mails = array();
$fresh( $o )->update_status( 'completed' ); // Shipped anyway.
$mp_mails = array_filter( $mails, static fn( string $subject ): bool => str_contains( $subject, 'MobilePay' ) );
$check( 'then Completed: settled with nothing taken — not Failed, no MobilePay e-mail, no capture recorded', 'completed' === $fresh( $o )->get_status() && $data( $o )->released() && array() === $captures( $o ) && array() === $mp_mails, $fresh( $o )->get_status() . ' | ' . implode( ' | ', $mp_mails ) );

WP_CLI::log( 'G. The hold expires before shipping' );
$o  = $new_order();
$pi = $paid( $o );
$fake->expire( $pi );
SMPW_Payments::sync( $o, 'test' );
$check( 'hold gone before shipping → note, status kept', 'processing' === $fresh( $o )->get_status() && str_contains( $notes( $o ), 'no longer exists' ) );
$fresh( $o )->update_status( 'completed' );
$check( 'Completed without a hold → Failed, no capture recorded', 'failed' === $fresh( $o )->get_status() && array() === $captures( $o ) );

WP_CLI::log( 'G2. Failed — the customer pays again with MobilePay (the order-pay link)' );
$mails = array();
$pi2   = $started( $o ); // WooCommerce's order-pay link calls process_payment() on the Failed order as it is.
$check( 'the hold gone → a new attempt; the order stays Failed (unauthorized: unpaid), no e-mail', $pi2 !== $pi && 'failed' === $fresh( $o )->get_status() && ! $data( $o )->authorized() && str_contains( $notes( $o ), 'the customer is paying the order again with MobilePay' ) && array() === $mails, implode( ' | ', $mails ) );
$check( 'WooCommerce\'s unpaid-order timer can\'t cancel it (it takes pending orders only)', ! in_array( $o->get_id(), array_map( 'intval', (array) WC_Data_Store::load( 'order' )->get_unpaid_orders( current_time( 'timestamp' ) + MINUTE_IN_SECONDS ) ), true ) );
$check( 'the new hold covers what is still owed', $minor( $o ) === $data( $o )->attempt( $pi2 )['amount'] );
$fake->approve( $pi2 );
SMPW_Payments::sync( $o, 'test' );
$check( 'the new approval pays the order → Processing', 'processing' === $fresh( $o )->get_status() && $pi2 === $data( $o )->paying() && $minor( $o ) === $data( $o )->authorized_amount() );
$mp_mails = array_filter( $mails, static fn( string $subject ): bool => str_contains( $subject, 'MobilePay' ) );
$check( 'no duplicate note or e-mail', ! str_contains( $notes( $o ), 'already paid' ) && array() === $mp_mails, implode( ' | ', $mp_mails ) );

WP_CLI::log( 'H. A transient error at the capture' );
$o                        = $new_order();
$pi                       = $paid( $o );
$fake->next_capture_error = new WP_Error( 'smpw_network', 'cURL error 28: timeout', array( 'retryable' => true, 'request_id' => '' ) );
$fresh( $o )->update_status( 'completed' );
$check( 'a transient error keeps Completed, records no capture, schedules a retry', 'completed' === $fresh( $o )->get_status() && ! $data( $o )->captured() && array() === $captures( $o ) && as_has_scheduled_action( 'smpw_capture_retry', array( $o->get_id(), 1 ), SMPW_Reconcile::GROUP ) );
SMPW_Payments::capture_retry( $o->get_id(), 1 );
$check( 'the retry captures, and smpw_payment_captured fires once', $data( $o )->captured() && array( array( $o->get_id(), $minor( $o ) ) ) === $captures( $o ) );
as_unschedule_all_actions( 'smpw_capture_retry', array( $o->get_id(), 1 ), SMPW_Reconcile::GROUP );

WP_CLI::log( 'I. WooCommerce\'s automatic cancellation of unpaid orders' );
$o  = $new_order();
$pi = $started( $o );
$fake->approve( $pi );
$cancel = apply_filters( 'woocommerce_cancel_unpaid_order', true, $fresh( $o ) );
$check( 'an approved order is not auto-cancelled; it becomes Processing', false === $cancel && 'processing' === $fresh( $o )->get_status() );
$o2 = $new_order();
$fake->decline( $started( $o2 ) );
$check( 'an unapproved order may be auto-cancelled', true === apply_filters( 'woocommerce_cancel_unpaid_order', true, $fresh( $o2 ) ) );

WP_CLI::log( 'J. A payment that doesn\'t match' );
$o  = $new_order();
$pi = $started( $o );
$fake->approve( $pi );
$fake->intents[ $pi ]->amount += 100;
SMPW_Payments::sync( $o, 'test' );
$check( 'a PaymentIntent with another amount is ignored', 'pending' === $fresh( $o )->get_status() && str_contains( $notes( $o ), 'does not match' ) );

WP_CLI::log( 'K. Webhook' );
$o  = $new_order();
$pi = $started( $o );
$fake->approve( $pi );
$event = json_decode( wp_json_encode( array( 'id' => 'evt_' . $pi, 'type' => 'payment_intent.amount_capturable_updated', 'livemode' => false, 'data' => array( 'object' => array( 'id' => $pi, 'metadata' => array( 'smpw' => '1' ) ) ) ) ) );
$check( 'webhook: handled → Processing', 'handled' === SMPW_Webhook::process( $event, 'test' ) && 'processing' === $fresh( $o )->get_status() );
$check( 'webhook: the same event again is a duplicate', 'duplicate' === SMPW_Webhook::process( $event, 'test' ) );
$card = json_decode( '{"id":"evt_card_test","type":"payment_intent.succeeded","livemode":false,"data":{"object":{"id":"pi_card","metadata":{"order_id":"1"}}}}' );
$check( 'webhook: a card payment is ignored', 'ignored' === SMPW_Webhook::process( $card, 'test' ) );
delete_transient( 'smpw_evt_' . md5( 'evt_' . $pi ) );

WP_CLI::log( 'L. The customer paid by card instead' );
$o  = $new_order();
$pi = $started( $o );
$fake->approve( $pi );
$switched = $fresh( $o );
$switched->set_payment_method( 'bacs' );
$switched->save();
SMPW_Reconcile::followup( $o->get_id(), $pi );
$check( 'paid another way → the late MobilePay hold is released', 'canceled' === $fake->intents[ $pi ]->status && str_contains( $notes( $o ), 'another payment method' ) );
} finally {
	// Clean up: our follow-ups, refunds, orders.
	foreach ( $orders as $id ) {
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order ) {
			continue;
		}
		foreach ( ( new SMPW_Order_Data( $order ) )->attempts() as $attempt ) {
			as_unschedule_all_actions( 'smpw_followup', array( $id, $attempt['id'] ), SMPW_Reconcile::GROUP );
		}
		foreach ( $order->get_refunds() as $refund ) {
			$refund->delete( true );
		}
		$order->delete( true );
	}
}

WP_CLI::log( "\n{$pass} passed, {$fail} failed" );
if ( $fail > 0 ) {
	WP_CLI::halt( 1 );
}
