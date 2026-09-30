<?php
/**
 * Scenarios: the state machine end to end — start, sync, capture, release, refunds, orphans, disputes — against an
 * in-memory WooCommerce and a fake Stripe (fake-client.php) that can fail on request. No WordPress, no network. From
 * the plugin's folder:
 *
 *   php tests/scenarios.php [name-filter]
 *
 * The in-memory world: orders with meta and notes; WooCommerce refunds, saved before the gateway is asked and deleted
 * again on a WP_Error, a full one setting "Refunded" (as wc_create_refund() does); status hooks; Action Scheduler (args
 * matched exactly); the order lock (busy on request); the smpw_payment_captured actions fired; every Stripe call logged
 * with its idempotency key. Complements run.php (pure units) and wp-cli/integration.php (real WooCommerce).
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fake-client.php';

// ---- In-memory WooCommerce ------------------------------------------------------------------------------------------

$GLOBALS['sc_orders']   = array(); // id => order data.
$GLOBALS['sc_notes']    = array(); // id => notes.
$GLOBALS['sc_mails']    = array();
$GLOBALS['sc_logs']     = array();
$GLOBALS['sc_hooks']    = array();
$GLOBALS['sc_refunds']  = array(); // id => WC_Order_Refund.
$GLOBALS['sc_as']       = array(); // Action Scheduler.
$GLOBALS['sc_cached']   = array(); // id => order data: this request's in-memory copy (an object cache keeps one per request).
$GLOBALS['sc_evicted']  = array(); // Cache keys deleted (wp_cache_delete()), "group:key".
$GLOBALS['sc_captured'] = array(); // The smpw_payment_captured actions fired: [order id, amount in minor units] each.

/** A WooCommerce order: its data lives in $GLOBALS['sc_orders'], an object is a copy until save(). */
class WC_Order {

	public function __construct( public int $id, public array $d ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_status(): string {
		return $this->d['status'];
	}

	public function set_status( string $status ): void {
		$this->d['status'] = $status;
	}

	public function has_status( $status ): bool {
		return in_array( $this->d['status'], (array) $status, true );
	}

	/** Saves, fires woocommerce_order_status_<to> on a change, and notes it. */
	public function update_status( string $to, string $note = '' ): bool {
		$from              = $this->d['status'];
		$this->d['status'] = $to;
		$this->save();
		if ( $from !== $to ) {
			do_action( 'woocommerce_order_status_' . $to, $this->id, $this );
		}
		sc_note( $this->id, trim( $note . ( $from !== $to ? " [status {$from} → {$to}]" : '' ) ) );
		return true;
	}

	public function add_order_note( string $note ): int {
		return sc_note( $this->id, $note );
	}

	public function needs_payment(): bool {
		return in_array( $this->d['status'], array( 'pending', 'failed' ), true ) && (float) $this->d['total'] > 0;
	}

	public function get_total() {
		return $this->d['total'];
	}

	public function get_total_refunded(): float {
		return (float) $this->d['refunded'];
	}

	public function set_transaction_id( string $id ): void {
		$this->d['transaction_id'] = $id;
	}

	public function get_transaction_id(): string {
		return $this->d['transaction_id'];
	}

	public function get_checkout_order_received_url(): string {
		return 'https://shop.test/checkout/order-received/' . $this->id;
	}

	public function get_checkout_payment_url(): string {
		return 'https://shop.test/checkout/order-pay/' . $this->id . '/?pay_for_order=true&key=' . $this->get_order_key();
	}

	public function get_edit_order_url(): string {
		return 'https://shop.test/wp-admin/post.php?post=' . $this->id;
	}

	public function get_order_key(): string {
		return 'wc_order_k' . $this->id;
	}

	public function get_order_number(): string {
		return (string) $this->id;
	}

	public function get_payment_method(): string {
		return $this->d['payment_method'];
	}

	public function set_payment_method( string $method ): void {
		$this->d['payment_method'] = $method;
	}

	/** get_billing_*() from the order's billing data. */
	public function __call( string $name, array $args ) {
		if ( str_starts_with( $name, 'get_billing_' ) ) {
			return (string) ( $this->d['billing'][ substr( $name, 12 ) ] ?? '' );
		}
		throw new Error( 'WC_Order stub lacks ' . $name );
	}

	public function get_meta( string $key ) {
		return $this->d['meta'][ $key ][0] ?? '';
	}

	public function update_meta_data( string $key, $value ): void {
		$this->d['meta'][ $key ] = array( $value );
	}

	public function add_meta_data( string $key, $value ): void {
		$this->d['meta'][ $key ][] = $value;
	}

	public function delete_meta_data( string $key ): void {
		unset( $this->d['meta'][ $key ] );
	}

	/** Saves — and this request's in-memory copy follows its own writes (not another request's: sc_elsewhere()). */
	public function save(): int {
		$GLOBALS['sc_orders'][ $this->id ] = $this->d;
		if ( isset( $GLOBALS['sc_cached'][ $this->id ] ) ) {
			$GLOBALS['sc_cached'][ $this->id ] = $this->d;
		}
		return $this->id;
	}
}

/** WooCommerce's WC_Data, as far as the order lock uses it: the order's meta cache key. */
final class WC_Data {

	public static function generate_meta_cache_key( $id, $cache_group ): string {
		return 'object_meta_' . $id;
	}
}

/** The object cache: deleting an order's meta cache key drops this request's in-memory copy of the order. */
function wp_cache_delete( $key, $group = '' ): bool {
	$GLOBALS['sc_evicted'][] = $group . ':' . $key;
	if ( 'orders' === $group && preg_match( '/^object_meta_(\d+)$/', (string) $key, $m ) ) {
		unset( $GLOBALS['sc_cached'][ (int) $m[1] ] );
	}
	return true;
}

/** This request reads the order now and keeps that copy in memory (as an object cache does). */
function sc_read_early( WC_Order $o ): WC_Order {
	$GLOBALS['sc_cached'][ $o->get_id() ] = $GLOBALS['sc_orders'][ $o->get_id() ];
	return wc_get_order( $o->get_id() );
}

/** Run $fn as another request: its writes reach the database, not this request's in-memory copies. */
function sc_elsewhere( callable $fn ): void {
	$mine                  = $GLOBALS['sc_cached'];
	$GLOBALS['sc_cached'] = array();
	$fn();
	$GLOBALS['sc_cached'] = $mine;
}

/** A WooCommerce refund (shop_order_refund) of an order. */
final class WC_Order_Refund {

	public array $meta = array();

	public function __construct( public int $id, public int $parent, public float $amount ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_parent_id(): int {
		return $this->parent;
	}

	public function get_amount() {
		return $this->amount;
	}

	public function get_meta( string $key ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function save(): int {
		$GLOBALS['sc_refunds'][ $this->id ] = $this;
		return $this->id;
	}
}

function sc_note( int $id, string $note ): int {
	$GLOBALS['sc_notes'][ $id ][] = $note;
	return count( $GLOBALS['sc_notes'][ $id ] );
}

/** A refund, or an order — from this request's in-memory copy when it has one (sc_read_early()), else the database. */
function wc_get_order( $id ) {
	$id = (int) $id;
	if ( isset( $GLOBALS['sc_refunds'][ $id ] ) ) {
		return $GLOBALS['sc_refunds'][ $id ];
	}
	if ( isset( $GLOBALS['sc_cached'][ $id ] ) ) {
		return new WC_Order( $id, $GLOBALS['sc_cached'][ $id ] );
	}
	return isset( $GLOBALS['sc_orders'][ $id ] ) ? new WC_Order( $id, $GLOBALS['sc_orders'][ $id ] ) : false;
}

/** An order's refunds (type shop_order_refund, parent, newest first, limit, return ids) and the sweep's order query. */
function wc_get_orders( array $args ): array {
	if ( 'shop_order_refund' === ( $args['type'] ?? '' ) ) {
		$list = array_values( array_filter( $GLOBALS['sc_refunds'], static fn( $r ) => $r->parent === (int) $args['parent'] ) );
		usort( $list, static fn( $a, $b ) => $b->id <=> $a->id );
		if ( ( $args['limit'] ?? -1 ) > 0 ) {
			$list = array_slice( $list, 0, (int) $args['limit'] );
		}
		return 'ids' === ( $args['return'] ?? '' ) ? array_map( static fn( $r ) => $r->id, $list ) : $list;
	}
	if ( isset( $args['payment_method'] ) ) {
		$statuses = array_map( static fn( $s ) => preg_replace( '/^wc-/', '', $s ), (array) ( $args['status'] ?? array() ) );
		return array_keys( array_filter( $GLOBALS['sc_orders'], static fn( $d ) => $d['payment_method'] === $args['payment_method'] && in_array( $d['status'], $statuses, true ) ) );
	}
	return array();
}

/** One increasing id for every refund, like WooCommerce's; $peek: the id the next refund will get. */
function sc_next_refund_id( bool $peek = false ): int {
	static $next = 5000;
	return $peek ? $next + 1 : ++$next;
}

/**
 * wc_create_refund(): validates, fires woocommerce_create_refund with the refund (no id yet), saves it, then
 * (refund_payment) asks the gateway in the same request; a WP_Error deletes the refund again. A refund that leaves
 * nothing to refund sets the order to "Refunded" itself (woocommerce_order_fully_refunded_status), as WooCommerce does
 * (wc-order-functions.php) — its status hooks (the payments' on_cancelled()) run right there, inside the caller.
 */
function wc_create_refund( array $args ) {
	$order_id = (int) $args['order_id'];
	$amount   = (float) $args['amount'];
	$d        = $GLOBALS['sc_orders'][ $order_id ];
	if ( ! empty( $GLOBALS['sc_fail_wc_refund'] ) ) {
		--$GLOBALS['sc_fail_wc_refund'];
		return new WP_Error( 'error', 'Database error' );
	}
	$remaining = round( (float) $d['total'] - (float) $d['refunded'], 2 ); // get_remaining_refund_amount(), read first.
	if ( $amount < 0 || round( $amount, 2 ) > $remaining ) {
		return new WP_Error( 'error', 'Invalid refund amount.' );
	}
	$refund = new WC_Order_Refund( 0, $order_id, $amount );
	do_action( 'woocommerce_create_refund', $refund, $args );
	$refund->id = sc_next_refund_id();
	$refund->save();
	$GLOBALS['sc_orders'][ $order_id ]['refunded'] += $amount;
	if ( ! empty( $args['refund_payment'] ) ) {
		$result = SMPW_Payments::refund( wc_get_order( $order_id ), $amount, (string) ( $args['reason'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			unset( $GLOBALS['sc_refunds'][ $refund->id ] );
			$GLOBALS['sc_orders'][ $order_id ]['refunded'] -= $amount;
			return $result;
		}
	}
	if ( round( $remaining - $amount, 2 ) <= 0 ) {
		$status = apply_filters( 'woocommerce_order_fully_refunded_status', 'refunded', $order_id, $refund->id );
		if ( $status ) {
			wc_get_order( $order_id )->update_status( $status ); // WooCommerce's order object saves only the status it changes.
		}
	}
	return $refund;
}

/**
 * Only WooCommerce's first half: the refund saved, the gateway not asked yet (another request may run in between).
 * $in_this_request: it is this request's wc_create_refund() for the gateway (woocommerce_create_refund fired here), so
 * the process_refund() that follows is this request's; else it came from elsewhere and nothing here knows about it.
 */
function sc_book_only( WC_Order $o, float $amount, bool $in_this_request = false ): WC_Order_Refund {
	$refund = new WC_Order_Refund( 0, $o->get_id(), $amount );
	if ( $in_this_request ) {
		do_action(
			'woocommerce_create_refund',
			$refund,
			array(
				'order_id'       => $o->get_id(),
				'amount'         => $amount,
				'refund_payment' => true,
			)
		);
	}
	$refund->id = sc_next_refund_id();
	$refund->save();
	$GLOBALS['sc_orders'][ $o->get_id() ]['refunded'] += $amount;
	return $refund;
}

/** Run $fn as another request: its own refunds in progress (Payments' per-request state), not this one's. */
function sc_other_request( callable $fn ): void {
	$booking = new ReflectionProperty( SMPW_Payments::class, 'booking' );
	$mine    = $booking->getValue();
	$booking->setValue( null, array() );
	$fn();
	$booking->setValue( null, $mine );
}

function home_url( string $path = '' ): string {
	return 'https://shop.test' . $path;
}

function wp_parse_url( string $url ) {
	return parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

function wp_date( string $format, $timestamp = null ): string {
	return gmdate( 'Y-m-d H:i', (int) $timestamp );
}

function wp_generate_uuid4(): string {
	return bin2hex( random_bytes( 8 ) );
}

function esc_html( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ): string {
	return (string) $url;
}

function admin_url( string $path = '' ): string {
	return 'https://shop.test/wp-admin/' . $path;
}

function wp_nonce_url( string $url, $action = -1 ): string {
	return $url . '&_wpnonce=test';
}

function wp_mail( $to, $subject, $message ) {
	$GLOBALS['sc_mails'][] = array(
		'subject' => (string) $subject,
		'body'    => (string) $message,
	);
	return true;
}

function wc_get_logger() {
	return new class() {
		public function log( $level, $message, $context ) {
			$GLOBALS['sc_logs'][] = $level . ' ' . $message;
		}
	};
}

function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['sc_hooks'][ $hook ][] = array( $callback, $accepted_args );
}

function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	add_action( $hook, $callback, $priority, $accepted_args );
}

function do_action( string $hook, ...$args ): void {
	foreach ( $GLOBALS['sc_hooks'][ $hook ] ?? array() as [ $callback, $accepted ] ) {
		$callback( ...array_slice( $args, 0, $accepted ) );
	}
}

/** Action Scheduler: args matched exactly; pending and running actions count as scheduled (as_has_scheduled_action()). */
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
	$GLOBALS['sc_as'][] = array(
		'hook'   => $hook,
		'args'   => $args,
		'group'  => $group,
		'status' => 'pending',
	);
	return count( $GLOBALS['sc_as'] );
}

function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['sc_as'] as $action ) {
		if ( $action['hook'] === $hook && in_array( $action['status'], array( 'pending', 'running' ), true ) && ( null === $args || wp_json_encode( $action['args'] ) === wp_json_encode( $args ) ) && ( '' === $group || $action['group'] === $group ) ) {
			return true;
		}
	}
	return false;
}

/** The queue runner: every pending capture retry, once (one scheduled while running waits for the next call). */
function sc_run_retries(): int {
	$ran = 0;
	foreach ( array_keys( $GLOBALS['sc_as'] ) as $i ) {
		if ( 'pending' !== $GLOBALS['sc_as'][ $i ]['status'] || 'smpw_capture_retry' !== $GLOBALS['sc_as'][ $i ]['hook'] ) {
			continue;
		}
		$GLOBALS['sc_as'][ $i ]['status'] = 'running';
		do_action( 'smpw_capture_retry', ...$GLOBALS['sc_as'][ $i ]['args'] );
		$GLOBALS['sc_as'][ $i ]['status'] = 'complete';
		++$ran;
	}
	return $ran;
}

function sc_pending_retries(): array {
	return array_values( array_map( static fn( $a ) => $a['args'], array_filter( $GLOBALS['sc_as'], static fn( $a ) => 'smpw_capture_retry' === $a['hook'] && 'pending' === $a['status'] ) ) );
}

/** The order lock: GET_LOCK answers 1, or 0 while $GLOBALS['sc_lock_busy'] (another request holds it). */
$GLOBALS['wpdb'] = new class() {
	public function prepare( string $query, ...$args ): string {
		return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query ), $args );
	}
	public function get_var( string $query ) {
		return empty( $GLOBALS['sc_lock_busy'] ) ? '1' : '0';
	}
	public function query( string $query ) {
		return 1;
	}
};

