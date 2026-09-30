<?php
defined( 'ABSPATH' ) || exit;

/**
 * Every money movement and every order transition for MobilePay — under a per-order lock, after asking
 * Stripe for the PaymentIntent's real state. Callers: the gateway (start, refund), the return endpoint, the
 * webhook, reconciliation, WooCommerce status changes (capture at "Completed", release at "Cancelled"),
 * admin and WP-CLI.
 */
final class SMPW_Payments {

	private static ?SMPW_Client $client = null;

	/** @var array<int, WC_Order_Refund> Per order: the refund wc_create_refund() is booking through the gateway in this request (on_create_refund()). */
	private static array $booking = array();

	/** What the customer reads when MobilePay can't be started. */
	public static function start_failed_message(): string {
		return __( 'MobilePay could not be started right now. Please try again, or choose another payment method.', 'stripe-mobilepay-woocommerce' );
	}

	/** The Stripe client — replaceable through the filter smpw_client (e.g. a fake Stripe for integration tests). */
	public static function client(): SMPW_Client {
		$client = apply_filters( 'smpw_client', self::$client );
		if ( ! $client instanceof SMPW_Client ) {
			self::$client = new SMPW_Stripe();
			$client       = self::$client;
		}
		return $client;
	}

	public static function is_ours( $order ): bool {
		return $order instanceof WC_Order && SMPW_Plugin::GATEWAY_ID === $order->get_payment_method();
	}

	/** Pure: the idempotency key of one logical operation on one order. */
	public static function idempotency_key( string $site, int $order_id, string $operation ): string {
		return 'smpw-' . $site . '-' . $order_id . '-' . $operation;
	}

	private static function key( int $order_id, string $operation ): string {
		return self::idempotency_key( SMPW_Plugin::site(), $order_id, $operation );
	}

	/** @return array{id: int, number: string, name: string, email: string, phone: string, line1: string, line2: string, postcode: string, city: string, country: string, shop: string} */
	public static function order_facts( WC_Order $order ): array {
		return array(
			'id'       => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'name'     => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email'    => (string) $order->get_billing_email(),
			'phone'    => (string) $order->get_billing_phone(),
			'line1'    => (string) $order->get_billing_address_1(),
			'line2'    => (string) $order->get_billing_address_2(),
			'postcode' => (string) $order->get_billing_postcode(),
			'city'     => (string) $order->get_billing_city(),
			'country'  => (string) $order->get_billing_country(),
			'shop'     => SMPW_Plugin::shop_name(),
		);
	}

	/** No side effects: the PaymentIntent (created and confirmed in one call) for one attempt. */
	public static function intent_params( array $facts, int $attempt, int $amount, string $return_url, string $site ): array {
		$address = array_filter(
			array(
				'line1'       => (string) ( $facts['line1'] ?? '' ),
				'line2'       => (string) ( $facts['line2'] ?? '' ),
				'postal_code' => (string) ( $facts['postcode'] ?? '' ),
				'city'        => (string) ( $facts['city'] ?? '' ),
				'country'     => (string) ( $facts['country'] ?? '' ),
			),
			'strlen'
		);
		$billing = array_filter(
			array(
				'name'  => (string) ( $facts['name'] ?? '' ),
				'email' => (string) ( $facts['email'] ?? '' ),
				'phone' => (string) ( $facts['phone'] ?? '' ),
			),
			'strlen'
		);
		if ( array() !== $address ) {
			$billing['address'] = $address;
		}
		$method = array( 'type' => 'mobilepay' );
		if ( array() !== $billing ) {
			$method['billing_details'] = $billing;
		}
		return array(
			'amount'               => $amount,
			'currency'             => 'dkk',
			'payment_method_types' => array( 'mobilepay' ),
			'capture_method'       => 'manual',
			'confirm'              => true,
			'payment_method_data'  => $method,
			'return_url'           => $return_url,
			/* translators: 1: the shop's name, 2: order number */
			'description'          => trim( sprintf( __( '%1$s order #%2$s', 'stripe-mobilepay-woocommerce' ), (string) ( $facts['shop'] ?? '' ), (string) ( $facts['number'] ?? '' ) ) ),
			'metadata'             => array(
				'smpw'              => '1',
				'smpw_site'         => $site,
				'smpw_order_id'     => (string) ( $facts['id'] ?? '' ),
				'smpw_order_number' => (string) ( $facts['number'] ?? '' ),
				'smpw_attempt'      => (string) $attempt,
			),
		);
	}

	/** No side effects: '' when $intent is this attempt's PaymentIntent for this order on this site, else why not. */
	public static function mismatch( object $intent, int $order_id, array $attempt, string $site ): string {
		$meta = $intent->metadata ?? null;
		if ( '1' !== (string) ( $meta->smpw ?? '' ) ) {
			return __( 'not a MobilePay payment from this plugin', 'stripe-mobilepay-woocommerce' );
		}
		if ( (string) $order_id !== (string) ( $meta->smpw_order_id ?? '' ) ) {
			return __( 'a different order', 'stripe-mobilepay-woocommerce' );
		}
		if ( $site !== (string) ( $meta->smpw_site ?? '' ) ) {
			return __( 'a different website', 'stripe-mobilepay-woocommerce' );
		}
		if ( (int) ( $intent->amount ?? -1 ) !== (int) $attempt['amount'] ) {
			return __( 'a different amount', 'stripe-mobilepay-woocommerce' );
		}
		if ( strtolower( (string) ( $intent->currency ?? '' ) ) !== strtolower( (string) $attempt['currency'] ) ) {
			return __( 'a different currency', 'stripe-mobilepay-woocommerce' );
		}
		return '';
	}

	/** Pure: when Stripe authorized the payment — its charge's time (expanded), else the PaymentIntent's; $fallback if neither is known. */
	public static function authorized_at( object $intent, int $fallback ): int {
		$charge = $intent->latest_charge ?? null;
		$at     = is_object( $charge ) ? (int) ( $charge->created ?? 0 ) : 0;
		if ( $at <= 0 ) {
			$at = (int) ( $intent->created ?? 0 );
		}
		return $at > 0 ? $at : $fallback;
	}

	/**
	 * Pure: what sync() reports — the status seen for the attempt asked about ($intent_id), else for the order's
	 * primary attempt (paying, else current). Other attempts never overwrite it.
	 *
	 * @param array<string, string> $seen Per PaymentIntent checked: its status, 'error' (not readable) or '' (ignored).
	 */
	public static function sync_result( array $seen, string $intent_id, string $paying, string $current ): string {
		$primary = '' !== $intent_id ? $intent_id : ( '' !== $paying ? $paying : $current );
		return (string) ( $seen[ $primary ] ?? '' );
	}

	/**
	 * Close one attempt at Stripe: release a hold, or end an attempt the customer can no longer use. True only when
	 * Stripe has it canceled — then $note is written and the stored status follows (current or paying attempt).
	 * Every try its own key (the reason, then the order's try number, as the capture's): Stripe replays a saved answer —
	 * a 5xx too — for 24 h, and the same hold is released by several paths (the state table, a refund before the capture,
	 * nothing due at "Completed"). Safe: cancelling can't move money twice, and a refused try is judged by Stripe's state.
	 */
	private static function cancel_intent( WC_Order $order, array $attempt, string $reason, string $note ): bool {
		$id   = (string) $attempt['id'];
		$data = new SMPW_Order_Data( $order );
		$try  = $data->next_cancel_try();
		$data->save();
		$result = self::client()->cancel_intent( $id, $reason, self::key( $order->get_id(), 'cancel-' . $id . '-' . $reason . '-' . $try ), (string) $attempt['mode'] );
		if ( is_wp_error( $result ) ) {
			// A lost answer, or the state changed meanwhile (expired, canceled or captured): Stripe's own state decides.
			$check = self::client()->retrieve_intent( $id, (string) $attempt['mode'] );
			if ( is_wp_error( $check ) || 'canceled' !== (string) ( $check->status ?? '' ) ) {
				SMPW_Log::warning( 'cancel failed', array( 'order' => $order->get_id(), 'intent' => $id, 'error' => $result->get_error_message() ) );
				return false;
			}
		}
		if ( $id === $data->current() || $id === $data->paying() ) {
			$data->set_status( 'canceled' ); // The meta box stops saying "Reserved"; the sweep stops checking it.
			$data->save();
		}
		if ( '' !== $note ) {
			$order->add_order_note( $note );
		}
		return true;
	}

	private static function session_key(): string {
		return function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get_customer_id() : '';
	}

	/** The customer has paid: empty the cart in this browser and in the one that started the payment. */
	public static function empty_carts( WC_Order $order ): void {
		$stored = ( new SMPW_Order_Data( $order ) )->session();
		if ( function_exists( 'WC' ) && WC()->session && WC()->cart && (string) WC()->session->get_customer_id() === $stored ) {
			WC()->cart->empty_cart();
		}
		if ( '' !== $stored && class_exists( 'WC_Session_Handler' ) ) {
			$handler = new WC_Session_Handler();
			if ( method_exists( $handler, 'delete_session' ) ) {
				$handler->delete_session( $stored );
			}
		}
	}

	/** Add a note only the first time; returns it, or '' when it was written before. */
	private static function once( WC_Order $order, SMPW_Order_Data $data, string $flag, string $note ): string {
		if ( $data->flag( $flag ) ) {
			return '';
		}
		$data->set_flag( $flag );
		$data->save();
		$order->add_order_note( $note );
		return $note;
	}

	/** An approval on an order already paid or cancelled, given back — call only after Stripe confirmed it. Once; '' when written before. */
	private static function note_duplicate( WC_Order $order, SMPW_Order_Data $data, array $attempt, object $intent ): string {
		$text = 'succeeded' === $intent->status
			/* translators: 1: MobilePay attempt number, 2: PaymentIntent ID, 3: amount */
			? __( 'MobilePay attempt %1$d (%2$s) was approved, but the order was already paid or cancelled. The extra amount (%3$s) has been refunded.', 'stripe-mobilepay-woocommerce' )
			/* translators: 1: MobilePay attempt number, 2: PaymentIntent ID, 3: amount */
			: __( 'MobilePay attempt %1$d (%2$s) was approved, but the order was already paid or cancelled. The extra amount (%3$s) has been released.', 'stripe-mobilepay-woocommerce' );
		return self::once(
			$order,
			$data,
			'dupe_note_' . $intent->id,
			sprintf( $text, (int) $attempt['attempt'], $intent->id, SMPW_Money::format( (int) ( $intent->amount ?? 0 ) ) )
		);
	}

	/** A hold Stripe didn't release (a later sync tries again). Once; '' when written before. */
	private static function note_release_failed( WC_Order $order, SMPW_Order_Data $data, object $intent ): string {
		return self::once(
			$order,
			$data,
			'release_fail_' . $intent->id,
			/* translators: 1: PaymentIntent ID, 2: amount */
			sprintf( __( 'The MobilePay hold (%1$s, %2$s) could not be released automatically. Release it in the Stripe dashboard.', 'stripe-mobilepay-woocommerce' ), $intent->id, SMPW_Money::format( (int) ( $intent->amount_capturable ?? 0 ) ) )
		);
	}

	/** A date and time in the site's time zone and language, e.g. "October 6, 2026 at 21:45". */
	private static function when( int $timestamp ): string {
		/* translators: a date and time in PHP date() format, e.g. "October 6, 2026 at 21:45" */
		return $timestamp > 0 ? wp_date( __( 'F j, Y \a\t H:i', 'stripe-mobilepay-woocommerce' ), $timestamp ) : __( 'unknown', 'stripe-mobilepay-woocommerce' );
	}