/** A listener on smpw_payment_captured (registered by sc_reset()): records [order id, amount in minor units]. */
function sc_on_captured( WC_Order $order, int $amount_minor ): void {
	$GLOBALS['sc_captured'][] = array( $order->get_id(), $amount_minor );
}

/** The order's _smpw_captured meta as code that reads it directly sees it: 'yes' only when a payment was captured. */
function sc_captured_meta( WC_Order $o ): string {
	return (string) ( $GLOBALS['sc_orders'][ $o->get_id() ]['meta'][ SMPW_Order_Data::CAPTURED ][0] ?? '' );
}

// ---- Fake Stripe with failure injection, a key log and a call log ---------------------------------------------------

final class SMPW_Scenario_Client implements SMPW_Client {

	public SMPW_Test_Client $fake;
	/** Every money POST: "<call> <idempotency key>". */
	public array $keys = array();
	/** Every call: "<call> <PaymentIntent id>". */
	public array $calls = array();
	/** The parameters of every PaymentIntent created. */
	public array $created = array();
	/** Per call (create, retrieve, capture, cancel, refund, list): answers to give instead, in order — a WP_Error, an object, 'lost' (done at Stripe, the answer lost) or null (pass). */
	public array $fail = array();
	/** Runs before a cancel reaches Stripe (e.g. the hold expires in between). */
	public $before_cancel = null;
	/**
	 * Stripe's idempotency, when a scenario switches it on: a key's first answer that Stripe keeps — a success or a
	 * 5xx, also one whose answer got lost on the way — is given again for 24 h to the same key; the same key with other
	 * parameters is refused (400). A network error that never reached Stripe keeps nothing.
	 */
	public bool $idempotency = false;
	private array $saved     = array();

	public function __construct() {
		$this->fake = new SMPW_Test_Client();
	}

	private function next( string $method ) {
		return array() !== ( $this->fail[ $method ] ?? array() ) ? array_shift( $this->fail[ $method ] ) : null;
	}

	private function answer( string $method, callable $real, string $key = '', array $params = array() ) {
		$request = $method . ' ' . wp_json_encode( $params );
		if ( $this->idempotency && '' !== $key && isset( $this->saved[ $key ] ) ) {
			return $request === $this->saved[ $key ]['request'] ? $this->saved[ $key ]['answer'] : sc_idempotency_400();
		}
		$next   = $this->next( $method );
		$answer = 'lost' === $next ? $real() : ( $next ?? $real() );
		if ( $this->idempotency && '' !== $key && ( ! is_wp_error( $answer ) || (int) ( $answer->get_error_data()['status'] ?? 0 ) >= 500 ) ) {
			$this->saved[ $key ] = array(
				'request' => $request,
				'answer'  => $answer,
			);
		}
		return 'lost' === $next ? sc_net_error() : $answer;
	}

	public function create_intent( array $params, string $key, string $mode ) {
		$this->keys[]    = 'create ' . $key;
		$this->calls[]   = 'create';
		$this->created[] = $params;
		return $this->answer( 'create', fn() => $this->fake->create_intent( $params, $key, $mode ), $key, $params );
	}

	public function retrieve_intent( string $id, string $mode ) {
		$this->calls[] = 'retrieve ' . $id;
		return $this->answer( 'retrieve', fn() => $this->fake->retrieve_intent( $id, $mode ) );
	}

	public function capture_intent( string $id, int $amount, string $key, string $mode ) {
		$this->keys[]  = 'capture ' . $key;
		$this->calls[] = 'capture ' . $id;
		return $this->answer( 'capture', fn() => $this->fake->capture_intent( $id, $amount, $key, $mode ), $key, array( $id, $amount ) );
	}

	public function cancel_intent( string $id, string $reason, string $key, string $mode ) {
		$this->keys[]  = 'cancel ' . $key;
		$this->calls[] = 'cancel ' . $id;
		if ( $this->before_cancel ) {
			( $this->before_cancel )( $id );
		}
		return $this->answer( 'cancel', fn() => $this->fake->cancel_intent( $id, $reason, $key, $mode ), $key, array( $id, $reason ) );
	}

	public function create_refund( array $params, string $key, string $mode ) {
		$this->keys[]  = 'refund ' . $key;
		$this->calls[] = 'refund ' . $params['payment_intent'];
		return $this->answer( 'refund', fn() => $this->fake->create_refund( $params, $key, $mode ), $key, $params );
	}

	public function list_refunds( string $intent_id, string $mode ) {
		$this->calls[] = 'list ' . $intent_id;
		return $this->answer( 'list', fn() => $this->fake->list_refunds( $intent_id, $mode ) );
	}

	public function retrieve_dispute( string $id, string $mode ) {
		return $this->fake->retrieve_dispute( $id, $mode );
	}

	public function create_webhook_endpoint( array $params, string $key, string $mode ) {
		return $this->fake->create_webhook_endpoint( $params, $key, $mode );
	}

	public function delete_webhook_endpoint( string $id, string $mode ) {
		return $this->fake->delete_webhook_endpoint( $id, $mode );
	}
}

function sc_net_error(): WP_Error {
	return new WP_Error( 'smpw_network', 'cURL error 28', array( 'retryable' => true, 'request_id' => '' ) );
}

function sc_5xx(): WP_Error {
	return new WP_Error( 'smpw_stripe', 'Stripe returned HTTP 500', array( 'status' => 500, 'retryable' => true ) );
}

/** What Stripe answers to a known idempotency key sent with other parameters. */
function sc_idempotency_400(): WP_Error {
	return new WP_Error( 'smpw_stripe', 'Keys for idempotent requests can only be used with the same parameters they were first used with.', array( 'status' => 400, 'type' => 'idempotency_error', 'retryable' => false ) );
}

// ---- Helpers --------------------------------------------------------------------------------------------------------

/** A fresh world: no orders, notes, mails, logs, refunds, captures or scheduled actions; a new fake Stripe in Payments. */
function sc_reset(): SMPW_Scenario_Client {
	foreach ( array( 'sc_orders', 'sc_notes', 'sc_mails', 'sc_logs', 'sc_hooks', 'sc_refunds', 'sc_as', 'sc_cached', 'sc_evicted', 'sc_captured' ) as $global ) {
		$GLOBALS[ $global ] = array();
	}
	$GLOBALS['sc_lock_busy']      = false;
	$GLOBALS['sc_fail_wc_refund'] = 0;
	update_option( 'admin_email', 'shop@example.test' );
	$client = new SMPW_Scenario_Client();
	( new ReflectionProperty( SMPW_Payments::class, 'client' ) )->setValue( null, $client );
	( new ReflectionProperty( SMPW_Payments::class, 'booking' ) )->setValue( null, array() ); // A new request.
	SMPW_Payments::hooks(); // Completed → capture, Cancelled/Refunded → sync, refunds being booked, capture retries.
	add_action( 'smpw_payment_captured', 'sc_on_captured', 10, 2 );
	return $client;
}

/** A pending MobilePay order of 663 kr. */
function sc_order( array $d = array() ): WC_Order {
	static $next = 1000;
	$id                           = ++$next;
	$GLOBALS['sc_orders'][ $id ] = array_merge(
		array(
			'status'         => 'pending',
			'total'          => '663.00',
			'refunded'       => 0.0,
			'payment_method' => 'smpw_mobilepay',
			'transaction_id' => '',
			'meta'           => array(),
			'billing'        => array(
				'first_name' => 'Test',
				'last_name'  => 'Customer',
				'email'      => 'k@example.test',
				'country'    => 'DK',
			),
		),
		$d
	);
	return wc_get_order( $id );
}

function sc_data( WC_Order $o ): SMPW_Order_Data {
	return new SMPW_Order_Data( wc_get_order( $o->get_id() ) );
}

function sc_status( WC_Order $o ): string {
	return wc_get_order( $o->get_id() )->get_status();
}

/** How many of the order's notes contain $needle. */
function sc_count( WC_Order $o, string $needle ): int {
	return count( array_filter( $GLOBALS['sc_notes'][ $o->get_id() ] ?? array(), static fn( $n ) => str_contains( $n, $needle ) ) );
}

/** How many mails contain $needle in the subject or the body. */
function sc_mails( string $needle ): int {
	return count( array_filter( $GLOBALS['sc_mails'], static fn( $m ) => str_contains( $m['subject'] . "\n" . $m['body'], $needle ) ) );
}

/** Start an attempt (the checkout's process_payment()); returns its PaymentIntent id. */
function sc_start( WC_Order $o ): string {
	SMPW_Payments::start( wc_get_order( $o->get_id() ) );
	return sc_data( $o )->current();
}

/** A paid (authorized, Processing) order and its paying attempt. */
function sc_paid( SMPW_Scenario_Client $c ): array {
	$o  = sc_order();
	$pi = sc_start( $o );
	$c->fake->approve( $pi );
	SMPW_Payments::sync( $o, 'test' );
	return array( $o, $pi );
}

/**
 * A paid order with a late second payment on an earlier attempt, captured (a duplicate to give back): the order,
 * the duplicate's PaymentIntent, the paying one.
 */
function sc_duplicate( SMPW_Scenario_Client $c ): array {
	$o   = sc_order();
	$pi1 = sc_start( $o );
	$c->fake->decline( $pi1 );
	$pi2 = sc_start( $o );
	$c->fake->approve( $pi2 );
	SMPW_Payments::sync( $o, 'test' );
	$c->fake->intents[ $pi1 ]->status          = 'succeeded';
	$c->fake->intents[ $pi1 ]->amount_received = $c->fake->intents[ $pi1 ]->amount;
	return array( $o, $pi1, $pi2 );
}

/** The customer message a start() threw, or '' when it didn't throw. */
function sc_throws( callable $fn ): string {
	try {
		$fn();
	} catch ( SMPW_Exception $e ) {
		return $e->getMessage();
	}
	return '';
}

/** WooCommerce's refund button ($gateway) or a refund booked without the gateway ("Refund manually", another plugin). */
function sc_refund( WC_Order $o, float $amount, bool $gateway = true ) {
	return wc_create_refund(
		array(
			'order_id'       => $o->get_id(),
			'amount'         => $amount,
			'reason'         => 't',
			'refund_payment' => $gateway,
		)
	);
}

function sc_refunded( WC_Order $o ): float {
	return (float) $GLOBALS['sc_orders'][ $o->get_id() ]['refunded'];
}

function sc_stripe_refunded( SMPW_Scenario_Client $c, string $pi ): int {
	return array_sum( array_map( static fn( $r ) => $r->amount, array_filter( $c->fake->refunds, static fn( $r ) => $r->payment_intent === $pi ) ) );
}

function sc_wc_refunds( WC_Order $o ): array {
	return wc_get_orders(
		array(
			'type'   => 'shop_order_refund',
			'parent' => $o->get_id(),
		)
	);
}

function sc_completed( WC_Order $o ): void {
	wc_get_order( $o->get_id() )->update_status( 'completed' );
}

function sc_keys( SMPW_Scenario_Client $c, string $prefix ): array {
	return array_values( array_filter( $c->keys, static fn( $k ) => str_starts_with( $k, $prefix ) ) );
}

/** The cancel keys sent for $pi with $reason: "…-cancel-<pi>-<reason>-<try>". */
function sc_cancel_keys( SMPW_Scenario_Client $c, WC_Order $o, string $pi, string $reason ): array {
	return array_values( array_filter( $c->keys, static fn( $k ) => 1 === preg_match( '/^cancel smpw-shop\.test-' . $o->get_id() . '-cancel-' . preg_quote( $pi, '/' ) . '-' . $reason . '-\d+$/', $k ) ) );
}

/** What the Stripe client returns for an HTTP answer (SMPW_Stripe::parse()). */
function sc_http_error( int $status, string $code, string $message ): WP_Error {
	return SMPW_Stripe::parse(
		array(
			'code'       => $status,
			'body'       => wp_json_encode( array( 'error' => array( 'type' => 409 === $status ? 'idempotency_error' : 'invalid_request_error', 'code' => $code, 'message' => $message ) ) ),
			'request_id' => 'req_test',
		)
	);
}

// ==== The order lock =================================================================================================

test(
	'lock: an order read before the lock is read again after it — another request\'s capture is seen, never recorded twice',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		$stale      = sc_read_early( $o );                            // This request (a webhook) loaded the order …
		sc_elsewhere( static fn() => sc_completed( $o ) );           // … while another request captured it at "Completed".
		assert_same( 'processing', $stale->get_status(), 'the early copy is stale' );
		assert_same( 1, sc_count( $o, 'has been captured via MobilePay' ) );
		$GLOBALS['sc_evicted'] = array();
		assert_same( 'succeeded', SMPW_Payments::sync( $stale, 'webhook', $pi ) );
		assert_same( 1, sc_count( $o, 'has been captured via MobilePay' ), 'not recorded a second time' );
		assert_same( 'completed', sc_status( $o ) );
		assert_same( array( 'orders:object_meta_' . $o->get_id(), 'posts:' . $o->get_id(), 'post_meta:' . $o->get_id() ), $GLOBALS['sc_evicted'] );
	}
);

test(
	'lock: only the first acquisition in a request forgets the order — a refund inside a sync (re-entrant) does not',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		$GLOBALS['sc_evicted'] = array();
		wc_get_order( $o->get_id() )->update_status( 'cancelled' ); // sync → REFUND_ORDER → wc_create_refund() → refund() takes the lock again.
		assert_same( 66300, sc_stripe_refunded( $c, $pi ) );
		assert_same( 1, count( array_filter( $GLOBALS['sc_evicted'], static fn( $k ) => str_starts_with( $k, 'orders:object_meta_' ) ) ) );
	}
);

// ==== Cancel and release =============================================================================================

test(
	'cancel: a cancelled order\'s hold is released — noted once, stored status canceled, the key names the reason',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		wc_get_order( $o->get_id() )->update_status( 'cancelled' );
		assert_same( 'canceled', $c->fake->intents[ $pi ]->status );
		assert_same( 1, sc_count( $o, 'has been released because the order was cancelled' ) );
		assert_same( 'canceled', sc_data( $o )->status(), 'the meta box and the sweep see it released' );
		assert_same( 1, count( sc_cancel_keys( $c, $o, $pi, 'requested_by_customer' ) ), 'key: ' . implode( ', ', $c->keys ) );
		SMPW_Payments::sync( $o, 'test' );
		assert_same( 1, sc_count( $o, 'has been released' ), 'never repeated' );
		assert_same( 0, count( $GLOBALS['sc_mails'] ) );
	}
);

test(
	'cancel: a release Stripe refuses is noted and mailed once, never as released; a later sync releases it and says so once',
	static function (): void {
		$c                 = sc_reset();
		[ $o, $pi ]        = sc_paid( $c );
		$c->fail['cancel'] = array( sc_net_error(), sc_net_error() );
		wc_get_order( $o->get_id() )->update_status( 'cancelled' );
		assert_same( 'requires_capture', $c->fake->intents[ $pi ]->status );
		assert_same( 0, sc_count( $o, 'has been released' ), 'no false release note' );
		assert_same( 1, sc_count( $o, 'could not be released automatically' ) );
		assert_same( 1, sc_mails( 'The MobilePay hold could not be released' ), 'mailed although the row has no EMAIL_SHOP' );
		assert_same( 'requires_capture', sc_data( $o )->status(), 'the sweep keeps checking it' );
		assert_same( 'requires_capture', SMPW_Payments::sync( $o, 'sweep' ) );
		assert_same( 1, sc_count( $o, 'could not be released automatically' ), 'failure noted once' );
		assert_same( 1, count( $GLOBALS['sc_mails'] ), 'mailed once' );
		SMPW_Payments::sync( $o, 'sweep' );
		assert_same( 'canceled', $c->fake->intents[ $pi ]->status );
		assert_same( 1, sc_count( $o, 'has been released' ) );
		assert_same( 1, count( $GLOBALS['sc_mails'] ), 'the release itself is noted, not mailed' );
	}
);

test(
	'cancel: every try its own key — a 5xx Stripe saved is never replayed, across a refund before the capture, "Cancelled" and the sweep',
	static function (): void {
		$c                 = sc_reset();
		[ $o, $pi ]        = sc_paid( $c );
		$c->idempotency    = true;
		$c->fail['cancel'] = array( sc_5xx() );
		assert_true( is_wp_error( sc_refund( $o, 663 ) ), 'the release for a full refund before the capture: Stripe answered 500 (and keeps that answer)' );
		assert_same( 'requires_capture', $c->fake->intents[ $pi ]->status );
		wc_get_order( $o->get_id() )->update_status( 'cancelled' ); // The same hold, released by the state table.
		assert_same( 'canceled', $c->fake->intents[ $pi ]->status, 'a new key: not the saved 500' );
		assert_same( 1, sc_count( $o, 'has been released because the order was cancelled' ) );
		$keys = sc_cancel_keys( $c, $o, $pi, 'requested_by_customer' );
		assert_same( 2, count( $keys ) );
		assert_same( 2, count( array_unique( $keys ) ) );
		// The same across "Refunded" (WooCommerce sets it at a full refund), "Completed" with nothing due and the sweep.
		$c                 = sc_reset();
		[ $o, $pi ]        = sc_paid( $c );
		$c->idempotency    = true;
		$c->fail['cancel'] = array( sc_5xx(), sc_5xx() );
		sc_refund( $o, 663, false );
		assert_same( 'refunded', sc_status( $o ) );
		sc_completed( $o );
		assert_same( 'requires_capture', $c->fake->intents[ $pi ]->status );
		SMPW_Payments::sync( $o, 'sweep' );
		assert_same( 'canceled', $c->fake->intents[ $pi ]->status );
		assert_true( sc_data( $o )->released() );
		assert_same( 3, count( array_unique( sc_cancel_keys( $c, $o, $pi, 'requested_by_customer' ) ) ) );
	}
);

test(
	'cancel: a hold that expired between the fetch and the cancel counts as released — no failure note',
	static function (): void {
		$c                = sc_reset();
		[ $o, $pi ]       = sc_paid( $c );
		$c->before_cancel = static function ( string $id ) use ( $c ): void {
			$c->fake->expire( $id );
		};
		wc_get_order( $o->get_id() )->update_status( 'cancelled' );
		assert_same( 0, sc_count( $o, 'could not be released' ) );
		assert_same( 1, sc_count( $o, 'has been released' ) );
		assert_same( 'canceled', sc_data( $o )->status() );
	}
);

// ==== Duplicates: a second approval on a paid order ==================================================================

test(
	'duplicate: a second hold whose release fails — one failure note + mail; released later — the duplicate note + mail, once',
	static function (): void {
		$c   = sc_reset();
		$o   = sc_order();
		$pi1 = sc_start( $o );
		$c->fake->decline( $pi1 );
		$pi2 = sc_start( $o );
		$c->fake->approve( $pi2 );
		SMPW_Payments::sync( $o, 'test' );
		assert_same( 'processing', sc_status( $o ) );
		$c->fake->intents[ $pi1 ]->status            = 'requires_capture';
		$c->fake->intents[ $pi1 ]->amount_capturable = $c->fake->intents[ $pi1 ]->amount;
		$c->fail['cancel']    = array( sc_net_error() );
		$GLOBALS['sc_mails'] = array();
		assert_same( 'requires_capture', SMPW_Payments::sync( $o, 'webhook', $pi1 ), 'the attempt asked about' );
		assert_same( 0, sc_count( $o, 'already paid' ) );
		assert_same( 1, sc_count( $o, 'could not be released automatically' ) );
		assert_same( 1, sc_mails( 'could not be released automatically' ) );
		assert_same( 1, count( $GLOBALS['sc_mails'] ) );
		$c->fail['cancel'] = array( sc_net_error() );
		SMPW_Payments::sync( $o, 'webhook', $pi1 );
		assert_same( 1, count( $GLOBALS['sc_mails'] ), 'no second mail' );
		SMPW_Payments::sync( $o, 'webhook', $pi1 );
		assert_same( 'canceled', $c->fake->intents[ $pi1 ]->status );
		assert_same( 1, sc_count( $o, 'already paid' ) );
		assert_same( 2, count( $GLOBALS['sc_mails'] ) );
		SMPW_Payments::sync( $o, 'webhook', $pi1 );
		assert_same( 1, sc_count( $o, 'already paid' ), 'never repeated' );
		assert_same( 2, count( $GLOBALS['sc_mails'] ) );
		assert_same( 'requires_capture', sc_data( $o )->status(), 'the paying hold keeps its status' );
	}
);

test(
	'duplicate: a captured second payment is refunded once; a refusal is noted + mailed once and retried',
	static function (): void {
		$c   = sc_reset();
		$o   = sc_order();
		$pi1 = sc_start( $o );
		$c->fake->decline( $pi1 );
		$pi2 = sc_start( $o );
		$c->fake->approve( $pi2 );
		SMPW_Payments::sync( $o, 'test' );
		$c->fake->intents[ $pi1 ]->status          = 'succeeded';
		$c->fake->intents[ $pi1 ]->amount_received = $c->fake->intents[ $pi1 ]->amount;
		$c->fail['refund']    = array( new WP_Error( 'smpw_stripe', 'boom', array( 'status' => 500, 'retryable' => true ) ) );
		$GLOBALS['sc_mails'] = array();
		SMPW_Payments::sync( $o, 'webhook', $pi1 );
		assert_same( 0, sc_count( $o, 'already paid' ) );
		assert_same( 1, sc_count( $o, 'could not be refunded automatically' ) );
		assert_same( 1, sc_mails( 'duplicate MobilePay payment' ) );
		assert_same( 1, count( $GLOBALS['sc_mails'] ), 'not mailed twice' );
		SMPW_Payments::sync( $o, 'webhook', $pi1 );
		assert_same( 1, count( $c->fake->refunds ) );
		assert_same( 1, sc_count( $o, 'has been refunded' ) );
		assert_same( 2, count( $GLOBALS['sc_mails'] ) );
		SMPW_Payments::sync( $o, 'webhook', $pi1 );
		assert_same( 1, count( $c->fake->refunds ), 'no second refund call' );
		assert_same( 1, sc_count( $o, 'has been refunded' ) );
		assert_same( 2, count( $GLOBALS['sc_mails'] ) );
	}
);

test(
	'duplicate refund: a 5xx Stripe saved under one try\'s key is never replayed — the next try, its own key, refunds; noted and mailed once',
	static function (): void {
		$c                    = sc_reset();
		[ $o, $pi1 ]          = sc_duplicate( $c );
		$c->idempotency       = true;
		$c->fail['refund']    = array( sc_5xx() );
		$GLOBALS['sc_mails'] = array();
		SMPW_Payments::sync( $o, 'webhook', $pi1 );
		assert_same( 0, sc_stripe_refunded( $c, $pi1 ) );
		assert_same( 1, sc_count( $o, 'could not be refunded automatically' ) );
		SMPW_Payments::sync( $o, 'webhook', $pi1 );
		assert_same( 66300, sc_stripe_refunded( $c, $pi1 ), 'not the saved 500: refunded' );
		assert_same( 1, sc_count( $o, 'already paid' ) );
		$keys = sc_keys( $c, 'refund ' );
		assert_same( 2, count( array_unique( $keys ) ), 'two tries, two keys' );
		foreach ( $keys as $key ) {
			assert_true( 1 === preg_match( '/-dupe-' . preg_quote( $pi1, '/' ) . '-\d+$/', $key ), $key );
		}
		SMPW_Payments::sync( $o, 'sweep' );
		assert_same( 66300, sc_stripe_refunded( $c, $pi1 ), 'never again' );
		assert_same( 1, sc_count( $o, 'already paid' ) );
		assert_same( 2, count( $GLOBALS['sc_mails'] ), 'the failure and the duplicate note, each once' );
	}
);

test(
	'duplicate refund: a lost answer is never followed by a second refund — seen at once, or by the next try\'s fresh check',
	static function (): void {
		// Seen at once: asked again right after the lost answer, Stripe lists the refund.
		$c                    = sc_reset();
		[ $o, $pi1 ]          = sc_duplicate( $c );
		$c->fail['refund']    = array( 'lost' );
		$GLOBALS['sc_mails'] = array();
		SMPW_Payments::sync( $o, 'webhook', $pi1 );
		assert_same( 66300, sc_stripe_refunded( $c, $pi1 ) );
		assert_same( 1, count( $c->fake->refunds ) );
		assert_same( 0, sc_count( $o, 'could not be refunded' ), 'no failure claimed' );
		assert_same( 1, sc_count( $o, 'already paid' ) );
		// Not seen at once (the lookup fails too): refused for now — the next try's fresh check finds it, no second refund.
		$c                 = sc_reset();
		[ $o, $pi1 ]       = sc_duplicate( $c );
		$c->fail['refund'] = array( 'lost' );
		$c->fail['list']   = array( null, sc_net_error() );
		SMPW_Payments::sync( $o, 'webhook', $pi1 );
		assert_same( 1, sc_count( $o, 'could not be refunded automatically' ) );
		SMPW_Payments::sync( $o, 'webhook', $pi1 );
		SMPW_Payments::sync( $o, 'sweep' );
		assert_same( 1, count( $c->fake->refunds ), 'one refund at Stripe' );
		assert_same( 1, count( sc_keys( $c, 'refund ' ) ), 'and no second request' );
		assert_same( 1, sc_count( $o, 'already paid' ) );
		assert_true( sc_data( $o )->flag( 'dupe_' . $pi1 ) );
	}
);

test(
	'duplicate refund: given back in the Stripe dashboard (the charge refunded in full) — done without a refund call, noted once',
	static function (): void {
		$c                                       = sc_reset();
		[ $o, $pi1 ]                             = sc_duplicate( $c );
		$c->fake->intents[ $pi1 ]->latest_charge = (object) array(
			'id'              => 'ch_dupe',
			'amount_refunded' => 66300,
		);
		SMPW_Payments::sync( $o, 'webhook', $pi1 );
		assert_same( array(), sc_keys( $c, 'refund ' ) );
		assert_same( 1, sc_count( $o, 'already paid' ) );
		assert_true( sc_data( $o )->flag( 'dupe_' . $pi1 ) );
	}
);