	/** start() gives up: a note and a log line for the shop, the standard message for the customer. */
	private static function start_failed( WC_Order $order, string $note ): never {
		$order->add_order_note( $note );
		SMPW_Log::error( 'start failed', array( 'order' => $order->get_id(), 'error' => $note ) );
		throw new SMPW_Exception( self::start_failed_message() );
	}

	/**
	 * A new attempt: close the order's open attempts, create + confirm a MobilePay PaymentIntent (hold only) for what
	 * the order still owes, and return where to send the customer. An earlier attempt approved meanwhile pays the
	 * order instead; an order that isn't unpaid (a Failed order paid again) goes through reopen() first.
	 * $pay_page: started on the order's payment page (order-pay) — a failed attempt goes back there, not to the checkout.
	 *
	 * @throws SMPW_Exception With a message for the customer.
	 */
	public static function start( WC_Order $order, bool $pay_page = false ): string {
		$order_id = $order->get_id();
		if ( ! SMPW_Lock::acquire( $order_id, 10 ) ) {
			self::start_failed( $order, __( 'MobilePay could not be started: another process is handling the order right now.', 'stripe-mobilepay-woocommerce' ) );
		}
		try {
			$order = wc_get_order( $order_id );
			$data  = new SMPW_Order_Data( $order );
			if ( ! SMPW_Decision::unpaid( $order->get_status(), $data->authorized() ) ) {
				$url = self::reopen( $order, $data );
				if ( '' !== $url ) {
					return $url;
				}
				$order = wc_get_order( $order_id );
				$data  = new SMPW_Order_Data( $order );
			}
			foreach ( array_reverse( $data->attempts() ) as $attempt ) {
				$intent = self::client()->retrieve_intent( (string) $attempt['id'], (string) $attempt['mode'] );
				if ( is_wp_error( $intent ) ) {
					continue;
				}
				if ( in_array( $intent->status, array( 'requires_capture', 'succeeded' ), true ) ) {
					self::sync_locked( $order, 'start', (string) $attempt['id'] );
					$order = wc_get_order( $order_id );
					if ( ! $order->needs_payment() ) {
						return $order->get_checkout_order_received_url();
					}
				} elseif ( in_array( $intent->status, SMPW_Decision::OPEN, true ) ) {
					/* translators: 1: MobilePay attempt number, 2: PaymentIntent ID */
					self::cancel_intent( $order, $attempt, 'abandoned', sprintf( __( 'MobilePay attempt %1$d (%2$s) has been closed because the customer started a new one.', 'stripe-mobilepay-woocommerce' ), (int) $attempt['attempt'], $attempt['id'] ) );
				}
			}

			$order   = wc_get_order( $order_id );
			$data    = new SMPW_Order_Data( $order );
			$mode    = SMPW_Plugin::mode();
			$attempt = $data->next_attempt();
			$amount  = SMPW_Money::to_minor( $order->get_total() ) - SMPW_Money::to_minor( $order->get_total_refunded() ); // What is still owed.
			$params  = self::intent_params( self::order_facts( $order ), $attempt, $amount, SMPW_Return::url( $order, $attempt, $pay_page ), SMPW_Plugin::site() );
			// A new key on every call: Stripe replays a saved answer (a 5xx too) for 24 h, and refuses changed billing details under an old key.
			$intent = self::client()->create_intent( $params, self::key( $order_id, 'create-' . $attempt . '-' . wp_generate_uuid4() ), $mode );
			if ( is_wp_error( $intent ) || empty( $intent->id ) ) {
				/* translators: %s: the error */
				self::start_failed( $order, sprintf( __( 'MobilePay could not be started: %s', 'stripe-mobilepay-woocommerce' ), is_wp_error( $intent ) ? $intent->get_error_message() : __( 'no answer from Stripe', 'stripe-mobilepay-woocommerce' ) ) );
			}
			$data->add_attempt( (string) $intent->id, $attempt, $amount, $mode );
			$data->set_status( (string) $intent->status );
			$data->set_session( self::session_key() );
			$order->set_transaction_id( (string) $intent->id );
			$data->save();
			SMPW_Reconcile::schedule_followups( $order_id, (string) $intent->id );

			$url = (string) ( $intent->next_action->redirect_to_url->url ?? '' );
			if ( 'requires_action' === $intent->status && '' !== $url ) {
				/* translators: 1: MobilePay attempt number, 2: PaymentIntent ID, 3: amount */
				$order->add_order_note( sprintf( __( 'MobilePay started (attempt %1$d, %2$s): %3$s will be reserved when the customer approves in the app (within 5 minutes).', 'stripe-mobilepay-woocommerce' ), $attempt, $intent->id, SMPW_Money::format( $amount ) ) );
				return $url;
			}
			// Not the expected answer: let the state machine decide where the customer goes.
			self::sync_locked( $order, 'start', (string) $intent->id );
			$order = wc_get_order( $order_id );
			if ( ! $order->needs_payment() ) {
				return $order->get_checkout_order_received_url();
			}
			/* translators: 1: PaymentIntent status, 2: MobilePay attempt number, 3: PaymentIntent ID */
			self::start_failed( $order, sprintf( __( 'MobilePay could not be started: Stripe answered "%1$s" to attempt %2$d (%3$s) without sending the customer on to MobilePay.', 'stripe-mobilepay-woocommerce' ), $intent->status, $attempt, $intent->id ) );
		} finally {
			SMPW_Lock::release( $order_id );
		}
	}

	/**
	 * process_payment() on an order that isn't unpaid: WooCommerce's order-pay link offers a Failed order (a failed
	 * capture) as it is. A hold that still lives is never doubled (the shop captures it), a capture made meanwhile is
	 * recorded, and only a gone hold is forgotten. The status stays: no "Failed" e-mails again, no stock restored and
	 * taken again, and WooCommerce's unpaid-order timer (pending orders only) never cancels a shipped order whose re-pay
	 * is abandoned. Without its authorization the order is unpaid (SMPW_Decision::unpaid()): the next approval →
	 * "Processing".
	 *
	 * @return string Where to send the customer, or '' to go on with a new attempt.
	 * @throws SMPW_Exception With a message for the customer.
	 */
	private static function reopen( WC_Order $order, SMPW_Order_Data $data ): string {
		$paying = $data->paying();
		if ( '' === $paying ) {
			$data->clear_authorization(); // No attempt to check at Stripe: nothing here can be a live hold.
			$data->save();
			$order->add_order_note( __( 'The customer is paying the order again with MobilePay. The order status will change once the payment is approved.', 'stripe-mobilepay-woocommerce' ) );
			return '';
		}
		$attempt = $data->attempt( $paying );
		$intent  = null !== $attempt ? self::client()->retrieve_intent( $paying, (string) $attempt['mode'] ) : new WP_Error( 'smpw_attempt', __( 'not an attempt of this order', 'stripe-mobilepay-woocommerce' ) );
		if ( is_wp_error( $intent ) ) {
			/* translators: 1: PaymentIntent ID, 2: the error */
			self::start_failed( $order, sprintf( __( 'MobilePay could not be started: the first MobilePay payment (%1$s) could not be checked with Stripe (%2$s).', 'stripe-mobilepay-woocommerce' ), $paying, $intent->get_error_message() ) );
		}
		$status = (string) $intent->status;
		if ( 'requires_capture' === $status ) {
			/* translators: 1: PaymentIntent ID, 2: amount, 3: date and time the hold expires */
			self::start_failed( $order, sprintf( __( 'The customer tried to pay again with MobilePay, but the first hold (%1$s, %2$s) is still active until %3$s. Mark the order Completed to capture it.', 'stripe-mobilepay-woocommerce' ), $paying, SMPW_Money::format( (int) ( $intent->amount_capturable ?? 0 ) ), self::when( $data->capture_before() ) ) );
		}
		if ( 'succeeded' === $status ) {
			self::sync_locked( $order, 'start', $paying ); // Captured meanwhile (e.g. in the Stripe dashboard): record it.
			$order = wc_get_order( $order->get_id() );
			if ( ! $order->needs_payment() ) {
				return $order->get_checkout_order_received_url();
			}
			/* translators: 1: PaymentIntent ID, 2: amount */
			self::start_failed( $order, sprintf( __( 'The customer tried to pay again with MobilePay, but the first payment (%1$s, %2$s) has already been captured in Stripe. Correct the order status — no new payment was accepted.', 'stripe-mobilepay-woocommerce' ), $paying, SMPW_Money::format( (int) ( $intent->amount_received ?? 0 ) ) ) );
		}
		// The hold is gone (expired or canceled): the next approval pays the order.
		$data->clear_authorization();
		$data->save();
		/* translators: %s: PaymentIntent ID */
		$order->add_order_note( sprintf( __( 'The MobilePay hold (%s) no longer exists, so the customer is paying the order again with MobilePay. The order status will change once the new payment is approved.', 'stripe-mobilepay-woocommerce' ), $paying ) );
		return '';
	}