test(
	'duplicate: two approvals at once — the paying one stays, the other hold is released with one note + mail',
	static function (): void {
		$c                 = sc_reset();
		$o                 = sc_order();
		$pi1               = sc_start( $o );
		$c->fail['cancel'] = array( sc_net_error() );
		$pi2               = sc_start( $o );
		assert_same( 'requires_action', $c->fake->intents[ $pi1 ]->status );
		$c->fake->approve( $pi1 );
		$c->fake->approve( $pi2 );
		$GLOBALS['sc_mails'] = array();
		SMPW_Payments::sync( $o, 'return' );
		assert_same( 'processing', sc_status( $o ) );
		assert_same( $pi2, sc_data( $o )->paying() );
		assert_same( 'canceled', $c->fake->intents[ $pi1 ]->status );
		assert_same( 1, sc_count( $o, 'already paid' ) );
		assert_same( 1, count( $GLOBALS['sc_mails'] ) );
		assert_same( 1, count( sc_cancel_keys( $c, $o, $pi1, 'abandoned' ) ), 'closed as abandoned (refused) …' );
		assert_same( 1, count( sc_cancel_keys( $c, $o, $pi1, 'duplicate' ) ), '… then released as a duplicate, under its own key' );
		SMPW_Payments::sync( $o, 'sweep' );
		assert_same( 1, sc_count( $o, 'already paid' ) );
		assert_same( 1, count( $GLOBALS['sc_mails'] ) );
	}
);

// ==== Paying a Failed order again (WooCommerce's order-pay link) =====================================================

test(
	're-pay: Failed, the hold gone — paid again with MobilePay, the status kept until the approval → Processing, no duplicate',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		$c->fake->expire( $pi );
		SMPW_Payments::sync( $o, 'test' );
		wc_get_order( $o->get_id() )->update_status( 'completed' );
		assert_same( 'failed', sc_status( $o ) );
		$GLOBALS['sc_mails'] = array();
		$notes                = count( $GLOBALS['sc_notes'][ $o->get_id() ] );
		$pi2                  = sc_start( $o );
		assert_true( $pi2 !== $pi );
		assert_same( 'failed', sc_status( $o ), 'no status change: no e-mails, and WooCommerce\'s unpaid timer (pending only) never cancels it' );
		assert_same( 0, count( array_filter( array_slice( $GLOBALS['sc_notes'][ $o->get_id() ], $notes ), static fn( $n ) => str_contains( $n, '[status' ) ) ) );
		assert_same( 1, sc_count( $o, 'no longer exists, so the customer is paying the order again with MobilePay' ) );
		assert_same( false, sc_data( $o )->authorized() );
		assert_same( '', sc_data( $o )->paying() );
		assert_true( SMPW_Decision::unpaid( sc_status( $o ), sc_data( $o )->authorized() ), 'unpaid now' );
		assert_same( 66300, sc_data( $o )->attempt( $pi2 )['amount'] );
		assert_same( 2, sc_count( $o, 'MobilePay started' ) );
		$c->fake->approve( $pi2 );
		assert_same( 'requires_capture', SMPW_Payments::sync( $o, 'return' ), 'the new paying attempt' );
		assert_same( 'processing', sc_status( $o ) );
		assert_same( 1, sc_count( $o, '[status failed → processing]' ) );
		assert_same( $pi2, sc_data( $o )->paying() );
		assert_same( 66300, sc_data( $o )->authorized_amount() );
		assert_same( 0, sc_count( $o, 'already paid' ) );
		assert_same( array(), $GLOBALS['sc_mails'] );
		wc_get_order( $o->get_id() )->update_status( 'completed' );
		assert_same( 66300, $c->fake->captures[ $pi2 ] ?? -1 );
	}
);

test(
	're-pay: started on the order-pay page — the return URL says so (a failed attempt goes back there); from the checkout it doesn\'t',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		assert_same( false, str_contains( $c->created[0]['return_url'], 'pay=1' ), 'the checkout' );
		$c->fake->expire( $pi );
		sc_completed( $o );
		SMPW_Payments::start( wc_get_order( $o->get_id() ), true ); // The gateway on WooCommerce's order-pay form.
		assert_same( 'https://shop.test/?wc-api=smpw_return&order=' . $o->get_id() . '&key=wc_order_k' . $o->get_id() . '&attempt=2&pay=1', $c->created[1]['return_url'] );
		assert_same( 'order-pay', SMPW_Return::retry_page( true, wc_get_order( $o->get_id() )->needs_payment() ), 'Failed: still payable' );
	}
);

test(
	're-pay: abandoned or declined — the order stays Failed (never pending), noted once, and the sweep keeps checking it',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		$c->fake->expire( $pi );
		sc_completed( $o );
		$pi2 = sc_start( $o );
		$c->fake->decline( $pi2 );
		assert_same( 'requires_payment_method', SMPW_Payments::sync( $o, 'return' ) );
		SMPW_Payments::sync( $o, 'followup', $pi2 );
		assert_same( 'failed', sc_status( $o ) );
		assert_same( 1, sc_count( $o, 'was not completed' ) );
		assert_same( 'failed', SMPW_Return::outcome( sc_status( $o ), 'requires_payment_method', 0, false ), 'the customer may try again' );
		$d = sc_data( $o );
		assert_true( SMPW_Reconcile::needs_sync( 'failed', count( $d->attempts() ), $d->authorized(), $d->captured(), $d->status() ), 'a late approval is still picked up' );
		// The customer approves after all (a late webhook, the sweep): the order is paid.
		$c->fake->approve( $pi2 );
		SMPW_Reconcile::sweep();
		assert_same( 'processing', sc_status( $o ) );
		assert_same( $pi2, sc_data( $o )->paying() );
	}
);

test(
	're-pay: Failed with the hold still live — no second hold, the customer gets the start message',
	static function (): void {
		$c                  = sc_reset();
		[ $o, $pi ]         = sc_paid( $c );
		$c->fail['capture'] = array( new WP_Error( 'smpw_stripe', 'amount_too_large', array( 'status' => 400, 'retryable' => false ) ) );
		wc_get_order( $o->get_id() )->update_status( 'completed' );
		assert_same( 'failed', sc_status( $o ) );
		assert_same( 'requires_capture', $c->fake->intents[ $pi ]->status );
		$msg = sc_throws( static fn() => SMPW_Payments::start( wc_get_order( $o->get_id() ) ) );
		assert_same( SMPW_Payments::start_failed_message(), $msg );
		assert_same( 1, count( sc_data( $o )->attempts() ), 'no new attempt' );
		assert_same( 'failed', sc_status( $o ) );
		assert_same( 1, sc_count( $o, 'The customer tried to pay again with MobilePay, but the first hold' ) );
		assert_same( $pi, sc_data( $o )->paying() );
	}
);

test(
	're-pay: Failed but captured meanwhile — recorded, no new hold',
	static function (): void {
		$c                  = sc_reset();
		[ $o, $pi ]         = sc_paid( $c );
		$c->fail['capture'] = array( new WP_Error( 'smpw_stripe', 'declined', array( 'status' => 402, 'retryable' => false ) ) );
		wc_get_order( $o->get_id() )->update_status( 'completed' );
		assert_same( 'failed', sc_status( $o ) );
		$c->fake->capture_intent( $pi, 66300, 'dashboard', 'live' );
		$msg = sc_throws( static fn() => SMPW_Payments::start( wc_get_order( $o->get_id() ) ) );
		assert_same( SMPW_Payments::start_failed_message(), $msg );
		assert_true( sc_data( $o )->captured(), 'the capture is recorded' );
		assert_same( 1, sc_count( $o, 'has already been captured in Stripe' ) );
		assert_same( 1, count( sc_data( $o )->attempts() ) );
	}
);

test(
	're-pay: the paying attempt unreadable — refused, nothing changed',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		$c->fake->expire( $pi );
		wc_get_order( $o->get_id() )->update_status( 'completed' );
		$c->fail['retrieve'] = array( sc_net_error() );
		assert_same( SMPW_Payments::start_failed_message(), sc_throws( static fn() => SMPW_Payments::start( wc_get_order( $o->get_id() ) ) ) );
		assert_same( 'failed', sc_status( $o ) );
		assert_true( sc_data( $o )->authorized() );
		assert_same( 1, sc_count( $o, 'could not be checked with Stripe' ) );
	}
);

test(
	're-pay: after a refund the new hold is total − refunded, and the capture takes exactly that',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		$c->fake->expire( $pi );
		wc_get_order( $o->get_id() )->update_status( 'completed' );
		$GLOBALS['sc_orders'][ $o->get_id() ]['refunded']                                        = 100.0;
		$GLOBALS['sc_orders'][ $o->get_id() ]['meta'][ SMPW_Order_Data::PRECAPTURE ] = array( '10000' );
		$pi2 = sc_start( $o );
		assert_same( 56300, sc_data( $o )->attempt( $pi2 )['amount'] );
		assert_same( 0, sc_data( $o )->precapture_refunded() );
		$c->fake->approve( $pi2 );
		SMPW_Payments::sync( $o, 'test' );
		wc_get_order( $o->get_id() )->update_status( 'completed' );
		assert_same( 56300, $c->fake->captures[ $pi2 ] ?? -1, 'no double subtraction' );
	}
);

test(
	'unpaid: a Failed order that never had a hold (failed by another plugin) is paid by a MobilePay approval → Processing',
	static function (): void {
		$c  = sc_reset();
		$o  = sc_order();
		$pi = sc_start( $o );
		$GLOBALS['sc_orders'][ $o->get_id() ]['status'] = 'failed'; // E.g. a card attempt's late webhook, before the Stripe plugin was kept out.
		$c->fake->approve( $pi );
		assert_same( 'requires_capture', SMPW_Payments::sync( $o, 'webhook', $pi ) );
		assert_same( 'processing', sc_status( $o ) );
		assert_same( $pi, sc_data( $o )->paying() );
		assert_same( 0, sc_count( $o, 'still active' ), 'not treated as a failed capture' );
	}
);

test(
	'stripe plugin: never settles a payment on a MobilePay order; other orders keep its statuses',
	static function (): void {
		sc_reset();
		$statuses = array( 'pending', 'failed' );
		assert_same( array(), SMPW_Payments::stripe_statuses( $statuses, sc_order() ) );
		assert_same( $statuses, SMPW_Payments::stripe_statuses( $statuses, sc_order( array( 'payment_method' => 'stripe' ) ) ) );
		assert_same( $statuses, SMPW_Payments::stripe_statuses( $statuses, null ), 'no order: untouched' );
		assert_true( isset( $GLOBALS['sc_hooks']['wc_stripe_allowed_payment_processing_statuses'] ), 'hooked' );
	}
);

// ==== "Refunded" =====================================================================================================

test(
	'refunded: "Refunded" set by hand releases a live hold — noted once as refunded, nothing mailed',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		wc_get_order( $o->get_id() )->update_status( 'refunded' );
		assert_same( 'canceled', $c->fake->intents[ $pi ]->status );
		assert_same( 1, sc_count( $o, 'has been released because the order was refunded' ) );
		assert_same( 'canceled', sc_data( $o )->status() );
		assert_same( array(), $c->fake->refunds );
		SMPW_Payments::sync( $o, 'sweep' );
		assert_same( 1, sc_count( $o, 'has been released' ), 'never repeated' );
		assert_same( 0, count( $GLOBALS['sc_mails'] ) );
	}
);

test(
	'refunded: captured money isn\'t moved by "Refunded" — as for card orders',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		$c->calls = array();
		wc_get_order( $o->get_id() )->update_status( 'refunded' );
		assert_same( array(), $c->fake->refunds );
		assert_same( array( 'retrieve ' . $pi ), $c->calls, 'looked at, nothing moved' );
		$d = sc_data( $o );
		assert_same( false, SMPW_Reconcile::needs_sync( 'refunded', 1, true, $d->captured(), $d->status(), true ), 'not swept' );
	}
);

test(
	'refunded: a release Stripe refuses at "Refunded" is noted and mailed once, and left to the sweep, which releases it',
	static function (): void {
		$c                 = sc_reset();
		[ $o, $pi ]        = sc_paid( $c );
		$c->fail['cancel'] = array( sc_net_error() );
		wc_get_order( $o->get_id() )->update_status( 'refunded' );
		assert_same( 'requires_capture', $c->fake->intents[ $pi ]->status );
		assert_same( 1, sc_count( $o, 'could not be released automatically' ) );
		assert_same( 1, sc_mails( 'The MobilePay hold could not be released' ) );
		SMPW_Reconcile::sweep();
		assert_same( 'canceled', $c->fake->intents[ $pi ]->status );
		assert_same( 1, sc_count( $o, 'has been released because the order was refunded' ) );
		assert_same( 1, count( $GLOBALS['sc_mails'] ) );
	}
);

// ==== start() and sync() =============================================================================================

test(
	'sync: reports the current attempt, not the oldest; an unreadable current one is an error',
	static function (): void {
		$c   = sc_reset();
		$o   = sc_order();
		$pi1 = sc_start( $o );
		$c->fake->decline( $pi1 );
		$pi2 = sc_start( $o );
		assert_same( 'canceled', $c->fake->intents[ $pi1 ]->status );
		assert_same( 'requires_action', sc_data( $o )->status() );
		assert_same( 'requires_action', SMPW_Payments::sync( $o, 'return' ) );
		$c->fail['retrieve'] = array( sc_net_error() );
		assert_same( 'error', SMPW_Payments::sync( $o, 'return' ) );
		$c->fail['retrieve'] = array( $c->fake->retrieve_intent( $pi2, 'live' ), sc_net_error() );
		assert_same( 'requires_action', SMPW_Payments::sync( $o, 'return' ) );
		assert_same( 'canceled', SMPW_Payments::sync( $o, 'webhook', $pi1 ) );
		assert_same( '', SMPW_Payments::sync( $o, 'webhook', 'pi_unknown' ) );
	}
);

test(
	'start: a failed create never makes the next start() reuse its key',
	static function (): void {
		$c                 = sc_reset();
		$o                 = sc_order();
		$c->fail['create'] = array( sc_5xx() );
		assert_same( SMPW_Payments::start_failed_message(), sc_throws( static fn() => SMPW_Payments::start( wc_get_order( $o->get_id() ) ) ) );
		sc_start( $o );
		assert_same( 2, count( $c->keys ) );
		assert_true( str_starts_with( $c->keys[0], 'create smpw-shop.test-' . $o->get_id() . '-create-1-' ) && str_starts_with( $c->keys[1], 'create smpw-shop.test-' . $o->get_id() . '-create-1-' ) );
		assert_true( $c->keys[0] !== $c->keys[1], 'a new key' );
		assert_same( 1, sc_count( $o, 'MobilePay could not be started: Stripe returned HTTP 500' ) );
		assert_same( 1, sc_count( $o, 'MobilePay started' ) );
	}
);

test(
	'start: lock busy or an unexpected answer — a note + log; "started" only when the customer is sent to MobilePay',
	static function (): void {
		$c                        = sc_reset();
		$o                        = sc_order();
		$GLOBALS['sc_lock_busy'] = true;
		assert_same( SMPW_Payments::start_failed_message(), sc_throws( static fn() => SMPW_Payments::start( wc_get_order( $o->get_id() ) ) ) );
		$GLOBALS['sc_lock_busy'] = false;
		assert_same( 1, sc_count( $o, 'MobilePay could not be started: another process is handling the order' ) );
		$odd                        = array(
			'id'                => 'pi_odd',
			'status'            => 'requires_payment_method',
			'amount'            => 66300,
			'currency'          => 'dkk',
			'amount_capturable' => 0,
			'amount_received'   => 0,
			'metadata'          => (object) array(
				'smpw'          => '1',
				'smpw_site'     => 'shop.test',
				'smpw_order_id' => (string) $o->get_id(),
			),
		);
		$c->fail['create']          = array( (object) $odd );
		$c->fake->intents['pi_odd'] = (object) $odd;
		assert_same( SMPW_Payments::start_failed_message(), sc_throws( static fn() => SMPW_Payments::start( wc_get_order( $o->get_id() ) ) ) );
		assert_same( 0, sc_count( $o, 'MobilePay started' ) );
		assert_same( 1, sc_count( $o, 'Stripe answered "requires_payment_method"' ) );
		assert_same( 2, count( array_filter( $GLOBALS['sc_logs'], static fn( $l ) => str_starts_with( $l, 'error start failed' ) ) ) );
	}
);

test(
	'authorize: the hold runs from Stripe\'s authorization; a repeated sync never moves it',
	static function (): void {
		$c  = sc_reset();
		$o  = sc_order();
		$pi = sc_start( $o );
		$c->fake->approve( $pi );
		$c->fake->intents[ $pi ]->latest_charge = (object) array(
			'id'      => 'ch_1',
			'created' => 1790000000,
		);
		SMPW_Payments::sync( $o, 'test' );
		assert_same( 1790000000 + 7 * DAY_IN_SECONDS, sc_data( $o )->capture_before() );
		SMPW_Payments::sync( $o, 'test' );
		assert_same( 1790000000 + 7 * DAY_IN_SECONDS, sc_data( $o )->capture_before() );
	}
);

// ==== Capture ========================================================================================================

test(
	'capture: a capture made by sync fires smpw_payment_captured once, with the amount captured',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		$GLOBALS['sc_orders'][ $o->get_id() ]['status'] = 'completed';
		SMPW_Payments::sync( $o, 'sweep' );
		assert_true( sc_data( $o )->captured() );
		assert_same( array( array( $o->get_id(), 66300 ) ), $GLOBALS['sc_captured'] );
		SMPW_Payments::sync( $o, 'sweep' );
		SMPW_Payments::capture_retry( $o->get_id(), 1 );
		assert_same( 1, count( $GLOBALS['sc_captured'] ), 'once' );
	}
);

test(
	'capture: nothing due at "Completed", the hold still live (refunded in full without the gateway, the release at "Refunded" left to the sweep) — released, settled, no capture recorded, never Failed',
	static function (): void {
		$c                       = sc_reset();
		[ $o, $pi ]              = sc_paid( $c );
		$GLOBALS['sc_lock_busy'] = true; // WooCommerce sets "Refunded" while the order is busy: its sync is left to the sweep.
		sc_refund( $o, 663, false );
		$GLOBALS['sc_lock_busy'] = false;
		assert_same( 'requires_capture', $c->fake->intents[ $pi ]->status );
		$GLOBALS['sc_mails'] = array();
		sc_completed( $o );
		assert_same( 'canceled', $c->fake->intents[ $pi ]->status );
		assert_same( array(), $c->fake->captures );
		assert_true( sc_data( $o )->captured() && sc_data( $o )->released() );
		assert_same( 0, sc_data( $o )->captured_amount() );
		assert_same( SMPW_Order_Data::RELEASED, sc_captured_meta( $o ), 'code reading the meta directly never sees "yes"' );
		assert_same( array(), $GLOBALS['sc_captured'], 'smpw_payment_captured does not fire' );
		assert_same( 1, sc_count( $o, 'refunded before shipping. The MobilePay hold' ) );
		// Stripe's payment_intent.canceled for our own release, the sweep, a capture retry, "Completed" again:
		SMPW_Payments::sync( $o, 'webhook', $pi );
		SMPW_Payments::sync( $o, 'sweep' );
		SMPW_Payments::capture_retry( $o->get_id(), 1 );
		wc_get_order( $o->get_id() )->update_status( 'processing' );
		sc_completed( $o );
		assert_same( 'completed', sc_status( $o ), 'never FAIL_CAPTURE' );
		assert_same( 0, sc_count( $o, 'Failed' ) );
		assert_same( array(), $GLOBALS['sc_captured'] );
		assert_same( array(), $GLOBALS['sc_mails'] );
		assert_same( 1, sc_count( $o, 'has been released' ), 'noted once' );
		assert_same( false, SMPW_Reconcile::needs_sync( 'completed', 1, true, sc_data( $o )->captured(), sc_data( $o )->status() ), 'the sweep leaves it' );
		// A refund on it moves no money.
		$c->calls = array();
		sc_book_only( $o, 10, true );
		assert_true( true === SMPW_Payments::refund( wc_get_order( $o->get_id() ), 10.0, 't' ) );
		assert_same( array(), $c->calls );
	}
);

test(
	'meta box: a hold released with nothing due reads "Nothing captured (released)", not "Captured: 0,00 kr"; a capture its amount',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_refund( $o, 663, false );
		sc_completed( $o );
		ob_start();
		SMPW_Admin::render( wc_get_order( $o->get_id() ) );
		$box = (string) ob_get_clean();
		assert_contains( 'Nothing captured (released)', $box );
		assert_same( false, str_contains( $box, 'Captured:' ) );
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		ob_start();
		SMPW_Admin::render( wc_get_order( $o->get_id() ) );
		$box = (string) ob_get_clean();
		assert_contains( 'Captured: 663,00 kr', $box );
	}
);

test(
	'capture: the nothing-due release refused — one note + mail, not settled; the sweep releases it, settled once',
	static function (): void {
		$c                       = sc_reset();
		[ $o, $pi ]              = sc_paid( $c );
		$GLOBALS['sc_lock_busy'] = true; // The release at "Refunded" left to the sweep (the order was busy): the hold lives at "Completed".
		sc_refund( $o, 663, false );
		$GLOBALS['sc_lock_busy'] = false;
		$c->fail['cancel']       = array( sc_net_error(), sc_net_error() );
		$GLOBALS['sc_mails']     = array();
		sc_completed( $o );
		assert_same( 'requires_capture', $c->fake->intents[ $pi ]->status );
		assert_same( false, sc_data( $o )->captured() );
		assert_same( 1, sc_count( $o, 'could not be released automatically' ) );
		assert_same( 1, sc_mails( 'The MobilePay hold could not be released' ) );
		assert_true( SMPW_Reconcile::needs_sync( 'completed', 1, true, false, sc_data( $o )->status() ), 'the sweep picks it up' );
		SMPW_Payments::sync( $o, 'sweep' );
		assert_same( 1, sc_count( $o, 'could not be released automatically' ), 'once' );
		assert_same( 1, count( $GLOBALS['sc_mails'] ), 'once' );
		SMPW_Payments::sync( $o, 'sweep' );
		assert_same( 'canceled', $c->fake->intents[ $pi ]->status );
		assert_true( sc_data( $o )->released() );
		assert_same( 1, sc_count( $o, 'has been released' ) );
		assert_same( array(), $GLOBALS['sc_captured'] );
		assert_same( 'completed', sc_status( $o ) );
	}
);

test(
	'capture: refunded in full — WooCommerce sets "Refunded", which releases the hold — then "Completed": settled with nothing taken; never Failed, "contact the customer" or a capture recorded',
	static function (): void {
		foreach ( array( false, true ) as $gateway ) { // Without the gateway (e.g. "Refund manually"), and with it (released at the refund).
			$c          = sc_reset();
			[ $o, $pi ] = sc_paid( $c );
			sc_refund( $o, 663, $gateway );
			assert_same( 'refunded', sc_status( $o ), 'WooCommerce sets "Refunded" itself' );
			assert_same( 'canceled', $c->fake->intents[ $pi ]->status, 'the hold is released' );
			$GLOBALS['sc_mails'] = array();
			$c->calls            = array();
			sc_completed( $o ); // E.g. shipped anyway.
			assert_same( 'completed', sc_status( $o ), 'never FAIL_CAPTURE' );
			assert_true( sc_data( $o )->released(), 'settled with nothing taken' );
			assert_same( 0, sc_data( $o )->captured_amount() );
			assert_same( array( 'retrieve ' . $pi ), $c->calls, 'looked at, nothing moved' );
			assert_same( SMPW_Order_Data::RELEASED, sc_captured_meta( $o ) );
			assert_same( 0, sc_count( $o, 'contact the customer' ) );
			assert_same( 0, sc_count( $o, 'Failed' ) );
			assert_same( 1, sc_count( $o, 'has already been released' ) );
			assert_same( array(), $GLOBALS['sc_mails'] );
			// Stripe's payment_intent.canceled, the sweep, a capture retry, "Completed" again: nothing more.
			SMPW_Payments::sync( $o, 'webhook', $pi );
			SMPW_Reconcile::sweep();
			SMPW_Payments::capture_retry( $o->get_id(), 1 );
			wc_get_order( $o->get_id() )->update_status( 'processing' );
			sc_completed( $o );
			assert_same( 'completed', sc_status( $o ) );
			assert_same( 1, sc_count( $o, 'has already been released' ), 'noted once' );
			assert_same( array(), $GLOBALS['sc_captured'] );
			assert_same( array(), $GLOBALS['sc_mails'] );
		}
	}
);

test(
	'sync: refunded in full and released, "Completed" while the order was busy — Stripe\'s webhook settles it with nothing taken (the state table\'s FAIL_CAPTURE, nothing due); the capture retry leaves it',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_refund( $o, 663, false ); // "Refunded" → released.
		$GLOBALS['sc_mails']     = array();
		$GLOBALS['sc_lock_busy'] = true;
		sc_completed( $o ); // The capture waits for the lock: a retry chain.
		$GLOBALS['sc_lock_busy'] = false;
		assert_same( array( array( $o->get_id(), 1 ) ), sc_pending_retries() );
		assert_same( 'canceled', SMPW_Payments::sync( $o, 'webhook', $pi ) ); // payment_intent.canceled, late.
		assert_same( 'completed', sc_status( $o ), 'not Failed' );
		assert_true( sc_data( $o )->released() );
		assert_same( 1, sc_count( $o, 'has already been released' ) );
		sc_run_retries();
		assert_same( 'completed', sc_status( $o ) );
		assert_same( 1, sc_count( $o, 'has already been released' ), 'noted once' );
		assert_same( 0, sc_count( $o, 'contact the customer' ) );
		assert_same( array(), $GLOBALS['sc_captured'] );
		assert_same( array(), $GLOBALS['sc_mails'] );
	}
);

test(
	'capture: every try its own key — a saved 5xx is never replayed; the retry chain captures',
	static function (): void {
		$c                  = sc_reset();
		[ $o, $pi ]         = sc_paid( $c );
		$c->fail['capture'] = array( sc_5xx() );
		sc_completed( $o );
		assert_same( false, sc_data( $o )->captured() );
		assert_same( array( array( $o->get_id(), 1 ) ), sc_pending_retries() );
		assert_same( 1, sc_run_retries() );
		assert_same( 66300, $c->fake->captures[ $pi ] ?? 0 );
		assert_same( array( array( $o->get_id(), 66300 ) ), $GLOBALS['sc_captured'], 'the retry records the capture' );
		assert_same( array( 'capture smpw-shop.test-' . $o->get_id() . '-capture-' . $pi . '-1', 'capture smpw-shop.test-' . $o->get_id() . '-capture-' . $pi . '-2' ), sc_keys( $c, 'capture ' ) );
	}
);

test(
	'capture: a lost answer is found on the next fetch — recorded once, never captured twice',
	static function (): void {
		$c                  = sc_reset();
		[ $o, $pi ]         = sc_paid( $c );
		$c->fail['capture'] = array( 'lost' );
		sc_completed( $o );
		assert_true( sc_data( $o )->captured() );
		assert_same( 66300, sc_data( $o )->captured_amount() );
		assert_same( 1, sc_count( $o, 'has been captured via MobilePay' ) );
		assert_same( array(), sc_pending_retries() );
	}
);