	/**
	 * Bring the order in step with Stripe: for each attempt to check, fetch the PaymentIntent, validate it,
	 * decide (SMPW_Decision) and act. Idempotent; safe from any path at any time. An order that isn't
	 * (or no longer is) paid with MobilePay never runs the state machine: close_orphans() only closes its attempts.
	 *
	 * @return string The status of the attempt asked about ($intent_id), else of the order's primary attempt (paying,
	 *                else current) — never another attempt's: a PaymentIntent status; 'error' (it couldn't be read);
	 *                '' (nothing to check, not an attempt of this order, or its PaymentIntent doesn't match the order);
	 *                'locked' (try later). An order that isn't ours: close_orphans()'s 'ok' | 'locked' | 'error'.
	 */
	public static function sync( WC_Order $order, string $source, string $intent_id = '' ): string {
		$order_id = $order->get_id();
		if ( ! SMPW_Lock::acquire( $order_id, 15 ) ) {
			return 'locked';
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return '';
			}
			if ( ! self::is_ours( $order ) ) {
				return self::close_orphans( $order, $source );
			}
			return self::sync_locked( $order, $source, $intent_id );
		} finally {
			SMPW_Lock::release( $order_id );
		}
	}

	/**
	 * "Sync with Stripe" (the order screen) and `wp smpw sync`: the payment, then its refunds — a refund made in the
	 * Stripe dashboard is booked, and so is one whose answer got lost (which unblocks the refund button) — without
	 * waiting for the webhook. The refunds are left alone when the payment couldn't be checked (as the webhook).
	 *
	 * @return array{status: string, refunds: string} sync()'s result; sync_refunds()'s, or '' when it didn't run.
	 */
	public static function sync_with_refunds( WC_Order $order, string $source ): array {
		$status  = self::sync( $order, $source );
		$refunds = '';
		$fresh   = wc_get_order( $order->get_id() );
		if ( self::is_ours( $fresh ) && ! in_array( $status, array( 'locked', 'error' ), true ) ) {
			$refunds = self::sync_refunds( $fresh );
		}
		return array(
			'status'  => $status,
			'refunds' => $refunds,
		);
	}

	private static function sync_locked( WC_Order $order, string $source, string $intent_id = '' ): string {
		$order_id = $order->get_id();
		$data     = new SMPW_Order_Data( $order );
		$seen     = array(); // Per PaymentIntent checked: its status, 'error' (not readable) or '' (ignored) — see sync_result().
		foreach ( '' !== $intent_id ? array( $intent_id ) : self::ids_to_check( $data ) as $id ) {
			$attempt = $data->attempt( $id );
			if ( null === $attempt ) {
				SMPW_Log::warning( 'sync: not an attempt of this order', array( 'order' => $order_id, 'intent' => $id, 'source' => $source ) );
				$seen[ $id ] = '';
				continue;
			}
			$intent = self::client()->retrieve_intent( $id, (string) $attempt['mode'] );
			if ( is_wp_error( $intent ) ) {
				SMPW_Log::warning( 'sync: retrieve failed', array( 'order' => $order_id, 'intent' => $id, 'source' => $source, 'error' => $intent->get_error_message() ) );
				$seen[ $id ] = 'error';
				continue;
			}
			$problem = self::mismatch( $intent, $order_id, $attempt, SMPW_Plugin::site() );
			if ( '' !== $problem ) {
				/* translators: 1: PaymentIntent ID, 2: why it doesn't match */
				self::once( $order, $data, 'mismatch_' . $id, sprintf( __( 'MobilePay: the payment %1$s does not match the order (%2$s) and has been ignored.', 'stripe-mobilepay-woocommerce' ), $id, $problem ) );
				SMPW_Log::error( 'sync: mismatch', array( 'order' => $order_id, 'intent' => $id, 'problem' => $problem ) );
				$seen[ $id ] = '';
				continue;
			}
			$status  = (string) $intent->status;
			$before  = $order->get_status();
			$unpaid  = SMPW_Decision::unpaid( $before, $data->authorized() );
			$actions = SMPW_Decision::decide( $status, $before, $data->role( $id, $unpaid ), $data->authorized(), $data->captured() );
			if ( ( $id === $data->current() || $id === $data->paying() ) && $status !== $data->status() ) {
				$data->set_status( $status );
				$data->save();
			}
			self::apply( $actions, $order, $intent, $attempt, $source );
			$order = wc_get_order( $order_id );
			$data  = new SMPW_Order_Data( $order );
			SMPW_Log::info(
				'sync',
				array(
					'order'   => $order_id,
					'intent'  => $id,
					'source'  => $source,
					'status'  => $status,
					'before'  => $before,
					'after'   => $order->get_status(),
					'actions' => implode( ',', $actions ),
				)
			);
			$seen[ $id ] = $status;
		}
		// The primary attempt as the order stands now: an approval in this sync may have made another attempt the paying one.
		return self::sync_result( $seen, $intent_id, $data->paying(), $data->current() );
	}

	/** The paying attempt first, then the newest ones (an order has a handful at most). */
	private static function ids_to_check( SMPW_Order_Data $data ): array {
		$ids = array_reverse( array_map( static fn( array $a ): string => (string) $a['id'], $data->attempts() ) );
		if ( '' !== $data->paying() ) {
			$ids = array_values( array_unique( array_merge( array( $data->paying() ), $ids ) ) );
		}
		return array_slice( $ids, 0, 6 );
	}

	/**
	 * Perform the decided actions, in order. The order lock is held. A note that money was released or refunded
	 * follows only Stripe's confirmation, and only once; EMAIL_SHOP sends only a note written in this call. A hold
	 * Stripe didn't release is noted and mailed once where it happens.
	 */
	private static function apply( array $actions, WC_Order $order, object $intent, array $attempt, string $source ): void {
		$order_id = $order->get_id();
		$id       = (string) $intent->id;
		$note     = '';    // Written in this call: what EMAIL_SHOP sends.
		$done     = false; // This PaymentIntent's release (CANCEL_INTENT) or refund (REFUND_INTENT), confirmed by Stripe.
		foreach ( $actions as $action ) {
			$order = wc_get_order( $order_id );
			$data  = new SMPW_Order_Data( $order );
			switch ( $action ) {
				case SMPW_Decision::AUTHORIZE:
					self::authorize( $order, $data, $intent, $source );
					break;
				case SMPW_Decision::MARK_CAPTURED:
					self::mark_captured( $order, $data, $id, (int) ( $intent->amount_received ?? 0 ), 0 );
					break;
				case SMPW_Decision::CAPTURE:
					self::capture_locked( $order, $source, 0 ); // Captured here too (e.g. by the sweep after an outage).
					break;
				case SMPW_Decision::CANCEL_INTENT:
					$hold = 'requires_capture' === $intent->status;
					$done = self::cancel_intent( $order, $attempt, $hold ? 'requested_by_customer' : 'abandoned', '' );
					if ( ! $done && $hold ) {
						// Money still held on the customer's card: noted and mailed once, whatever the row (a cancelled or
						// refunded order's has no EMAIL_SHOP). An open attempt holds no money: nothing to report.
						$failed = self::note_release_failed( $order, $data, $intent );
						if ( '' !== $failed ) {
							SMPW_Admin::email( $order, __( 'The MobilePay hold could not be released', 'stripe-mobilepay-woocommerce' ), $failed );
						}
					}
					break;
				case SMPW_Decision::REFUND_INTENT:
					$done = self::refund_duplicate( $order, $data, $intent, $attempt ); // A refusal is noted and mailed there, once.
					break;
				case SMPW_Decision::REFUND_ORDER:
					self::refund_rest( $order, __( 'The order was cancelled', 'stripe-mobilepay-woocommerce' ) );
					break;
				case SMPW_Decision::FAIL_CAPTURE:
					self::hold_gone( $order, $data, $id ); // Refunded in full: settled as released, not Failed.
					break;
				case SMPW_Decision::NOTE_NOT_COMPLETED:
					/* translators: 1: MobilePay attempt number, 2: PaymentIntent ID */
					$note = self::once( $order, $data, 'not_completed_' . $id, sprintf( __( 'The MobilePay payment was not completed (attempt %1$d, %2$s — declined, abandoned or not approved within 5 minutes). The order is awaiting payment; the customer can try again.', 'stripe-mobilepay-woocommerce' ), (int) $attempt['attempt'], $id ) );
					break;
				case SMPW_Decision::NOTE_DUPLICATE:
					if ( $done ) {
						$note = self::note_duplicate( $order, $data, $attempt, $intent );
					}
					break;
				case SMPW_Decision::NOTE_RELEASED:
					if ( $done ) {
						$text = 'refunded' === $order->get_status()
							/* translators: 1: PaymentIntent ID, 2: amount */
							? __( 'The MobilePay hold (%1$s, %2$s) has been released because the order was refunded.', 'stripe-mobilepay-woocommerce' )
							/* translators: 1: PaymentIntent ID, 2: amount */
							: __( 'The MobilePay hold (%1$s, %2$s) has been released because the order was cancelled.', 'stripe-mobilepay-woocommerce' );
						$note = self::once( $order, $data, 'released_' . $id, sprintf( $text, $id, SMPW_Money::format( (int) ( $intent->amount_capturable ?? 0 ) ) ) );
					}
					break;
				case SMPW_Decision::NOTE_HOLD_GONE:
					/* translators: %s: PaymentIntent ID */
					$note = self::once( $order, $data, 'hold_gone_' . $id, sprintf( __( 'The MobilePay hold (%s) no longer exists — it has expired or been canceled in Stripe. The amount cannot be captured; contact the customer about payment before the order is shipped.', 'stripe-mobilepay-woocommerce' ), $id ) );
					break;
				case SMPW_Decision::NOTE_HOLD_ACTIVE:
					/* translators: 1: PaymentIntent ID, 2: amount, 3: date and time the hold expires */
					$note = self::once( $order, $data, 'hold_active_' . $id, sprintf( __( 'The order is marked Failed, but the MobilePay hold (%1$s, %2$s) is still active until %3$s. Mark the order Completed again to capture the amount.', 'stripe-mobilepay-woocommerce' ), $id, SMPW_Money::format( (int) ( $intent->amount_capturable ?? 0 ) ), self::when( $data->capture_before() ) ) );
					break;
				case SMPW_Decision::EMAIL_SHOP:
					if ( '' !== $note ) {
						SMPW_Admin::email( $order, __( 'MobilePay needs attention', 'stripe-mobilepay-woocommerce' ), $note );
					}
					break;
			}
		}
	}

	/** The customer approved: this attempt pays the order → "Processing", exactly like a reserved card payment. */
	private static function authorize( WC_Order $order, SMPW_Order_Data $data, object $intent, string $source ): void {
		$id     = (string) $intent->id;
		$amount = self::record_authorization( $order, $data, $intent );
		// No other attempt of this order may succeed now, and no second hold may stay.
		foreach ( $data->attempts() as $other ) {
			if ( $other['id'] === $id ) {
				continue;
			}
			$other_intent = self::client()->retrieve_intent( (string) $other['id'], (string) $other['mode'] );
			if ( is_wp_error( $other_intent ) ) {
				continue; // That attempt's own sync (webhook, sweep) closes it.
			}
			if ( in_array( $other_intent->status, SMPW_Decision::OPEN, true ) ) {
				self::cancel_intent( $order, $other, 'duplicate', '' );
			} elseif ( 'requires_capture' === $other_intent->status ) {
				$note = self::cancel_intent( $order, $other, 'duplicate', '' )
					? self::note_duplicate( $order, $data, $other, $other_intent )
					: self::note_release_failed( $order, $data, $other_intent );
				if ( '' !== $note ) {
					SMPW_Admin::email( $order, __( 'MobilePay needs attention', 'stripe-mobilepay-woocommerce' ), $note );
				}
			}
		}
		$order = wc_get_order( $order->get_id() );
		$order->update_status(
			'processing',
			sprintf(
				/* translators: 1: amount, 2: PaymentIntent ID, 3: date and time the hold expires */
				__( 'The payment has been reserved via MobilePay (%1$s, %2$s) and will be captured when the order is marked Completed (shipped). The hold expires on %3$s.', 'stripe-mobilepay-woocommerce' ),
				SMPW_Money::format( $amount ),
				$id,
				self::when( ( new SMPW_Order_Data( $order ) )->capture_before() )
			)
		);
		self::empty_carts( $order );
		SMPW_Log::info( 'authorized', array( 'order' => $order->get_id(), 'intent' => $id, 'amount' => $amount, 'source' => $source ) );
	}

	/**
	 * The data of an authorization: $intent pays the order — the paying attempt, the amount it holds and Stripe's
	 * authorization time (the hold's expiry). authorize() records it before its "Processing"; capture_locked() alone,
	 * for an approval that was never synced. Saved.
	 *
	 * @return int The amount authorized (minor units).
	 */
	private static function record_authorization( WC_Order $order, SMPW_Order_Data $data, object $intent ): int {
		$id     = (string) $intent->id;
		$amount = (int) ( $intent->amount_capturable ?? 0 );
		if ( $amount <= 0 ) {
			$amount = (int) ( $intent->amount_received ?? 0 ) > 0 ? (int) $intent->amount_received : (int) $intent->amount;
		}
		$data->set_paying( $id );
		$data->mark_authorized( $amount, self::authorized_at( $intent, time() ) ); // Stripe's time: a late or repeated sync never moves the expiry.
		$data->set_status( (string) $intent->status );
		$order->set_transaction_id( $id );
		$data->save();
		return $amount;
	}

	/**
	 * Money taken on an attempt that isn't the order's payment: give it all back. True once Stripe has refunded it —
	 * now or before: the flag remembers it (a refunded PaymentIntent stays "succeeded" at Stripe), and before every try
	 * Stripe's own state is read (duplicate_refunded()), so a try whose answer got lost is never followed by a second
	 * refund. Every try its own key (dupe-<pi>-<n>, the order's try number): Stripe replays a saved answer — a 5xx too
	 * — for 24 h. False when Stripe refused: noted and mailed here, once; a later sync tries again.
	 *
	 * @param object $intent The PaymentIntent as read fresh by the caller (latest_charge expanded).
	 */
	private static function refund_duplicate( WC_Order $order, SMPW_Order_Data $data, object $intent, array $attempt ): bool {
		$id = (string) $intent->id;
		if ( $data->flag( 'dupe_' . $id ) ) {
			return true;
		}
		$amount = (int) ( $intent->amount_received ?? 0 );
		if ( $amount <= 0 ) {
			SMPW_Log::warning( 'duplicate refund: nothing received', array( 'order' => $order->get_id(), 'intent' => $id ) );
			return false;
		}
		$mode = (string) $attempt['mode'];
		if ( self::duplicate_refunded( $intent, $amount, $mode ) ) {
			return self::duplicate_done( $data, $id ); // Given back already (an earlier try whose answer got lost, the dashboard).
		}
		$try = $data->next_dupe_try();
		$data->save();
		$result = self::client()->create_refund(
			array(
				'payment_intent' => $id,
				'amount'         => $amount,
				'reason'         => 'duplicate',
				'metadata'       => array(
					'smpw'              => '1',
					'smpw_site'         => SMPW_Plugin::site(),
					'smpw_order_id'     => (string) $order->get_id(),
					'smpw_wc_refund_id' => 'duplicate',
				),
			),
			self::key( $order->get_id(), 'dupe-' . $id . '-' . $try ),
			$mode
		);
		if ( is_wp_error( $result ) ) {
			// A lost answer can hide a refund made: ask Stripe once more before calling it a failure.
			$check = self::client()->retrieve_intent( $id, $mode );
			if ( ! is_wp_error( $check ) && self::duplicate_refunded( $check, $amount, $mode ) ) {
				return self::duplicate_done( $data, $id );
			}
			/* translators: 1: amount, 2: PaymentIntent ID, 3: Stripe's error message */
			$note = self::once( $order, $data, 'dupe_fail_' . $id, sprintf( __( 'A duplicate MobilePay amount (%1$s, %2$s) could not be refunded automatically: %3$s. Refund it in the Stripe dashboard.', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $amount ), $id, $result->get_error_message() ) );
			if ( '' !== $note ) {
				SMPW_Admin::email( $order, __( 'A duplicate MobilePay payment must be refunded', 'stripe-mobilepay-woocommerce' ), $note );
			}
			return false;
		}
		return self::duplicate_done( $data, $id );
	}

	/**
	 * Whether Stripe has given the duplicate back already, by its fresh state: the charge refunded by at least $amount
	 * (the dashboard too), or a refund of ours for it (smpw_wc_refund_id "duplicate", pending or succeeded) — so a try
	 * whose answer got lost is seen even before the charge shows it.
	 */
	private static function duplicate_refunded( object $intent, int $amount, string $mode ): bool {
		$charge = $intent->latest_charge ?? null;
		if ( is_object( $charge ) && (int) ( $charge->amount_refunded ?? 0 ) >= $amount ) {
			return true;
		}
		foreach ( self::live_refunds( (string) $intent->id, $mode ) as $refund ) {
			$meta = $refund->metadata ?? null;
			if ( '1' === (string) ( $meta->smpw ?? '' ) && 'duplicate' === (string) ( $meta->smpw_wc_refund_id ?? '' ) && (int) ( $refund->amount ?? 0 ) >= $amount ) {
				return true;
			}
		}
		return false;
	}

	/** The duplicate is refunded: remembered for good (the callers write their note once). */
	private static function duplicate_done( SMPW_Order_Data $data, string $intent_id ): bool {
		$data->set_flag( 'dupe_' . $intent_id );
		$data->save();
		return true;
	}

	public static function hooks(): void {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_completed' ), 5, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_cancelled' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_cancelled' ), 10, 2 ); // As the Stripe plugin's cancel_payment() does for a card hold.
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'before_unpaid_cancel' ), 10, 2 );
		add_filter( 'wc_stripe_allowed_payment_processing_statuses', array( __CLASS__, 'stripe_statuses' ), 10, 2 );
		add_action( 'woocommerce_create_refund', array( __CLASS__, 'on_create_refund' ), 10, 2 );
		add_action( 'smpw_capture_retry', array( __CLASS__, 'capture_retry' ), 10, 2 );
	}

	/**
	 * The Stripe plugin's own gate before it settles a payment on an order (its webhook handler, its redirect and intent
	 * checks): never on a MobilePay order. Our orders carry no Stripe meta, but a card attempt made on the same order
	 * before the customer chose MobilePay can: its late payment_intent.payment_failed would set the order to Failed.
	 *
	 * @param mixed $statuses The order statuses in which the Stripe plugin may process a payment.
	 * @param mixed $order    The order.
	 * @return mixed None for a MobilePay order, else $statuses untouched.
	 */
	public static function stripe_statuses( $statuses, $order = null ) {
		return self::is_ours( $order ) ? array() : $statuses;
	}

	/**
	 * "Completed" = shipped: take the money. Priority 5, ahead of the usual handlers of the same status change, so that
	 * code acting on a completed order (an invoice, for instance) finds the capture recorded.
	 */
	public static function on_completed( $order_id, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( self::is_ours( $order ) ) {
			self::capture( $order, 'completed' );
		}
	}

	public static function capture( WC_Order $order, string $source, int $retry = 0 ): bool {
		$order_id = $order->get_id();
		if ( ! SMPW_Lock::acquire( $order_id, 20 ) ) {
			self::schedule_capture_retry( $order_id, $retry + 1 );
			return false;
		}
		try {
			return self::capture_locked( wc_get_order( $order_id ), $source, $retry );
		} finally {
			SMPW_Lock::release( $order_id );
		}
	}

	/**
	 * Take the money; the order lock is held. True when it is taken — now or before. False when it isn't: not yet (a
	 * retry chain or the sweep tries again), not at all (Failed), or nothing was due (the hold is released instead, or
	 * found released already, and recorded as settled — never as a capture).
	 */
	private static function capture_locked( WC_Order $order, string $source, int $retry ): bool {
		$data = new SMPW_Order_Data( $order );
		if ( $data->captured() ) {
			return ! $data->released();
		}
		// The payment lookup refund() and sync_refunds() use too; an order marked Completed before its approval was
		// synced has no paying attempt yet: its newest one — recorded below as the paying one, so they find it.
		$id      = $data->payment();
		$id      = '' !== $id ? $id : $data->current();
		$attempt = '' !== $id ? $data->attempt( $id ) : null;
		if ( null === $attempt ) {
			$order->add_order_note( __( 'There is no MobilePay hold to capture on this order.', 'stripe-mobilepay-woocommerce' ) );
			return false;
		}
		$intent = self::client()->retrieve_intent( $id, (string) $attempt['mode'] );
		if ( is_wp_error( $intent ) ) {
			return self::capture_trouble( $order, $attempt, $intent, $retry );
		}
		if ( $id !== $data->paying() && in_array( (string) $intent->status, array( 'requires_capture', 'succeeded' ), true ) ) {
			// Approved at Stripe, but no sync recorded it ("Completed" came first): the authorization is recorded now, as
			// authorize() records it — without its "Processing" and e-mails. A capture that fails is then a normal failed
			// capture (the hold's expiry known), and no later sync takes the order for unpaid and authorizes it again.
			$held = self::record_authorization( $order, $data, $intent );
			SMPW_Log::info( 'authorized at capture', array( 'order' => $order->get_id(), 'intent' => $id, 'amount' => $held, 'source' => $source ) );
		}
		switch ( (string) $intent->status ) {
			case 'succeeded':
				self::mark_captured( $order, $data, $id, (int) $intent->amount_received, 0 ); // Captured before: the Stripe dashboard, or an answer that got lost.
				return true;
			case 'requires_capture':
				$capturable = (int) $intent->amount_capturable;
				$basis      = self::refund_snapshot( $order );
				$amount     = SMPW_Money::capture_due( $capturable, SMPW_Money::to_minor( $order->get_total() ), $basis['refunded'] );
				if ( 0 === $amount ) {
					return self::release_nothing_due( $order, $data, $attempt, $intent, $basis );
				}
				// Every try its own key: Stripe replays a saved answer (a 5xx too) for 24 h, and refuses a changed amount
				// under an old key. Safe: each try follows a fresh fetch, and a PaymentIntent can be captured only once.
				$try = $data->next_capture_try();
				$data->save();
				$result = self::client()->capture_intent( $id, $amount, self::key( $order->get_id(), 'capture-' . $id . '-' . $try ), (string) $attempt['mode'] );
				if ( is_wp_error( $result ) ) {
					// A lost answer can hide a success: ask once more before calling it a failure.
					$check = self::client()->retrieve_intent( $id, (string) $attempt['mode'] );
					if ( ! is_wp_error( $check ) && 'succeeded' === $check->status ) {
						self::mark_captured( $order, $data, $id, (int) $check->amount_received, $capturable, $basis );
						return true;
					}
					return self::capture_trouble( $order, $attempt, $result, $retry, $check );
				}
				self::mark_captured( $order, $data, $id, (int) ( $result->amount_received ?? $amount ), $capturable, $basis );
				return true;
			case 'canceled':
				return self::hold_gone( $order, $data, $id );
			default:
				/* translators: 1: PaymentIntent ID, 2: PaymentIntent status */
				self::once( $order, $data, 'nothing_to_capture_' . $id, sprintf( __( 'The MobilePay payment %1$s has not been approved (status: %2$s) — there is nothing to capture.', 'stripe-mobilepay-woocommerce' ), $id, $intent->status ) );
				return false;
		}
	}

	/**
	 * WooCommerce's refunds as a capture sees them: the refunded total (minor units) and the refunds' ids — read in that
	 * order (left_out() relies on it). The capture leaves out every refund booked so far, with or without the gateway.
	 *
	 * @return array{refunded: int, ids: int[]}
	 */
	private static function refund_snapshot( WC_Order $order ): array {
		$refunded = SMPW_Money::to_minor( $order->get_total_refunded() );
		$ids      = wc_get_orders( array( 'type' => 'shop_order_refund', 'parent' => $order->get_id(), 'limit' => -1, 'return' => 'ids' ) );
		return array( 'refunded' => $refunded, 'ids' => array_map( 'intval', (array) $ids ) );
	}

	/**
	 * At "Completed" nothing is due (all refunded before the capture): release the hold instead. Confirmed → recorded
	 * as settled with nothing taken (SMPW_Order_Data::mark_released(): the state table never turns the canceled hold
	 * into FAIL_CAPTURE, and smpw_payment_captured doesn't fire) + one note. Refused → one note + e-mail; the sweep
	 * tries again.
	 *
	 * @param array{refunded: int, ids: int[]} $basis
	 * @return bool Always false: nothing was taken.
	 */
	private static function release_nothing_due( WC_Order $order, SMPW_Order_Data $data, array $attempt, object $intent, array $basis ): bool {
		$id   = (string) $intent->id;
		$hold = SMPW_Money::format( (int) ( $intent->amount_capturable ?? 0 ) );
		if ( ! self::cancel_intent( $order, $attempt, 'requested_by_customer', '' ) ) {
			/* translators: 1: PaymentIntent ID, 2: amount */
			$note = self::once( $order, $data, 'release_fail_' . $id, sprintf( __( 'There is nothing to capture — the full amount was refunded before shipping — but the MobilePay hold (%1$s, %2$s) could not be released automatically. The next automatic check will try to release it again; otherwise release it in the Stripe dashboard.', 'stripe-mobilepay-woocommerce' ), $id, $hold ) );
			if ( '' !== $note ) {
				SMPW_Admin::email( $order, __( 'The MobilePay hold could not be released', 'stripe-mobilepay-woocommerce' ), $note );
			}
			return false;
		}
		$data->mark_released();
		$data->set_capture_basis( $id, $basis['refunded'], $basis['ids'] );
		$data->save();
		/* translators: 1: PaymentIntent ID, 2: amount */
		self::once( $order, $data, 'released_' . $id, sprintf( __( 'There is nothing to capture — the full amount was refunded before shipping. The MobilePay hold (%1$s, %2$s) has been released.', 'stripe-mobilepay-woocommerce' ), $id, $hold ) );
		return false;
	}

	/**
	 * At "Completed" the hold is gone: canceled at Stripe — released already (at "Refunded", which WooCommerce sets
	 * itself on a full refund, or by a full refund before the capture) or expired. Nothing due — the order total minus
	 * every refund booked is 0 or less (capture_due() = 0 whatever the hold) — is settled as release_nothing_due()
	 * settles it: released, nothing recorded as captured, one note. Else the money is missing: Failed (fail_capture()).
	 *
	 * @return bool Always false: nothing was taken.
	 */
	private static function hold_gone( WC_Order $order, SMPW_Order_Data $data, string $id ): bool {
		$basis = self::refund_snapshot( $order );
		if ( SMPW_Money::to_minor( $order->get_total() ) - $basis['refunded'] > 0 ) {
			self::fail_capture( $order, __( 'the hold has expired or been canceled in Stripe', 'stripe-mobilepay-woocommerce' ) );
			return false;
		}
		$data->mark_released();
		$data->set_capture_basis( $id, $basis['refunded'], $basis['ids'] );
		$data->save();
		/* translators: %s: PaymentIntent ID */
		self::once( $order, $data, 'nothing_due_' . $id, sprintf( __( 'There is nothing to capture — the full amount has been refunded, and the MobilePay hold (%s) has already been released. Nothing was captured via MobilePay.', 'stripe-mobilepay-woocommerce' ), $id ) );
		return false;
	}

	/**
	 * Record a capture — made here, or found made elsewhere (the Stripe dashboard, a lost answer). $basis: what our
	 * capture's amount was worked out from (refund_snapshot()); without it, WooCommerce's refunded total now and no
	 * refund left out (a capture made elsewhere left none out). Noted once per PaymentIntent — and then, only then,
	 * the action smpw_payment_captured fires.
	 *
	 * @param array{refunded: int, ids: int[]}|null $basis
	 */
	private static function mark_captured( WC_Order $order, SMPW_Order_Data $data, string $id, int $taken, int $capturable, ?array $basis = null ): void {
		$basis = $basis ?? array( 'refunded' => SMPW_Money::to_minor( $order->get_total_refunded() ), 'ids' => array() );
		$data->mark_captured( $taken );
		$data->set_capture_basis( $id, (int) $basis['refunded'], (array) $basis['ids'] );
		$data->set_status( 'succeeded' );
		$data->save();
		/* translators: 1: amount, 2: PaymentIntent ID */
		$note = sprintf( __( '%1$s has been captured via MobilePay (%2$s).', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $taken ), $id );
		if ( $capturable > $taken ) {
			/* translators: %s: amount */
			$note .= ' ' . sprintf( __( 'The rest of the hold (%s) has been released.', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $capturable - $taken ) );
		}
		if ( '' !== self::once( $order, $data, 'captured_' . $id, $note ) ) {
			/**
			 * A MobilePay payment has been captured and the capture is recorded on the order. Fires once per captured
			 * PaymentIntent, however the capture came about: the order marked Completed, a capture retry, the hourly
			 * sweep, or a capture found made in the Stripe dashboard. Not when a hold is released with nothing due.
			 * The order may have any status; it runs while the plugin holds the order's lock.
			 *
			 * @param WC_Order $order        The order.
			 * @param int      $amount_minor The amount captured, in minor units (1/100 kr).
			 */
			do_action( 'smpw_payment_captured', $order, $taken );
		}
	}

	/**
	 * Transient trouble (transient()) starts one retry chain per order: a new try 5 minutes after the first failure,
	 * then 30 minutes and 2 hours after each further one. When the chain's third retry fails too — or at once, for a
	 * definitive error — the order fails (Failed + e-mail), telling the shop whether the hold still lives (then it
	 * can be captured later) — from $intent when it was just read, else read now. A try from elsewhere (the sweep,
	 * "Completed" again) while a chain is scheduled leaves it to that chain; the note is written once, at its start.
	 *
	 * @param object|WP_Error|null $intent The PaymentIntent as read right after the error, if it was.
	 */
	private static function capture_trouble( WC_Order $order, array $attempt, WP_Error $error, int $retry, $intent = null ): bool {
		if ( self::transient( $error ) && $retry < 3 ) {
			$scheduled = self::schedule_capture_retry( $order->get_id(), $retry + 1 );
			if ( $scheduled && 0 === $retry ) {
				/* translators: %s: the error */
				$order->add_order_note( sprintf( __( 'The MobilePay amount could not be captured right now (%s). It will be tried again automatically in 5 minutes, and then after 30 minutes and 2 hours; if that doesn\'t work, the order is set to Failed.', 'stripe-mobilepay-woocommerce' ), $error->get_error_message() ) );
			}
			SMPW_Log::warning( 'capture failed', array( 'order' => $order->get_id(), 'try' => $retry, 'next' => $scheduled ? $retry + 1 : 'the chain already scheduled', 'error' => $error->get_error_message() ) );
			return false;
		}
		if ( ! is_object( $intent ) || is_wp_error( $intent ) ) {
			$intent = self::client()->retrieve_intent( (string) $attempt['id'], (string) $attempt['mode'] );
		}
		self::fail_capture( $order, $error->get_error_message(), ! is_wp_error( $intent ) && 'requires_capture' === (string) ( $intent->status ?? '' ) ? $intent : null );
		return false;
	}

	/**
	 * Trouble a later try may not have: network, a 409 (the idempotency key busy with the same request), 429, 5xx
	 * (SMPW_Stripe::retryable()), or the Stripe plugin without its keys for a moment (not connected).
	 */
	public static function transient( WP_Error $error ): bool {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		return SMPW_Stripe::retryable( $error )
			|| 409 === (int) ( $data['status'] ?? 0 )
			|| 'idempotency_key_in_use' === (string) ( $data['code'] ?? '' )
			|| 'smpw_not_connected' === $error->get_error_code();
	}

	/**
	 * Schedule try $retry of the order's retry chain. Never a second chain: a new one (try 1) only when none of the
	 * order's tries is pending or running. Action Scheduler matches args exactly — always array( int order, int try ).
	 *
	 * @return bool Whether it was scheduled.
	 */
	private static function schedule_capture_retry( int $order_id, int $retry ): bool {
		$delay = self::retry_delay( $retry );
		if ( $delay <= 0 || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		if ( 1 === $retry && self::retry_scheduled( $order_id ) ) {
			return false; // A chain is on its way: it tries again itself.
		}
		as_schedule_single_action( time() + $delay, 'smpw_capture_retry', array( $order_id, $retry ), SMPW_Reconcile::GROUP );
		return true;
	}

	private static function retry_scheduled( int $order_id ): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}
		for ( $retry = 1; self::retry_delay( $retry ) > 0; $retry++ ) {
			if ( as_has_scheduled_action( 'smpw_capture_retry', array( $order_id, $retry ), SMPW_Reconcile::GROUP ) ) {
				return true;
			}
		}
		return false;
	}

	/** Pure: the wait before try $retry (1–3) of a capture retry chain, in seconds; 0 = the chain is over. */
	public static function retry_delay( int $retry ): int {
		return array( 1 => 300, 2 => 1800, 3 => 7200 )[ $retry ] ?? 0;
	}

	public static function capture_retry( $order_id, $retry ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! self::is_ours( $order ) || ! $order->has_status( 'completed' ) ) {
			return;
		}
		self::capture( $order, 'retry', (int) $retry );
	}

	/**
	 * Same as a failed card capture: "Failed", a note, and an e-mail to the shop. $hold: the PaymentIntent when its hold
	 * still lives — then the shop captures later ("Completed" again) instead of asking the customer to pay, and the
	 * state table's "still active" note (NOTE_HOLD_ACTIVE) isn't written a second time for it.
	 */
	private static function fail_capture( WC_Order $order, string $why, ?object $hold = null ): void {
		if ( null === $hold ) {
			/* translators: %s: why the amount couldn't be captured */
			$note = sprintf( __( 'The amount could not be captured via MobilePay: %s. The order has been set to Failed — contact the customer about payment.', 'stripe-mobilepay-woocommerce' ), $why );
		} else {
			$data = new SMPW_Order_Data( $order );
			$data->set_flag( 'hold_active_' . $hold->id );
			$data->save();
			/* translators: 1: why the amount couldn't be captured, 2: PaymentIntent ID, 3: amount, 4: date and time the hold expires */
			$note = sprintf( __( 'The amount could not be captured via MobilePay: %1$s. The MobilePay hold (%2$s, %3$s) is still active until %4$s: mark the order Completed again to capture the amount — now or later, before the hold expires. The order has been set to Failed.', 'stripe-mobilepay-woocommerce' ), $why, $hold->id, SMPW_Money::format( (int) ( $hold->amount_capturable ?? 0 ) ), self::when( $data->capture_before() ) );
		}
		$order->update_status( 'failed', $note );
		SMPW_Admin::email( $order, __( 'The MobilePay amount could not be captured', 'stripe-mobilepay-woocommerce' ), $note );
	}

	/**
	 * "Cancelled" or "Refunded": the state table releases the hold and closes open attempts; a cancelled order also
	 * gets back what was taken ("Refunded" moves no captured money — like card orders).
	 */
	public static function on_cancelled( $order_id, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( self::is_ours( $order ) ) {
			self::sync( $order, $order->get_status() ); // 'locked' → the hourly sweep does it.
		}
	}

	/**
	 * A cancelled order whose payment was taken: give back what the customer still has to get back — the capture minus
	 * the refunds booked after it (SMPW_Money::still_to_refund()) — never more than Stripe can still refund, nor than
	 * WooCommerce can still book (as the Stripe plugin does for cards). Refused → one note + e-mail; the sweep retries.
	 */
	private static function refund_rest( WC_Order $order, string $reason ): void {
		$data     = new SMPW_Order_Data( $order );
		$id       = $data->payment();
		$attempt  = '' !== $id ? $data->attempt( $id ) : null;
		$refunded = SMPW_Money::to_minor( $order->get_total_refunded() );
		$room     = SMPW_Money::to_minor( $order->get_total() ) - $refunded; // WooCommerce books no more than this.
		if ( null === $attempt || SMPW_Money::still_to_refund( $data->captured_amount(), $refunded, $data->refunded_at_capture(), $room ) <= 0 ) {
			return;
		}
		$intent = self::client()->retrieve_intent( $id, (string) $attempt['mode'] ); // Fresh, its charge expanded: what Stripe can still refund.
		if ( is_wp_error( $intent ) ) {
			SMPW_Log::warning( 'refund rest: retrieve failed', array( 'order' => $order->get_id(), 'intent' => $id, 'error' => $intent->get_error_message() ) );
			return; // Still due, so the hourly sweep tries again.
		}
		$rest = SMPW_Money::still_to_refund( $data->captured_amount(), $refunded, $data->refunded_at_capture(), min( $room, self::refundable( $intent ) ) );
		if ( $rest <= 0 ) {
			return;
		}
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => SMPW_Money::from_minor( $rest ),
				'reason'         => $reason,
				'refund_payment' => true,
				'restock_items'  => false,
			)
		);
		if ( is_wp_error( $refund ) ) {
			/* translators: 1: amount, 2: the error */
			$note = self::once( $order, $data, 'refund_rest_fail_' . $id, sprintf( __( 'The order is cancelled, but the MobilePay amount (%1$s) could not be refunded automatically: %2$s. Refund it in WooCommerce or in Stripe.', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $rest ), $refund->get_error_message() ) );
			if ( '' !== $note ) {
				SMPW_Admin::email( $order, __( 'A MobilePay refund failed', 'stripe-mobilepay-woocommerce' ), $note );
			}
		}
	}

	/** What Stripe can still refund of a captured PaymentIntent: received minus its charge's amount_refunded (retrieve expands latest_charge). */
	private static function refundable( object $intent ): int {
		$charge = $intent->latest_charge ?? null;
		return max( 0, (int) ( $intent->amount_received ?? 0 ) - ( is_object( $charge ) ? (int) ( $charge->amount_refunded ?? 0 ) : 0 ) );
	}

	/**
	 * woocommerce_create_refund: wc_create_refund() fires it with the refund just before saving it and — for a refund
	 * paid back through the gateway — asking the gateway in the same request (process_refund() → refund()). Kept per
	 * order for this request, so refund() knows which WooCommerce refund it pays back (booked_refund()).
	 *
	 * @param mixed $refund The refund being created.
	 * @param mixed $args   wc_create_refund()'s arguments.
	 */
	public static function on_create_refund( $refund, $args = array() ): void {
		if ( $refund instanceof WC_Order_Refund && is_array( $args ) && ! empty( $args['refund_payment'] ) ) {
			self::$booking[ (int) $refund->get_parent_id() ] = $refund;
		}
	}

	/**
	 * The WooCommerce refund this refund() call pays back: the one wc_create_refund() is booking in this request
	 * (on_create_refund(); $booking, taken by refund() before anything else), else — process_refund() called without
	 * wc_create_refund() — the order's newest refund, a guess: taken only when it is of this call's amount (else
	 * another refund is in flight — a WP_Error, nothing refunded), and refused by refund() when it was sent already.
	 *
	 * @return WC_Order_Refund|WP_Error|null null: the order has no refund to name.
	 */
	private static function booked_refund( int $order_id, ?WC_Order_Refund $booking, int $minor ) {
		if ( null !== $booking && $booking->get_id() > 0 && $minor === SMPW_Money::to_minor( $booking->get_amount() ) ) {
			return $booking;
		}
		$newest = self::newest_refund( $order_id );
		if ( null !== $newest && $minor !== SMPW_Money::to_minor( $newest->get_amount() ) ) {
			/* translators: 1: WooCommerce refund ID, 2: that refund's amount, 3: the amount asked for */
			return new WP_Error( 'smpw_refund', sprintf( __( 'Nothing has been refunded: the refund could not be recognized — the newest refund (#%1$d) is for %2$s, not %3$s. Two refunds at the same time? Check the order and try again.', 'stripe-mobilepay-woocommerce' ), (int) $newest->get_id(), SMPW_Money::format( SMPW_Money::to_minor( $newest->get_amount() ) ), SMPW_Money::format( $minor ) ) );
		}
		return $newest;
	}

	/**
	 * WooCommerce's refund button (process_refund) — WooCommerce has saved the refund before it asks, and deletes it
	 * again on a WP_Error. Captured → a Stripe refund. Not captured yet → the refund lowers the coming capture
	 * (SMPW_Money::capture_due()); with nothing left, the hold is released first — a failed release fails the refund.
	 * Stripe is asked first on every path that could move money. A WooCommerce refund that already carries a Stripe
	 * refund is never sent again, and a guessed one of another amount is never taken (two refunds at once, when the
	 * refund can only be guessed).
	 *
	 * @return true|WP_Error
	 */
	public static function refund( WC_Order $order, float $amount, string $reason ) {
		$order_id = $order->get_id();
		// The refund WooCommerce is booking in this request, taken once: whatever happens next, it was this call's.
		$booking = self::$booking[ $order_id ] ?? null;
		unset( self::$booking[ $order_id ] );
		$minor = SMPW_Money::to_minor( $amount );
		if ( $minor <= 0 ) {
			return new WP_Error( 'smpw_amount', __( 'The amount must be greater than 0.', 'stripe-mobilepay-woocommerce' ) );
		}
		if ( ! SMPW_Lock::acquire( $order_id, 20 ) ) {
			return new WP_Error( 'smpw_busy', __( 'The order is being processed right now — please try again in a moment.', 'stripe-mobilepay-woocommerce' ) );
		}
		try {
			$order   = wc_get_order( $order_id );
			$data    = new SMPW_Order_Data( $order );
			$id      = $data->payment();
			$attempt = '' !== $id ? $data->attempt( $id ) : null;
			if ( null === $attempt ) {
				return new WP_Error( 'smpw_nothing', __( 'The order has no MobilePay payment to refund.', 'stripe-mobilepay-woocommerce' ) );
			}
			$wc_refund = self::booked_refund( $order_id, $booking, $minor );
			if ( is_wp_error( $wc_refund ) ) {
				return $wc_refund; // Only a guess (booked_refund()'s fallback) of another amount: not this call's refund.
			}
			$sent = null !== $wc_refund ? (string) $wc_refund->get_meta( SMPW_Order_Data::REFUND_ID ) : '';
			if ( '' !== $sent ) {
				// Paid back already: only a guess (booked_refund()'s fallback) can name it — another refund ran meanwhile.
				/* translators: 1: WooCommerce refund ID, 2: Stripe refund ID */
				return new WP_Error( 'smpw_refund', sprintf( __( 'Nothing has been refunded: refund #%1$d has already been sent to Stripe (%2$s) — two refunds at the same time? Check the order and try again.', 'stripe-mobilepay-woocommerce' ), (int) $wc_refund->get_id(), $sent ) );
			}
			if ( $data->released() ) {
				$data->add_precapture_refund( $minor );
				$data->save();
				/* translators: 1: amount, 2: PaymentIntent ID */
				$order->add_order_note( sprintf( __( '%1$s has been booked as refunded. Nothing was captured via MobilePay (the hold %2$s has been released), so nothing is sent back.', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $minor ), $id ) );
				return true;
			}
			if ( $data->captured() && self::left_out( $order, $data, $wc_refund, $minor ) ) {
				$data->add_precapture_refund( $minor );
				$data->save();
				/* translators: 1: amount, 2: PaymentIntent ID */
				$order->add_order_note( sprintf( __( '%1$s is not sent back via MobilePay: the refund had already been deducted when the amount was captured (%2$s).', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $minor ), $id ) );
				return true;
			}
			$intent = self::client()->retrieve_intent( $id, (string) $attempt['mode'] );
			if ( is_wp_error( $intent ) ) {
				/* translators: 1: PaymentIntent ID, 2: the error */
				return new WP_Error( 'smpw_refund', sprintf( __( 'The MobilePay payment (%1$s) could not be checked with Stripe, so nothing has been refunded: %2$s', 'stripe-mobilepay-woocommerce' ), $id, $intent->get_error_message() ) );
			}
			if ( ! $data->captured() && 'succeeded' === (string) $intent->status ) {
				// Captured meanwhile (e.g. in the Stripe dashboard): recorded first, as the state table does (MARK_CAPTURED).
				// This refund comes after it.
				$before = SMPW_Money::to_minor( $order->get_total_refunded() ) - $minor;
				self::mark_captured( $order, $data, $id, (int) ( $intent->amount_received ?? 0 ), 0, array( 'refunded' => max( 0, $before ), 'ids' => array() ) );
			}
			return $data->captured()
				? self::refund_captured( $order, $data, $attempt, $intent, $minor, $reason, $wc_refund )
				: self::refund_held( $order, $data, $attempt, $intent, $minor );
		} finally {
			SMPW_Lock::release( $order_id );
		}
	}

	/**
	 * Did the capture already leave WooCommerce refund $wc_refund out? WooCommerce saves a refund before it asks us, so
	 * a capture that ran meanwhile may have counted it. Only a refund the capture listed whose amount is also in the
	 * refunded total it read counts (it reads the total first, then the ids): paying it back too would pay it twice.
	 */
	private static function left_out( WC_Order $order, SMPW_Order_Data $data, ?WC_Order_Refund $wc_refund, int $minor ): bool {
		if ( null === $wc_refund || ! in_array( (int) $wc_refund->get_id(), $data->capture_refund_ids(), true ) ) {
			return false;
		}
		return SMPW_Money::to_minor( $order->get_total_refunded() ) - $data->refunded_at_capture() < $minor;
	}

	/**
	 * Captured: a Stripe refund, key refund-<WooCommerce refund id>. A lost answer (a transient error: the refund may
	 * have been made) is looked up by that reference and this amount and adopted — never a second refund. Any other
	 * error is Stripe's answer, e.g. an idempotency clash had the key named another WooCommerce refund with its own
	 * Stripe refund: adopting that one would book this refund with no money sent back. A refund Stripe is still
	 * processing is noted as sent, not as refunded.
	 *
	 * @return true|WP_Error
	 */
	private static function refund_captured( WC_Order $order, SMPW_Order_Data $data, array $attempt, object $intent, int $minor, string $reason, ?WC_Order_Refund $wc_refund ) {
		$order_id = $order->get_id();
		$id       = (string) $attempt['id'];
		if ( 'succeeded' !== (string) $intent->status ) {
			/* translators: 1: PaymentIntent ID, 2: PaymentIntent status */
			return new WP_Error( 'smpw_refund', sprintf( __( 'The MobilePay payment (%1$s) has the status "%2$s" in Stripe — there is nothing to refund.', 'stripe-mobilepay-woocommerce' ), $id, $intent->status ) );
		}
		$refundable = self::refundable( $intent );
		if ( $minor > $refundable ) {
			/* translators: %s: amount */
			return new WP_Error( 'smpw_refund', sprintf( __( 'At most %s can be refunded via MobilePay on this order.', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $refundable ) ) );
		}
		// An earlier try whose answer got lost may have refunded already (its WooCommerce refund was deleted): a new
		// click must not refund it again. sync_refunds() books that one (the webhook, or "Sync with Stripe"), then
		// a new refund can go.
		$lost = self::unbooked_refund( $order_id, $id, (string) $attempt['mode'] );
		if ( null !== $lost ) {
			/* translators: 1: amount, 2: Stripe refund ID */
			return new WP_Error( 'smpw_refund', sprintf( __( 'Stripe has already refunded %1$s (%2$s) in an earlier attempt, but that refund has not been booked here yet (that happens when Stripe reports it, or with "Sync with Stripe" in the MobilePay box). Do not refund again now — check the payment in the Stripe dashboard.', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( (int) ( $lost->amount ?? 0 ) ), (string) ( $lost->id ?? '' ) ) );
		}
		$ref    = $wc_refund ? (string) $wc_refund->get_id() : 'x' . substr( md5( $minor . '|' . microtime() ), 0, 12 );
		$result = self::client()->create_refund(
			array(
				'payment_intent' => $id,
				'amount'         => $minor,
				'reason'         => 'requested_by_customer',
				'metadata'       => array(
					'smpw'              => '1',
					'smpw_site'         => SMPW_Plugin::site(),
					'smpw_order_id'     => (string) $order_id,
					'smpw_wc_refund_id' => $ref,
				),
			),
			self::key( $order_id, 'refund-' . $ref ),
			(string) $attempt['mode']
		);
		if ( is_wp_error( $result ) ) {
			// The answer may be lost while the refund was made: WooCommerce would delete its refund, and the next click
			// (a new WooCommerce refund, a new key) would refund twice. Adopt ours — this reference, this amount — if
			// Stripe has it. Only then: any other error is Stripe's answer, and WooCommerce deletes its refund.
			$made = self::transient( $result ) ? self::find_refund( $id, (string) $attempt['mode'], $ref, $minor ) : null;
			if ( null === $made ) {
				/* translators: %s: Stripe's error message */
				return new WP_Error( 'smpw_refund', sprintf( __( 'Stripe rejected the refund: %s', 'stripe-mobilepay-woocommerce' ), $result->get_error_message() ) );
			}
			$result = $made;
		}
		$status = (string) ( $result->status ?? '' );
		if ( in_array( $status, array( 'failed', 'canceled' ), true ) ) {
			/* translators: 1: Stripe refund ID, 2: refund status */
			return new WP_Error( 'smpw_refund', sprintf( __( 'Stripe could not complete the refund (%1$s, status: %2$s).', 'stripe-mobilepay-woocommerce' ), $result->id, $status ) );
		}
		if ( $wc_refund ) {
			$wc_refund->update_meta_data( SMPW_Order_Data::REFUND_ID, (string) $result->id );
			$wc_refund->save();
		}
		$why = '' !== $reason ? ' — ' . $reason : '';
		self::once(
			$order,
			$data,
			'refund_note_' . $result->id,
			'succeeded' === $status
				/* translators: 1: amount, 2: Stripe refund ID, 3: " — " and the refund's reason, or nothing */
				? sprintf( __( '%1$s has been refunded via MobilePay (%2$s)%3$s.', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $minor ), $result->id, $why )
				/* translators: 1: amount, 2: Stripe refund ID, 3: " — " and the refund's reason, or nothing, 4: refund status */
				: sprintf( __( '%1$s has been sent for refund via MobilePay (%2$s)%3$s. Stripe is still processing it (status: %4$s); if it fails, a note will appear here.', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $minor ), $result->id, $why, $status )
		);
		return true;
	}

	/** Our Stripe refund of $amount (minor units) for WooCommerce refund $ref (its metadata smpw_wc_refund_id), unless it failed; null when none. */
	private static function find_refund( string $intent_id, string $mode, string $ref, int $amount ): ?object {
		foreach ( self::live_refunds( $intent_id, $mode ) as $refund ) {
			if ( $ref === (string) ( $refund->metadata->smpw_wc_refund_id ?? '' ) && $amount === (int) ( $refund->amount ?? -1 ) ) {
				return $refund;
			}
		}
		return null;
	}

	/**
	 * A refund of ours at Stripe (made from a WooCommerce refund) that isn't booked here: its WooCommerce refund is
	 * gone and no refund carries its id. null when none — or when Stripe can't list them (then nothing is blocked).
	 */
	private static function unbooked_refund( int $order_id, string $intent_id, string $mode ): ?object {
		$known = null;
		foreach ( self::live_refunds( $intent_id, $mode ) as $refund ) {
			$ref = (string) ( $refund->metadata->smpw_wc_refund_id ?? '' );
			if ( '1' !== (string) ( $refund->metadata->smpw ?? '' ) || '' === $ref || ! ctype_digit( $ref ) || self::refund_exists( $order_id, $ref ) ) {
				continue;
			}
			$known = $known ?? self::known_refund_ids( $order_id );
			if ( ! in_array( (string) ( $refund->id ?? '' ), $known, true ) ) {
				return $refund;
			}
		}
		return null;
	}

	/** The PaymentIntent's refunds at Stripe that haven't failed (pending or succeeded); none when they can't be listed. */
	private static function live_refunds( string $intent_id, string $mode ): array {
		$list = self::client()->list_refunds( $intent_id, $mode );
		if ( is_wp_error( $list ) ) {
			return array();
		}
		return array_values( array_filter( (array) ( $list->data ?? array() ), static fn( $refund ): bool => is_object( $refund ) && ! in_array( (string) ( $refund->status ?? '' ), array( 'failed', 'canceled' ), true ) ) );
	}

	/**
	 * Not captured yet: the refund lowers the coming capture (WooCommerce's refunded total already includes it). With
	 * nothing left to capture, the hold is released first — refused → a WP_Error and nothing booked. No live hold → only
	 * booked (nothing to lower or give back).
	 *
	 * @return true|WP_Error
	 */
	private static function refund_held( WC_Order $order, SMPW_Order_Data $data, array $attempt, object $intent, int $minor ) {
		$id = (string) $attempt['id'];
		if ( 'requires_capture' !== (string) $intent->status ) {
			$data->add_precapture_refund( $minor );
			$data->save();
			/* translators: 1: amount, 2: PaymentIntent ID, 3: PaymentIntent status */
			$order->add_order_note( sprintf( __( '%1$s has been booked as refunded. The MobilePay payment (%2$s) has no active hold (status: %3$s), so there is nothing to release or send back.', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $minor ), $id, $intent->status ) );
			return true;
		}
		$hold = (int) $intent->amount_capturable;
		$left = SMPW_Money::capture_due( $hold, SMPW_Money::to_minor( $order->get_total() ), SMPW_Money::to_minor( $order->get_total_refunded() ) );
		if ( $left > 0 ) {
			$data->add_precapture_refund( $minor );
			$data->save();
			/* translators: 1: amount refunded, 2: the most that will be captured */
			$order->add_order_note( sprintf( __( '%1$s will not be captured (refunded before shipping). When the order is marked Completed, at most %2$s will be captured; the rest of the hold will be released.', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $minor ), SMPW_Money::format( $left ) ) );
			return true;
		}
		if ( ! self::cancel_intent( $order, $attempt, 'requested_by_customer', '' ) ) {
			/* translators: %s: PaymentIntent ID */
			return new WP_Error( 'smpw_release', sprintf( __( 'The MobilePay hold (%s) could not be released in Stripe, so the refund has not been made. Please try again shortly.', 'stripe-mobilepay-woocommerce' ), $id ) );
		}
		$data->add_precapture_refund( $minor );
		$data->save();
		/* translators: 1: PaymentIntent ID, 2: amount */
		self::once( $order, $data, 'released_' . $id, sprintf( __( 'The full amount was refunded before it was captured — the MobilePay hold (%1$s, %2$s) has been released.', 'stripe-mobilepay-woocommerce' ), $id, SMPW_Money::format( $hold ) ) );
		return true;
	}

	/** The order's newest WooCommerce refund — booked_refund()'s fallback only: with two refunds in flight it names the later one for both. */
	private static function newest_refund( int $order_id ): ?WC_Order_Refund {
		$refunds = wc_get_orders( array( 'type' => 'shop_order_refund', 'parent' => $order_id, 'limit' => 1, 'orderby' => 'ID', 'order' => 'DESC' ) );
		return isset( $refunds[0] ) && $refunds[0] instanceof WC_Order_Refund ? $refunds[0] : null;
	}

	/**
	 * The payment's refunds at Stripe, booked here: one made outside WooCommerce (the Stripe dashboard) — or one of
	 * ours whose WooCommerce refund is gone (the answer got lost, so WooCommerce deleted it) — becomes a WooCommerce
	 * refund, so the order's refund records (and anything built on them) follow; refunds that fail at Stripe are reported.
	 *
	 * @return string 'ok' | 'locked' | 'error' (Stripe unreadable, or a booking that may work later: the webhook
	 *                answers 503 and Stripe retries). A refund that can never be booked here is noted once: 'ok'.
	 */
	public static function sync_refunds( WC_Order $order ): string {
		$order_id = $order->get_id();
		if ( ! SMPW_Lock::acquire( $order_id, 15 ) ) {
			return 'locked';
		}
		try {
			$order   = wc_get_order( $order_id );
			$data    = new SMPW_Order_Data( $order );
			$id      = $data->payment();
			$attempt = '' !== $id ? $data->attempt( $id ) : null;
			if ( null === $attempt ) {
				return 'ok';
			}
			$list = self::client()->list_refunds( $id, (string) $attempt['mode'] );
			if ( is_wp_error( $list ) ) {
				return 'error';
			}
			$known  = self::known_refund_ids( $order_id );
			$result = 'ok';
			foreach ( (array) ( $list->data ?? array() ) as $refund ) {
				$rid    = (string) ( $refund->id ?? '' );
				$status = (string) ( $refund->status ?? '' );
				$amount = (int) ( $refund->amount ?? 0 );
				if ( '' === $rid ) {
					continue;
				}
				if ( in_array( $status, array( 'failed', 'canceled' ), true ) ) {
					$text = 'failed' === $status
						/* translators: 1: Stripe refund ID, 2: amount */
						? __( 'The MobilePay refund %1$s (%2$s) has failed in Stripe — the money has not been sent back. Check the payment in the Stripe dashboard.', 'stripe-mobilepay-woocommerce' )
						/* translators: 1: Stripe refund ID, 2: amount */
						: __( 'The MobilePay refund %1$s (%2$s) has been canceled in Stripe — the money has not been sent back. Check the payment in the Stripe dashboard.', 'stripe-mobilepay-woocommerce' );
					$note = self::once( $order, $data, 'refund_failed_' . $rid, sprintf( $text, $rid, SMPW_Money::format( $amount ) ) );
					if ( '' !== $note ) {
						SMPW_Admin::email( $order, __( 'A MobilePay refund failed', 'stripe-mobilepay-woocommerce' ), $note );
					}
					continue;
				}
				if ( 'succeeded' !== $status ) {
					continue; // Still pending at Stripe — not confirmed yet, so nothing is claimed; a later sync picks it up.
				}
				$ref = (string) ( $refund->metadata->smpw_wc_refund_id ?? '' );
				if ( in_array( $rid, $known, true ) || self::refund_exists( $order_id, $ref ) ) {
					continue; // Booked already: it carries the Stripe id, or it is the WooCommerce refund that made it.
				}
				$now  = wc_get_order( $order_id ); // WooCommerce's totals after any booking above.
				$room = SMPW_Money::to_minor( $now->get_total() ) - SMPW_Money::to_minor( $now->get_total_refunded() );
				if ( $amount <= 0 || $amount > $room ) {
					// WooCommerce refuses it ("Invalid refund amount") every time: a retry can't help.
					/* translators: 1: Stripe refund ID, 2: amount, 3: what the order has left to refund */
					$note = self::once( $order, $data, 'refund_book_fail_' . $rid, sprintf( __( 'A refund in Stripe (%1$s, %2$s) cannot be booked here: the order only has %3$s left to refund. Check the order and the payment in the Stripe dashboard.', 'stripe-mobilepay-woocommerce' ), $rid, SMPW_Money::format( $amount ), SMPW_Money::format( max( 0, $room ) ) ) );
					if ( '' !== $note ) {
						SMPW_Admin::email( $order, __( 'A MobilePay refund could not be booked', 'stripe-mobilepay-woocommerce' ), $note );
					}
					continue;
				}
				$wc_refund = wc_create_refund(
					array(
						'order_id'       => $order_id,
						'amount'         => SMPW_Money::from_minor( $amount ),
						/* translators: %s: Stripe refund ID */
						'reason'         => sprintf( __( 'Refunded in Stripe (%s)', 'stripe-mobilepay-woocommerce' ), $rid ),
						'refund_payment' => false,
						'restock_items'  => false,
					)
				);
				// A booking that completes the refund makes WooCommerce set "Refunded", and on_cancelled() syncs the order
				// inside this lock, writing its own notes and flags: go on with the order as it is now — a once() below
				// would otherwise save the flags as they were before the loop and lose that sync's.
				$order = wc_get_order( $order_id );
				$data  = new SMPW_Order_Data( $order );
				if ( is_wp_error( $wc_refund ) ) {
					$result = 'error'; // May work later: Stripe sends the event again.
					/* translators: 1: Stripe refund ID, 2: amount, 3: the error */
					$note = self::once( $order, $data, 'refund_book_retry_' . $rid, sprintf( __( 'A refund in Stripe (%1$s, %2$s) could not be booked here right now: %3$s. It will be tried again automatically.', 'stripe-mobilepay-woocommerce' ), $rid, SMPW_Money::format( $amount ), $wc_refund->get_error_message() ) );
					if ( '' !== $note ) {
						SMPW_Admin::email( $order, __( 'A MobilePay refund could not be booked', 'stripe-mobilepay-woocommerce' ), $note );
					}
					continue;
				}
				$wc_refund->update_meta_data( SMPW_Order_Data::REFUND_ID, $rid );
				$wc_refund->save();
				$order->add_order_note(
					'' === $ref
						/* translators: 1: amount, 2: Stripe refund ID */
						? sprintf( __( '%1$s has been refunded in Stripe (%2$s) and booked as a refund here.', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $amount ), $rid )
						/* translators: 1: amount, 2: Stripe refund ID */
						: sprintf( __( '%1$s has been refunded via MobilePay (%2$s), but the refund did not exist here (Stripe\'s answer got lost, or it was deleted) — now it has been booked.', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $amount ), $rid )
				);
			}
			return $result;
		} finally {
			SMPW_Lock::release( $order_id );
		}
	}

	/** Whether WooCommerce refund $ref (a Stripe refund's smpw_wc_refund_id) still exists on this order. */
	private static function refund_exists( int $order_id, string $ref ): bool {
		if ( '' === $ref || ! ctype_digit( $ref ) ) {
			return false;
		}
		$refund = wc_get_order( (int) $ref );
		return $refund instanceof WC_Order_Refund && (int) $refund->get_parent_id() === $order_id;
	}

	private static function known_refund_ids( int $order_id ): array {
		$ids = array();
		foreach ( wc_get_orders( array( 'type' => 'shop_order_refund', 'parent' => $order_id, 'limit' => -1 ) ) as $refund ) {
			$rid = (string) $refund->get_meta( SMPW_Order_Data::REFUND_ID );
			if ( '' !== $rid ) {
				$ids[] = $rid;
			}
		}
		return $ids;
	}

	/**
	 * A customer dispute (chargeback): noted and mailed once per state, under the order lock on a fresh order;
	 * handled in the Stripe dashboard.
	 *
	 * @return string 'ok' | 'locked' | 'error' (the webhook answers 503 unless 'ok', so Stripe retries)
	 */
	public static function dispute( WC_Order $order, string $dispute_id, string $mode ): string {
		$order_id = $order->get_id();
		if ( ! SMPW_Lock::acquire( $order_id, 15 ) ) {
			return 'locked';
		}
		try {
			$dispute = self::client()->retrieve_dispute( $dispute_id, $mode );
			if ( is_wp_error( $dispute ) ) {
				return 'error';
			}
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return 'ok';
			}
			$status = (string) ( $dispute->status ?? '' );
			$closed = in_array( $status, array( 'won', 'lost', 'warning_closed' ), true );
			if ( 'won' === $status ) {
				/* translators: %s: Stripe dispute ID */
				$text = sprintf( __( 'The dispute over the MobilePay payment (%s) has been decided: won — the money stays with the shop.', 'stripe-mobilepay-woocommerce' ), $dispute_id );
			} elseif ( 'lost' === $status ) {
				/* translators: %s: Stripe dispute ID */
				$text = sprintf( __( 'The dispute over the MobilePay payment (%s) has been decided: lost — the amount has been returned to the customer.', 'stripe-mobilepay-woocommerce' ), $dispute_id );
			} elseif ( $closed ) {
				/* translators: %s: Stripe dispute ID */
				$text = sprintf( __( 'The dispute over the MobilePay payment (%s) has been decided: closed.', 'stripe-mobilepay-woocommerce' ), $dispute_id );
			} else {
				/* translators: 1: Stripe dispute ID, 2: amount, 3: the reason Stripe gives */
				$text = sprintf( __( 'The customer has disputed the MobilePay payment (%1$s, %2$s, reason: %3$s). Respond in the Stripe dashboard before the deadline.', 'stripe-mobilepay-woocommerce' ), $dispute_id, SMPW_Money::format( (int) ( $dispute->amount ?? 0 ) ), (string) ( $dispute->reason ?? __( 'unknown', 'stripe-mobilepay-woocommerce' ) ) );
			}
			$note = self::once( $order, new SMPW_Order_Data( $order ), 'dispute_' . $dispute_id . '_' . $status, $text );
			if ( '' !== $note && 'won' !== $status ) {
				SMPW_Admin::email( $order, __( 'A MobilePay payment has been disputed', 'stripe-mobilepay-woocommerce' ), $note );
			}
			return 'ok';
		} finally {
			SMPW_Lock::release( $order_id );
		}
	}

	/**
	 * An order that switched from MobilePay to another payment method (the customer came back and paid by card)
	 * but still has MobilePay attempts: close them — release a late approval, give back anything taken.
	 *
	 * @return string 'ok' | 'locked' | 'error' (the webhook answers 503 unless 'ok', so Stripe retries). 'error' only
	 *                while a later try can still help (see retry_state()); a failure it can't is noted once: 'ok'.
	 */
	public static function close_orphans( WC_Order $order, string $source ): string {
		$order_id = $order->get_id();
		if ( ! SMPW_Lock::acquire( $order_id, 15 ) ) {
			return 'locked';
		}
		$result = 'ok';
		try {
			$order = wc_get_order( $order_id );
			$data  = new SMPW_Order_Data( $order );
			foreach ( $data->attempts() as $attempt ) {
				$intent = self::client()->retrieve_intent( (string) $attempt['id'], (string) $attempt['mode'] );
				if ( is_wp_error( $intent ) ) {
					if ( SMPW_Stripe::retryable( $intent ) ) {
						$result = 'error';
					} else {
						SMPW_Log::warning( 'orphans: attempt unreadable', array( 'order' => $order_id, 'intent' => (string) $attempt['id'], 'error' => $intent->get_error_message() ) );
					}
					continue;
				}
				if ( '' !== self::mismatch( $intent, $order_id, $attempt, SMPW_Plugin::site() ) ) {
					continue;
				}
				$id   = (string) $intent->id;
				$note = '';
				if ( 'requires_capture' === $intent->status ) {
					$state = self::cancel_intent( $order, $attempt, 'duplicate', '' ) ? 'done' : self::retry_state( $attempt, 'release' );
					if ( 'done' === $state ) {
						/* translators: 1: PaymentIntent ID, 2: amount */
						$note = self::once( $order, $data, 'orphan_' . $id, sprintf( __( 'The customer paid with another payment method, but also approved MobilePay (%1$s). The MobilePay hold (%2$s) has been released.', 'stripe-mobilepay-woocommerce' ), $id, SMPW_Money::format( (int) $intent->amount_capturable ) ) );
					} else {
						// Stripe hasn't released it: say so once; retry only while a later try can still do it.
						$result = 'retry' === $state ? 'error' : $result;
						/* translators: 1: PaymentIntent ID, 2: amount */
						$failure_note = self::once( $order, $data, 'orphan_fail_' . $id, sprintf( __( 'The customer paid with another payment method, but the MobilePay hold (%1$s, %2$s) could not be released automatically. Release it in the Stripe dashboard.', 'stripe-mobilepay-woocommerce' ), $id, SMPW_Money::format( (int) $intent->amount_capturable ) ) );
						if ( '' !== $failure_note ) {
							SMPW_Admin::email( $order, __( 'An extra MobilePay payment could not be released', 'stripe-mobilepay-woocommerce' ), $failure_note );
						}
					}
				} elseif ( 'succeeded' === $intent->status && ! $data->flag( 'dupe_' . $id ) ) {
					$state = self::refund_duplicate( $order, $data, $intent, $attempt ) ? 'done' : self::retry_state( $attempt, 'refund' );
					if ( 'done' === $state ) {
						$data->set_flag( 'dupe_' . $id ); // Refunded now — or found refunded at Stripe already: never again.
						$data->save();
						/* translators: 1: PaymentIntent ID, 2: amount */
						$note = self::once( $order, $data, 'orphan_' . $id, sprintf( __( 'The customer paid with another payment method, but the MobilePay amount (%1$s, %2$s) was captured too — it has been refunded.', 'stripe-mobilepay-woocommerce' ), $id, SMPW_Money::format( (int) $intent->amount_received ) ) );
					} elseif ( 'retry' === $state ) {
						$result = 'error'; // refund_duplicate() already noted + mailed its own failure once — don't duplicate it here.
					}
				} elseif ( in_array( $intent->status, SMPW_Decision::OPEN, true ) ) {
					self::cancel_intent( $order, $attempt, 'abandoned', '' );
				}
				if ( '' !== $note ) {
					SMPW_Admin::email( $order, __( 'An extra MobilePay payment was released', 'stripe-mobilepay-woocommerce' ), $note );
				}
			}
			SMPW_Log::info( 'orphans closed', array( 'order' => $order_id, 'source' => $source, 'result' => $result ) );
			return $result;
		} finally {
			SMPW_Lock::release( $order_id );
		}
	}

	/**
	 * After a release ($action 'release') or a refund ('refund') of an attempt failed: can a later try still do it?
	 * The error stays inside cancel_intent()/refund_duplicate(), so the equivalent of retryable() is Stripe's own
	 * state, read fresh: 'retry' — still to do, with the money still held (or Stripe unreadable for now: retryable());
	 * 'done' — done after all (released; refunded in full, e.g. in the dashboard); 'stuck' — no retry can do it
	 * (captured instead, refunded in part, disputed, or Stripe refuses for good).
	 */
	private static function retry_state( array $attempt, string $action ): string {
		$intent = self::client()->retrieve_intent( (string) $attempt['id'], (string) $attempt['mode'] );
		if ( is_wp_error( $intent ) ) {
			return SMPW_Stripe::retryable( $intent ) ? 'retry' : 'stuck';
		}
		$status = (string) ( $intent->status ?? '' );
		if ( 'release' === $action ) {
			return 'canceled' === $status ? 'done' : ( 'requires_capture' === $status ? 'retry' : 'stuck' );
		}
		$received = (int) ( $intent->amount_received ?? 0 );
		if ( 'succeeded' !== $status || $received <= 0 ) {
			return 'stuck';
		}
		$charge   = $intent->latest_charge ?? null;
		$refunded = is_object( $charge ) ? (int) ( $charge->amount_refunded ?? 0 ) : 0;
		if ( $refunded >= $received ) {
			return 'done';
		}
		return 0 === $refunded && ! ( is_object( $charge ) && ! empty( $charge->disputed ) ) ? 'retry' : 'stuck';
	}

	/** WooCommerce is about to cancel an unpaid order (the 60-minute hold-stock timer): ask Stripe first. */
	public static function before_unpaid_cancel( $cancel, $order ) {
		if ( ! $cancel || ! self::is_ours( $order ) ) {
			return $cancel;
		}
		$result = self::sync( $order, 'unpaid-cancel' );
		if ( in_array( $result, array( 'locked', 'error' ), true ) ) {
			return false; // Can't tell right now — the next run decides.
		}
		$fresh = wc_get_order( $order->get_id() );
		return $fresh instanceof WC_Order && $fresh->has_status( 'pending' ) ? $cancel : false;
	}
}