test(
	'capture: one retry chain per order, noted once; the chain\'s last failure fails the order',
	static function (): void {
		$c                  = sc_reset();
		[ $o, $pi ]         = sc_paid( $c );
		$c->fail['capture'] = array( sc_5xx(), sc_5xx(), sc_5xx() );
		sc_completed( $o );                 // Try 0 → chain [id, 1].
		SMPW_Payments::sync( $o, 'sweep' ); // Another try 0 → no second chain.
		assert_same( array( array( $o->get_id(), 1 ) ), sc_pending_retries() );
		assert_same( 1, sc_count( $o, 'could not be captured right now' ) );
		sc_run_retries();                   // Try 1 fails → [id, 2], no new note.
		assert_same( array( array( $o->get_id(), 2 ) ), sc_pending_retries() );
		assert_same( 1, sc_count( $o, 'could not be captured right now' ) );
		sc_run_retries();                   // Try 2 captures.
		assert_same( 66300, $c->fake->captures[ $pi ] ?? 0 );
		assert_same( array( array( $o->get_id(), 66300 ) ), $GLOBALS['sc_captured'] );
		// A chain whose third retry fails too → Failed + e-mail.
		$c                  = sc_reset();
		[ $o, $pi ]         = sc_paid( $c );
		$c->fail['capture'] = array( sc_5xx(), sc_5xx(), sc_5xx(), sc_5xx() );
		sc_completed( $o );
		sc_run_retries();
		sc_run_retries();
		assert_same( 'completed', sc_status( $o ) );
		sc_run_retries();
		assert_same( 'failed', sc_status( $o ) );
		assert_same( 1, sc_mails( 'MobilePay amount could not be captured' ) );
		assert_same( 1, sc_count( $o, 'could not be captured right now' ) );
	}
);

test(
	'capture: a 409 (the idempotency key busy) and Stripe briefly without keys are transient — the retry chain captures, never Failed',
	static function (): void {
		$busy = sc_http_error( 409, 'idempotency_key_in_use', 'There is currently another in-progress request using this Idempotent Key.' );
		foreach ( array( 'capture' => $busy, 'retrieve' => new WP_Error( 'smpw_not_connected', 'Stripe is not connected in live mode.', array( 'retryable' => false ) ) ) as $call => $error ) {
			$c                = sc_reset();
			[ $o, $pi ]       = sc_paid( $c );
			$c->fail[ $call ] = array( $error );
			sc_completed( $o );
			assert_same( 'completed', sc_status( $o ), "{$call}: not Failed" );
			assert_same( array( array( $o->get_id(), 1 ) ), sc_pending_retries(), "{$call}: a retry is scheduled" );
			assert_same( 1, sc_count( $o, 'could not be captured right now' ) );
			sc_run_retries();
			assert_same( 66300, $c->fake->captures[ $pi ] ?? 0, "{$call}: the retry captures" );
			assert_same( array( array( $o->get_id(), 66300 ) ), $GLOBALS['sc_captured'] );
		}
		assert_true( SMPW_Stripe::retryable( $busy ), 'the client itself retries a 409 with the same key' );
	}
);

test(
	'capture: given up while the hold still lives → Failed with "still active", never "contact the customer"; "Completed" again captures',
	static function (): void {
		$c                  = sc_reset();
		[ $o, $pi ]         = sc_paid( $c );
		$c->fail['capture'] = array( sc_http_error( 400, 'amount_too_large', 'Amount too large' ) );
		$GLOBALS['sc_mails'] = array();
		sc_completed( $o );
		assert_same( 'failed', sc_status( $o ) );
		assert_same( 1, sc_count( $o, 'is still active until' ) );
		assert_same( 1, sc_count( $o, 'mark the order Completed again' ) );
		assert_same( 0, sc_count( $o, 'contact the customer' ) );
		assert_same( 1, sc_mails( 'is still active until' ) );
		assert_same( 0, sc_mails( 'contact the customer' ) );
		SMPW_Payments::sync( $o, 'sweep' ); // The state table's NOTE_HOLD_ACTIVE: said already.
		assert_same( 1, sc_count( $o, 'still active' ) );
		sc_completed( $o );
		assert_same( 66300, $c->fake->captures[ $pi ] ?? 0 );
		assert_same( 'completed', sc_status( $o ) );
	}
);

test(
	'capture: given up and the hold gone → Failed, "contact the customer"; the retry chain\'s last failure too',
	static function (): void {
		$c                  = sc_reset();
		[ $o, $pi ]         = sc_paid( $c );
		$c->fail['capture'] = array( sc_http_error( 400, 'payment_intent_unexpected_state', 'This PaymentIntent could not be captured' ) );
		// The hold expired just as the capture went out: the fetch after the error sees it canceled.
		$gone                = $c->fake->retrieve_intent( $pi, 'live' );
		$gone->status        = 'canceled';
		$c->fail['retrieve'] = array( null, $gone );
		sc_completed( $o );
		assert_same( 'failed', sc_status( $o ) );
		assert_same( 1, sc_count( $o, 'contact the customer about payment' ) );
		assert_same( 0, sc_count( $o, 'still active' ) );
		// Retries used up while Stripe was unreachable, and it still can't be read: no claim that the hold lives.
		$c                  = sc_reset();
		[ $o, $pi ]         = sc_paid( $c );
		$c->fail['capture'] = array( sc_5xx(), sc_5xx(), sc_5xx(), sc_5xx() );
		sc_completed( $o );
		sc_run_retries();
		sc_run_retries();
		$c->fail['retrieve'] = array( null, sc_net_error(), sc_net_error() );
		sc_run_retries();
		assert_same( 'failed', sc_status( $o ) );
		assert_same( 1, sc_count( $o, 'contact the customer about payment' ) );
	}
);

test(
	'capture: "Completed" before the approval was synced — the newest attempt is captured; refund and sync_refunds find it',
	static function (): void {
		$c  = sc_reset();
		$o  = sc_order();
		$pi = sc_start( $o );
		$c->fake->approve( $pi );
		sc_completed( $o ); // Pending → completed, no sync in between.
		assert_same( $pi, sc_data( $o )->paying(), 'recorded as the paying attempt, its authorization with it' );
		assert_true( sc_data( $o )->authorized() );
		assert_same( 0, sc_count( $o, 'The payment has been reserved' ), 'no "Processing" on the way' );
		assert_same( 66300, $c->fake->captures[ $pi ] ?? 0 );
		assert_same( $pi, sc_data( $o )->payment() );
		assert_true( sc_refund( $o, 50 ) instanceof WC_Order_Refund );
		assert_same( 5000, sc_stripe_refunded( $c, $pi ) );
		$c->fake->foreign_refund( $pi, 1000 );
		assert_same( 'ok', SMPW_Payments::sync_refunds( wc_get_order( $o->get_id() ) ) );
		assert_same( 60.0, sc_refunded( $o ), 'the dashboard refund is booked' );
		SMPW_Payments::sync( $o, 'webhook', $pi );
		assert_same( 'completed', sc_status( $o ) );
	}
);

test(
	'capture: "Completed" before the approval was synced, the capture refused while the hold lives — Failed with the hold\'s real expiry; the sweep, a follow-up or a webhook never authorize it again',
	static function (): void {
		$c                                      = sc_reset();
		$o                                      = sc_order();
		$pi                                     = sc_start( $o );
		$c->fake->approve( $pi );
		$c->fake->intents[ $pi ]->latest_charge = (object) array(
			'id'      => 'ch_1',
			'created' => 1790000000, // Stripe's authorization.
		);
		$c->fail['capture']  = array( sc_http_error( 400, 'amount_too_large', 'Amount too large' ) );
		$GLOBALS['sc_mails'] = array();
		sc_completed( $o ); // Pending → completed, no sync in between; Stripe refuses the capture.
		$d = sc_data( $o );
		assert_same( 'failed', sc_status( $o ) );
		assert_same( $pi, $d->paying(), 'recorded as the paying attempt' );
		assert_same( 66300, $d->authorized_amount() );
		assert_same( 1790000000 + 7 * DAY_IN_SECONDS, $d->capture_before(), 'the hold runs from Stripe\'s authorization' );
		assert_same( 1, sc_count( $o, 'is still active until ' . wp_date( 'F j, Y \a\t H:i', 1790000000 + 7 * DAY_IN_SECONDS ) ) );
		assert_same( 0, sc_count( $o, 'unknown' ) );
		assert_same( 0, sc_count( $o, 'The payment has been reserved' ), 'no "Processing"' );
		assert_same( 1, count( $GLOBALS['sc_mails'] ), 'the failed capture\'s mail only' );
		// Within the hour: the sweep, a follow-up, a webhook — none takes the order for unpaid.
		$notes = count( $GLOBALS['sc_notes'][ $o->get_id() ] );
		SMPW_Reconcile::sweep();
		SMPW_Reconcile::followup( $o->get_id(), $pi );
		SMPW_Payments::sync( $o, 'webhook', $pi );
		assert_same( 'failed', sc_status( $o ), 'never authorized again' );
		assert_same( $notes, count( $GLOBALS['sc_notes'][ $o->get_id() ] ), 'no "Processing", no second "still active"' );
		assert_same( 1, count( $GLOBALS['sc_mails'] ) );
		// "Completed" again takes the money.
		sc_completed( $o );
		assert_same( 66300, $c->fake->captures[ $pi ] ?? 0 );
		assert_same( 'completed', sc_status( $o ) );
	}
);

test(
	'capture: "Completed" before the approval was synced, captured meanwhile in the Stripe dashboard — recorded with its authorization; "On hold" set by hand later is never taken for unpaid',
	static function (): void {
		$c  = sc_reset();
		$o  = sc_order();
		$pi = sc_start( $o );
		$c->fake->approve( $pi );
		$c->fake->capture_intent( $pi, 66300, 'dashboard', 'live' ); // Captured in the Stripe dashboard; nothing synced.
		sc_completed( $o );
		$d = sc_data( $o );
		assert_true( $d->captured() && $d->authorized(), 'the capture found, and its authorization' );
		assert_same( $pi, $d->paying() );
		assert_same( 1, sc_count( $o, '663,00 kr has been captured via MobilePay' ) );
		wc_get_order( $o->get_id() )->update_status( 'on-hold' ); // Set by hand (e.g. a customer's question).
		SMPW_Reconcile::sweep();
		SMPW_Payments::sync( $o, 'webhook', $pi );
		assert_same( 'on-hold', sc_status( $o ), 'not authorized again → Processing' );
		assert_same( 0, sc_count( $o, 'The payment has been reserved' ) );
		assert_same( 1, sc_count( $o, 'has been captured via MobilePay' ) );
	}
);

// ==== Amounts: what is captured and what goes back ===================================================================

test(
	'amounts: Failed re-paid after a 100 kr refund, 50 kr more refunded before shipping — captures what is owed',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_refund( $o, 100 );
		$c->fake->expire( $pi );
		sc_completed( $o );
		$pi2 = sc_start( $o );
		$c->fake->approve( $pi2 );
		SMPW_Payments::sync( $o, 'test' );
		sc_refund( $o, 50 );
		sc_completed( $o );
		assert_same( 66300 - 10000 - 5000, $c->fake->captures[ $pi2 ] ?? 0, 'captured what is owed' );
	}
);

test(
	'amounts: 100 kr refunded before the capture, captured, then cancelled — everything taken goes back',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_refund( $o, 100 );
		sc_completed( $o );
		wc_get_order( $o->get_id() )->update_status( 'cancelled' );
		assert_same( $c->fake->captures[ $pi ] ?? 0, sc_stripe_refunded( $c, $pi ) );
	}
);

test(
	'amounts: re-paid after a refund, captured, then cancelled — everything taken goes back',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_refund( $o, 100 );
		$c->fake->expire( $pi );
		sc_completed( $o );
		$pi2 = sc_start( $o );
		$c->fake->approve( $pi2 );
		SMPW_Payments::sync( $o, 'test' );
		sc_completed( $o );
		wc_get_order( $o->get_id() )->update_status( 'cancelled' );
		assert_same( $c->fake->captures[ $pi2 ] ?? 0, sc_stripe_refunded( $c, $pi2 ) );
	}
);

test(
	'amounts: a refund booked without the gateway (e.g. "Refund manually") lowers the capture; cancelled → all of it back',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_refund( $o, 120, false );
		sc_completed( $o );
		assert_same( 54300, $c->fake->captures[ $pi ] ?? 0 );
		assert_same( 12000, sc_data( $o )->refunded_at_capture() );
		wc_get_order( $o->get_id() )->update_status( 'cancelled' );
		assert_same( 54300, sc_stripe_refunded( $c, $pi ) );
	}
);

test(
	'amounts: cancelled after a refund made after the capture — the capture minus that refund; the sweep sees nothing more due',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_refund( $o, 100 );
		sc_completed( $o );
		assert_same( 56300, $c->fake->captures[ $pi ] ?? 0 );
		sc_refund( $o, 50 );
		assert_same( 5000, sc_stripe_refunded( $c, $pi ) );
		wc_get_order( $o->get_id() )->update_status( 'cancelled' );
		assert_same( 56300, sc_stripe_refunded( $c, $pi ), 'everything captured is back' );
		assert_same( 663.0, sc_refunded( $o ) );
		$c->calls = array();
		SMPW_Reconcile::sweep();
		assert_same( array(), $c->calls, 'nothing more due: not swept' );
	}
);

test(
	'amounts: a cancelled order\'s refund never asks more than Stripe can still refund',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		$c->fake->intents[ $pi ]->latest_charge = (object) array(
			'id'              => 'ch_1',
			'amount_refunded' => 6300, // 63 kr refunded in the dashboard, not booked yet.
		);
		wc_get_order( $o->get_id() )->update_status( 'cancelled' );
		assert_same( 60000, sc_stripe_refunded( $c, $pi ) );
	}
);

// ==== Refunds (WooCommerce's refund button) ==========================================================================

test(
	'refund before capture: in full, the release refused — refused, nothing booked; again — released, noted once',
	static function (): void {
		$c                 = sc_reset();
		[ $o, $pi ]        = sc_paid( $c );
		$c->fail['cancel'] = array( sc_net_error() );
		$r                 = sc_refund( $o, 663 );
		assert_true( is_wp_error( $r ), 'a WP_Error: WooCommerce deletes its refund' );
		assert_same( 0.0, sc_refunded( $o ) );
		assert_same( array(), sc_wc_refunds( $o ) );
		assert_same( 0, sc_data( $o )->precapture_refunded(), 'nothing booked first' );
		assert_same( 'requires_capture', $c->fake->intents[ $pi ]->status );
		assert_same( 0, sc_count( $o, 'has been released' ), 'no false release note' );
		$r = sc_refund( $o, 663 );
		assert_true( $r instanceof WC_Order_Refund );
		assert_same( 'canceled', $c->fake->intents[ $pi ]->status );
		assert_same( 66300, sc_data( $o )->precapture_refunded() );
		assert_same( 1, sc_count( $o, 'The full amount was refunded before it was captured' ) );
		assert_same( 0, count( $c->fake->refunds ), 'nothing was taken, nothing sent back' );
	}
);

test(
	'refund before capture: in part — "at most … will be captured" on the capture basis, no Stripe call but the fetch',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_refund( $o, 63, false ); // Booked without the gateway (another plugin, or "Refund manually").
		$c->calls = array();
		sc_refund( $o, 100 );
		assert_same( array( 'retrieve ' . $pi ), $c->calls, 'only the fresh fetch' );
		assert_same( 1, sc_count( $o, 'When the order is marked Completed, at most 500,00 kr will be captured' ) );
	}
);

test(
	'refund: a lost answer is adopted — one Stripe refund, booked once',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		$c->fail['refund'] = array( 'lost' );
		$r                 = sc_refund( $o, 100 );
		assert_true( $r instanceof WC_Order_Refund, is_wp_error( $r ) ? $r->get_error_message() : 'refund kept' );
		assert_same( 1, count( $c->fake->refunds ) );
		$rid = (string) array_key_first( $c->fake->refunds );
		assert_same( $rid, (string) wc_get_order( $r->get_id() )->get_meta( SMPW_Order_Data::REFUND_ID ) );
		assert_same( 1, sc_count( $o, 'has been refunded via MobilePay' ) );
		assert_same( 'ok', SMPW_Payments::sync_refunds( wc_get_order( $o->get_id() ) ) );
		assert_same( 1, count( sc_wc_refunds( $o ) ), 'not booked twice' );
	}
);

test(
	'refund: a lost answer that can\'t be looked up — refused; a new click blocked; sync_refunds books it once, with a note',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		$c->fail['refund'] = array( 'lost' );
		$c->fail['list']   = array( null, sc_net_error() ); // The pre-check lists fine, the lookup after the lost answer doesn't.
		$r                 = sc_refund( $o, 100 );
		assert_true( is_wp_error( $r ) );
		assert_same( 0.0, sc_refunded( $o ), 'WooCommerce deleted its refund' );
		assert_same( 1, count( $c->fake->refunds ), 'but Stripe made it' );
		$r2 = sc_refund( $o, 100 );
		assert_true( is_wp_error( $r2 ) && str_contains( $r2->get_error_message(), 'already refunded' ), is_wp_error( $r2 ) ? $r2->get_error_message() : 'not blocked' );
		assert_same( 1, count( $c->fake->refunds ), 'no second Stripe refund' );
		assert_same( 'ok', SMPW_Payments::sync_refunds( wc_get_order( $o->get_id() ) ) );
		assert_same( 100.0, sc_refunded( $o ) );
		assert_same( 1, sc_count( $o, 'the refund did not exist here' ) );
		assert_same( 'ok', SMPW_Payments::sync_refunds( wc_get_order( $o->get_id() ) ) );
		assert_same( 1, count( sc_wc_refunds( $o ) ), 'booked once' );
		assert_true( sc_refund( $o, 50 ) instanceof WC_Order_Refund, 'a new refund goes now' );
		assert_same( 15000, sc_stripe_refunded( $c, $pi ) );
	}
);

test(
	'refund: two refunds of the same amount in flight — each pays back its own WooCommerce refund under its own key; nothing under-refunded',
	static function (): void {
		$c              = sc_reset();
		[ $o, $pi ]     = sc_paid( $c );
		sc_completed( $o );
		$c->idempotency = true;
		$first          = sc_book_only( $o, 100, true );           // Admin A: wc_create_refund() booked the first refund here; its process_refund() waits for the lock …
		sc_other_request( static fn() => sc_refund( $o, 100 ) );  // … while admin B's request refunds the same amount (a second refund) first.
		$result = SMPW_Payments::refund( wc_get_order( $o->get_id() ), 100.0, 't' ); // A's turn.
		assert_true( true === $result, is_wp_error( $result ) ? $result->get_error_message() : 'paid back' );
		assert_same( 20000, sc_stripe_refunded( $c, $pi ), 'two Stripe refunds for two WooCommerce refunds' );
		assert_same( 200.0, sc_refunded( $o ) );
		$sent = array_filter( array_map( static fn( $r ) => (string) $r->get_meta( SMPW_Order_Data::REFUND_ID ), sc_wc_refunds( $o ) ) );
		assert_same( 2, count( array_unique( $sent ) ), 'each carries its own Stripe refund' );
		assert_true( in_array( 'refund smpw-shop.test-' . $o->get_id() . '-refund-' . $first->get_id(), $c->keys, true ), 'the first refund under its own key' );
		assert_same( 2, sc_count( $o, 'has been refunded via MobilePay' ) );
	}
);

test(
	'refund: the WooCommerce refund being booked is taken by refund() once — also when the order is busy; one booked without the gateway never',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		$booking = new ReflectionProperty( SMPW_Payments::class, 'booking' );
		assert_true( sc_refund( $o, 50 ) instanceof WC_Order_Refund );
		assert_same( array(), $booking->getValue(), 'taken' );
		sc_book_only( $o, 30, true );
		$GLOBALS['sc_lock_busy'] = true;
		assert_true( is_wp_error( SMPW_Payments::refund( wc_get_order( $o->get_id() ), 30.0, 't' ) ) );
		$GLOBALS['sc_lock_busy'] = false;
		assert_same( array(), $booking->getValue(), 'taken although the order was busy (WooCommerce deletes that refund)' );
		sc_refund( $o, 20, false ); // "Refund manually", another plugin, sync_refunds(): WooCommerce doesn't ask the gateway.
		assert_same( array(), $booking->getValue() );
	}
);

test(
	'refund: process_refund() without wc_create_refund() — the newest refund is the guess; one already sent to Stripe is refused',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		$r = sc_book_only( $o, 100 ); // Saved, but nothing in this request said so.
		assert_true( true === SMPW_Payments::refund( wc_get_order( $o->get_id() ), 100.0, 't' ), 'the newest, not sent yet: that one' );
		assert_same( 10000, sc_stripe_refunded( $c, $pi ) );
		assert_true( '' !== (string) $r->get_meta( SMPW_Order_Data::REFUND_ID ) );
		$again = SMPW_Payments::refund( wc_get_order( $o->get_id() ), 100.0, 't' );
		assert_true( is_wp_error( $again ) && str_contains( $again->get_error_message(), 'already been sent to Stripe' ), is_wp_error( $again ) ? $again->get_error_message() : 'not refused' );
		assert_same( 10000, sc_stripe_refunded( $c, $pi ), 'nothing sent twice' );
		// Two in flight, different amounts, this one not booked here: the guess names the other, sent one — refused.
		$c              = sc_reset();
		[ $o, $pi ]     = sc_paid( $c );
		sc_completed( $o );
		$c->idempotency = true;
		sc_book_only( $o, 100 );
		assert_true( sc_refund( $o, 50 ) instanceof WC_Order_Refund );
		assert_true( is_wp_error( SMPW_Payments::refund( wc_get_order( $o->get_id() ), 100.0, 't' ) ), 'refused: WooCommerce deletes it and shows the error' );
		assert_same( 5000, sc_stripe_refunded( $c, $pi ) );
		assert_same( 1, sc_count( $o, 'has been refunded via MobilePay' ) );
	}
);

test(
	'refund: process_refund() without wc_create_refund() — a guess of another amount (not sent yet) is refused, nothing sent; the guess of this amount goes',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		sc_book_only( $o, 100 );              // Two refunds saved elsewhere, neither sent yet: 100 kr …
		$newest = sc_book_only( $o, 50 );     // … and 50 kr, the newest.
		$result = SMPW_Payments::refund( wc_get_order( $o->get_id() ), 100.0, 't' ); // The 100 kr one's process_refund(), called directly.
		assert_true( is_wp_error( $result ) && str_contains( $result->get_error_message(), 'could not be recognized' ), is_wp_error( $result ) ? $result->get_error_message() : 'the 50 kr refund was taken for this 100 kr one' );
		assert_same( 0, sc_stripe_refunded( $c, $pi ), 'nothing sent' );
		assert_same( '', (string) $newest->get_meta( SMPW_Order_Data::REFUND_ID ), 'the 50 kr refund is not marked sent' );
		assert_true( true === SMPW_Payments::refund( wc_get_order( $o->get_id() ), 50.0, 't' ), 'the newest, of this amount: that one' );
		assert_same( 5000, sc_stripe_refunded( $c, $pi ) );
		assert_true( in_array( 'refund smpw-shop.test-' . $o->get_id() . '-refund-' . $newest->get_id(), $c->keys, true ), 'under its own key' );
	}
);

test(
	'refund: a cancelled order\'s rest refunded while an admin refund waited — the admin\'s refund pays back its own amount; guessed, it is refused',
	static function (): void {
		$c                 = sc_reset();
		[ $o, $pi ]        = sc_paid( $c );
		sc_completed( $o );
		$c->idempotency    = true;
		$c->fail['refund'] = array( sc_5xx() );
		wc_get_order( $o->get_id() )->update_status( 'cancelled' ); // The rest's refund fails for now; the sweep tries again.
		assert_same( 0, sc_stripe_refunded( $c, $pi ) );
		sc_book_only( $o, 100, true ); // The admin's refund, booked here for the gateway, waits for the lock …
		sc_other_request( static fn() => SMPW_Payments::sync( wc_get_order( $o->get_id() ), 'sweep' ) ); // … while the sweep refunds the rest (the capture minus that refund).
		assert_same( 56300, sc_stripe_refunded( $c, $pi ) );
		assert_true( true === SMPW_Payments::refund( wc_get_order( $o->get_id() ), 100.0, 't' ) );
		assert_same( 66300, sc_stripe_refunded( $c, $pi ), 'everything captured is back, each refund under its own key' );
		assert_same( 663.0, sc_refunded( $o ) );
		// The same with the admin refund's process_refund() called directly (nothing booked here): the guess names the rest's refund.
		$c                 = sc_reset();
		[ $o, $pi ]        = sc_paid( $c );
		sc_completed( $o );
		$c->idempotency    = true;
		$c->fail['refund'] = array( sc_5xx() );
		wc_get_order( $o->get_id() )->update_status( 'cancelled' );
		sc_book_only( $o, 100 );
		SMPW_Payments::sync( wc_get_order( $o->get_id() ), 'sweep' );
		$result = SMPW_Payments::refund( wc_get_order( $o->get_id() ), 100.0, 't' );
		assert_true( is_wp_error( $result ), 'refused — not "100 kr refunded" with nothing sent back' );
		assert_same( 56300, sc_stripe_refunded( $c, $pi ), 'and no second refund' );
	}
);

test(
	'refund: after a lost answer only a refund of this reference and this amount is adopted',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		// A refund at Stripe that names the WooCommerce refund about to be made, but for another amount.
		$c->fake->refunds['re_other'] = (object) array(
			'id'             => 're_other',
			'payment_intent' => $pi,
			'amount'         => 2000,
			'status'         => 'succeeded',
			'metadata'       => (object) array(
				'smpw'              => '1',
				'smpw_wc_refund_id' => (string) sc_next_refund_id( true ),
			),
		);
		$c->fail['refund'] = array( sc_net_error() ); // Never reached Stripe.
		$result            = sc_refund( $o, 100 );
		assert_true( is_wp_error( $result ), 'not adopted: 20 kr is not this 100 kr refund' );
		assert_same( 0.0, sc_refunded( $o ), 'WooCommerce deleted its refund' );
	}
);

test(
	'refund: a capture made in the Stripe dashboard, found at refund time — recorded once, smpw_payment_captured fired once, whatever Stripe says to the refund',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		$GLOBALS['sc_orders'][ $o->get_id() ]['status'] = 'completed'; // Completed while Stripe was down; the retry chain still waits …
		$c->fake->capture_intent( $pi, 66300, 'dashboard', 'live' );     // … and someone captured it in the Stripe dashboard.
		assert_true( sc_refund( $o, 50 ) instanceof WC_Order_Refund );
		assert_same( 1, sc_count( $o, '663,00 kr has been captured via MobilePay' ) );
		assert_same( 5000, sc_stripe_refunded( $c, $pi ) );
		assert_same( array( array( $o->get_id(), 66300 ) ), $GLOBALS['sc_captured'], 'the capture as the state table records it (MARK_CAPTURED)' );
		// Stripe refuses the refund: WooCommerce drops it; the capture stays recorded.
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		$GLOBALS['sc_orders'][ $o->get_id() ]['status'] = 'completed';
		$c->fake->capture_intent( $pi, 66300, 'dashboard', 'live' );
		$c->fail['refund'] = array( sc_http_error( 400, 'charge_disputed', 'This charge is disputed.' ) );
		assert_true( is_wp_error( sc_refund( $o, 50 ) ) );
		assert_same( array(), sc_wc_refunds( $o ) );
		assert_true( sc_data( $o )->captured() );
		assert_same( array( array( $o->get_id(), 66300 ) ), $GLOBALS['sc_captured'] );
		// Not shipped yet: recorded all the same.
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		$c->fake->capture_intent( $pi, 66300, 'dashboard', 'live' );
		assert_true( sc_refund( $o, 50 ) instanceof WC_Order_Refund );
		assert_true( sc_data( $o )->captured() );
		assert_same( array( array( $o->get_id(), 66300 ) ), $GLOBALS['sc_captured'] );
		SMPW_Payments::sync( $o, 'sweep' );
		assert_same( 1, count( $GLOBALS['sc_captured'] ), 'once' );
	}
);

test(
	'sync with refunds ("Sync with Stripe", wp smpw sync): books a dashboard refund, and a lost answer\'s refund — unblocking the button',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		$c->fake->foreign_refund( $pi, 1000 );
		assert_same(
			array(
				'status'  => 'succeeded',
				'refunds' => 'ok',
			),
			SMPW_Payments::sync_with_refunds( wc_get_order( $o->get_id() ), 'admin' )
		);
		assert_same( 10.0, sc_refunded( $o ), 'the dashboard refund is booked without the webhook' );
		// A refund whose answer got lost and couldn't be looked up blocks the next click until it is booked.
		$c->fail['refund'] = array( 'lost' );
		$c->fail['list']   = array( null, sc_net_error() );
		assert_true( is_wp_error( sc_refund( $o, 100 ) ) );
		$blocked = sc_refund( $o, 100 );
		assert_true( is_wp_error( $blocked ) && str_contains( $blocked->get_error_message(), 'Sync with Stripe' ), 'blocked, and told how to clear it' );
		SMPW_Payments::sync_with_refunds( wc_get_order( $o->get_id() ), 'admin' );
		assert_same( 110.0, sc_refunded( $o ) );
		assert_true( sc_refund( $o, 50 ) instanceof WC_Order_Refund, 'unblocked' );
		assert_same( 16000, sc_stripe_refunded( $c, $pi ) );
		// The order busy: the refunds wait too.
		$GLOBALS['sc_lock_busy'] = true;
		assert_same(
			array(
				'status'  => 'locked',
				'refunds' => '',
			),
			SMPW_Payments::sync_with_refunds( wc_get_order( $o->get_id() ), 'cli' )
		);
	}
);

test(
	'refund: the PaymentIntent is fetched first; one Stripe still processes is "sent for refund", not "refunded"; never more than refundable',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		$c->calls          = array();
		$c->fail['refund'] = array(
			(object) array(
				'id'     => 're_pending1',
				'status' => 'pending',
				'amount' => 5000,
			),
		);
		assert_true( sc_refund( $o, 50 ) instanceof WC_Order_Refund );
		assert_same( 'retrieve ' . $pi, $c->calls[0], 'a fresh fetch first' );
		assert_same( 1, sc_count( $o, 'has been sent for refund via MobilePay (re_pending1)' ) );
		assert_same( 0, sc_count( $o, 'has been refunded' ) );
		$c->fake->intents[ $pi ]->latest_charge = (object) array(
			'id'              => 'ch_1',
			'amount_refunded' => 60000,
		);
		$r = sc_refund( $o, 100 );
		assert_true( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'At most 63,00 kr can be refunded' ), 'no more than Stripe can refund: ' . ( is_wp_error( $r ) ? $r->get_error_message() : get_class( $r ) ) );
	}
);

test(
	'race: a refund booked while the capture ran is left out of the capture — never paid back again',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_book_only( $o, 100 ); // WooCommerce saved it; its process_refund() waits for the lock.
		sc_completed( $o );      // The capture reads the refunded total with it.
		assert_same( 56300, $c->fake->captures[ $pi ] ?? 0 );
		assert_true( true === SMPW_Payments::refund( wc_get_order( $o->get_id() ), 100.0, 't' ) );
		assert_same( 0, count( $c->fake->refunds ), 'not paid back a second time' );
		assert_same( 1, sc_count( $o, 'had already been deducted' ) );
		sc_refund( $o, 50 );
		assert_same( 5000, sc_stripe_refunded( $c, $pi ), 'a later refund goes to Stripe' );
		wc_get_order( $o->get_id() )->update_status( 'cancelled' );
		assert_same( 56300, sc_stripe_refunded( $c, $pi ), 'cancelled: everything captured back' );
		// The capture listed a refund whose amount it didn't read yet (saved between its two reads): that one is paid back.
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		$wc = sc_book_only( $o, 100 );
		$GLOBALS['sc_orders'][ $o->get_id() ]['meta'][ SMPW_Order_Data::CAPTURE_REFUNDS ] = array( wp_json_encode( array( $wc->get_id() ) ) );
		assert_true( true === SMPW_Payments::refund( wc_get_order( $o->get_id() ), 100.0, 't' ) );
		assert_same( 10000, sc_stripe_refunded( $c, $pi ) );
	}
);

test(
	'sync_refunds: larger than what is left — noted once, "ok"; a booking that fails for now — "error", then booked once',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		sc_refund( $o, 600, false );
		$c->fake->foreign_refund( $pi, 10000 );
		$GLOBALS['sc_mails'] = array();
		assert_same( 'ok', SMPW_Payments::sync_refunds( wc_get_order( $o->get_id() ) ) );
		assert_same( 'ok', SMPW_Payments::sync_refunds( wc_get_order( $o->get_id() ) ) );
		assert_same( 1, sc_count( $o, 'cannot be booked here' ) );
		assert_same( 1, count( $GLOBALS['sc_mails'] ) );
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		$c->fake->foreign_refund( $pi, 5000 );
		$GLOBALS['sc_fail_wc_refund'] = 1;
		assert_same( 'error', SMPW_Payments::sync_refunds( wc_get_order( $o->get_id() ) ) );
		assert_same( 'ok', SMPW_Payments::sync_refunds( wc_get_order( $o->get_id() ) ) );
		assert_same( 'ok', SMPW_Payments::sync_refunds( wc_get_order( $o->get_id() ) ) );
		assert_same( 50.0, sc_refunded( $o ), 'booked once' );
		assert_same( 1, sc_count( $o, 'has been refunded in Stripe' ) );
	}
);

test(
	'sync_refunds: a booking that completes the refund — WooCommerce sets "Refunded", whose sync runs inside it — keeps that sync\'s notes and flags: nothing repeated later',
	static function (): void {
		$c                 = sc_reset();
		[ $o, $pi1, $pi2 ] = sc_duplicate( $c ); // pi2 pays; pi1, an earlier attempt, was captured too: a duplicate.
		sc_completed( $o );
		$c->fake->foreign_refund( $pi2, 66300 );         // Refunded in full in the Stripe dashboard …
		$c->fake->refunds['re_failed'] = (object) array( // … and listed after it, an older refund that failed.
			'id'             => 're_failed',
			'payment_intent' => $pi2,
			'amount'         => 10000,
			'status'         => 'failed',
			'metadata'       => (object) array(),
		);
		$GLOBALS['sc_mails'] = array();
		assert_same( 'ok', SMPW_Payments::sync_refunds( wc_get_order( $o->get_id() ) ) );
		assert_same( 66300, sc_stripe_refunded( $c, $pi1 ), 'the sync inside it gave the duplicate back' );
		assert_true( sc_data( $o )->flag( 'dupe_note_' . $pi1 ), 'that sync\'s flag is kept' );
		assert_same( 'refunded', sc_status( $o ) );
		assert_same( 1, sc_count( $o, 'already paid' ) );
		assert_same( 1, sc_count( $o, 'has failed in Stripe' ) );
		// Later: Stripe's events for the duplicate and the refunds.
		SMPW_Payments::sync( $o, 'webhook', $pi1 );
		SMPW_Payments::sync_refunds( wc_get_order( $o->get_id() ) );
		assert_same( 1, sc_count( $o, 'already paid' ), 'not repeated' );
		assert_same( 1, sc_count( $o, 'has failed in Stripe' ) );
		assert_same( 66300, sc_stripe_refunded( $c, $pi1 ), 'no money moved twice' );
		assert_same( 2, count( $GLOBALS['sc_mails'] ), 'the duplicate and the failed refund, each once' );
		assert_same( 663.0, sc_refunded( $o ) );
	}
);

test(
	'cancelled after the capture: the rest refunded, WooCommerce sets "Refunded" (its sync inside the cancel\'s) — nothing repeated, not by the webhook, "Sync with Stripe" or the sweep',
	static function (): void {
		$c          = sc_reset();
		[ $o, $pi ] = sc_paid( $c );
		sc_completed( $o );
		sc_refund( $o, 100 ); // 100 kr back after the capture.
		$GLOBALS['sc_mails'] = array();
		wc_get_order( $o->get_id() )->update_status( 'cancelled' ); // REFUND_ORDER: the rest, 563 kr → nothing left → "Refunded".
		assert_same( 'refunded', sc_status( $o ) );
		assert_same( 66300, sc_stripe_refunded( $c, $pi ) );
		assert_same( 663.0, sc_refunded( $o ) );
		assert_same( 1, sc_count( $o, '563,00 kr has been refunded via MobilePay' ) );
		$notes = count( $GLOBALS['sc_notes'][ $o->get_id() ] );
		SMPW_Payments::sync( $o, 'webhook', $pi );
		SMPW_Payments::sync_refunds( wc_get_order( $o->get_id() ) );
		SMPW_Payments::sync_with_refunds( wc_get_order( $o->get_id() ), 'admin' );
		SMPW_Reconcile::sweep();
		assert_same( $notes, count( $GLOBALS['sc_notes'][ $o->get_id() ] ), 'nothing repeated' );
		assert_same( 2, count( sc_wc_refunds( $o ) ) );
		assert_same( 66300, sc_stripe_refunded( $c, $pi ) );
		assert_same( array(), $GLOBALS['sc_mails'] );
	}
);

// ==== Orders paid another way, disputes ==============================================================================

test(
	'orphans: sync() on an order paid another way only closes our attempts',
	static function (): void {
		$c  = sc_reset();
		$o  = sc_order();
		$pi = sc_start( $o );
		$c->fake->approve( $pi );
		$GLOBALS['sc_orders'][ $o->get_id() ]['payment_method'] = 'stripe';
		$GLOBALS['sc_orders'][ $o->get_id() ]['status']         = 'processing';
		assert_same( 'ok', SMPW_Payments::sync( $o, 'admin' ) );
		assert_same( 'canceled', $c->fake->intents[ $pi ]->status );
		assert_same( '', sc_data( $o )->paying(), 'never authorized by the state machine' );
	}
);

test(
	'orphans: refunded in the dashboard already — "ok"; Stripe unreachable — "error"; captured instead of released — "ok"',
	static function (): void {
		$c  = sc_reset();
		$o  = sc_order();
		$pi = sc_start( $o );
		$c->fake->approve( $pi );
		$c->fake->capture_intent( $pi, 66300, 'x', 'test' );
		$GLOBALS['sc_orders'][ $o->get_id() ]['payment_method'] = 'stripe';
		$GLOBALS['sc_orders'][ $o->get_id() ]['status']         = 'processing';
		$c->fake->intents[ $pi ]->latest_charge                  = (object) array(
			'id'              => 'ch_1',
			'amount_refunded' => 66300,
		);
		$c->fail['refund'] = array( new WP_Error( 'smpw_stripe', 'Charge has already been refunded.', array( 'status' => 400, 'retryable' => false ) ) );
		assert_same( 'ok', SMPW_Payments::close_orphans( $o, 'webhook' ) );
		assert_true( sc_data( $o )->flag( 'dupe_' . $pi ) );
		assert_same( 1, sc_count( $o, 'it has been refunded' ) );
		assert_same( 'ok', SMPW_Payments::close_orphans( $o, 'webhook' ) );
		assert_same( 1, sc_count( $o, 'it has been refunded' ), 'once' );
		// Transient: money still held, the refund failed for now → "error"; next time it works.
		$c  = sc_reset();
		$o  = sc_order();
		$pi = sc_start( $o );
		$c->fake->approve( $pi );
		$c->fake->capture_intent( $pi, 66300, 'x', 'test' );
		$GLOBALS['sc_orders'][ $o->get_id() ]['payment_method'] = 'stripe';
		$c->fail['refund'] = array( sc_5xx() );
		assert_same( 'error', SMPW_Payments::close_orphans( $o, 'webhook' ) );
		assert_same( 'ok', SMPW_Payments::close_orphans( $o, 'webhook' ) );
		assert_same( 66300, sc_stripe_refunded( $c, $pi ) );
		// A retrieve that fails for good (404) → not "error"; one that fails for now → "error".
		$c->fail['retrieve'] = array( new WP_Error( 'smpw_stripe', 'No such payment_intent', array( 'status' => 404, 'retryable' => false ) ) );
		assert_same( 'ok', SMPW_Payments::close_orphans( $o, 'webhook' ) );
		$c->fail['retrieve'] = array( sc_net_error() );
		assert_same( 'error', SMPW_Payments::close_orphans( $o, 'webhook' ) );
		// A late approval that got captured before it could be released → "ok", noted once.
		$c  = sc_reset();
		$o  = sc_order();
		$pi = sc_start( $o );
		$c->fake->approve( $pi );
		$GLOBALS['sc_orders'][ $o->get_id() ]['payment_method'] = 'stripe';
		$c->before_cancel = static function ( string $id ) use ( $c ): void {
			$c->fake->capture_intent( $id, 66300, 'x', 'test' );
		};
		assert_same( 'ok', SMPW_Payments::close_orphans( $o, 'webhook' ) );
		assert_same( 1, sc_count( $o, 'could not be released automatically' ) );
		// A release that fails for now (the hold still live) → "error".
		$c  = sc_reset();
		$o  = sc_order();
		$pi = sc_start( $o );
		$c->fake->approve( $pi );
		$GLOBALS['sc_orders'][ $o->get_id() ]['payment_method'] = 'stripe';
		$c->fail['cancel'] = array( sc_net_error() );
		assert_same( 'error', SMPW_Payments::close_orphans( $o, 'webhook' ) );
		assert_same( 'ok', SMPW_Payments::close_orphans( $o, 'webhook' ) );
		assert_same( 'canceled', $c->fake->intents[ $pi ]->status );
	}
);

test(
	'dispute: waits for the order lock, noted and mailed once',
	static function (): void {
		$c                        = sc_reset();
		[ $o, $pi ]               = sc_paid( $c );
		$GLOBALS['sc_lock_busy'] = true;
		assert_same( 'locked', SMPW_Payments::dispute( $o, 'dp_1', 'test' ) );
		$GLOBALS['sc_lock_busy'] = false;
		assert_same( 0, sc_count( $o, 'dispute' ) );
		assert_same( 'ok', SMPW_Payments::dispute( $o, 'dp_1', 'test' ) );
		assert_same( 'ok', SMPW_Payments::dispute( wc_get_order( $o->get_id() ), 'dp_1', 'test' ) );
		assert_same( 1, sc_count( $o, 'has disputed' ) );
		assert_same( 1, sc_mails( 'disputed' ) );
	}
);

exit( smpw_test_run( (string) ( $argv[1] ?? '' ) ) );
