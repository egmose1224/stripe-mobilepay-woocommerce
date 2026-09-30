<?php
defined( 'ABSPATH' ) || exit;

/**
 * MobilePay payments through Stripe: an order's state, syncing it with Stripe, the sweep, and this site's webhook.
 */
final class SMPW_CLI {

	/**
	 * Show an order's MobilePay state and ask Stripe for every attempt.
	 *
	 * ## OPTIONS
	 *
	 * <order>
	 * : Order ID.
	 */
	public function status( array $args ): void {
		$order = wc_get_order( (int) ( $args[0] ?? 0 ) );
		if ( ! $order instanceof WC_Order ) {
			WP_CLI::error( __( 'The order does not exist.', 'stripe-mobilepay-woocommerce' ) );
		}
		$data = new SMPW_Order_Data( $order );
		if ( $data->released() ) {
			$taken = __( 'nothing captured (released)', 'stripe-mobilepay-woocommerce' ); // Settled at "Completed" with nothing due: released, not captured.
		} else {
			/* translators: %s: amount, or "–" */
			$taken = sprintf( __( 'captured %s', 'stripe-mobilepay-woocommerce' ), $data->captured() ? SMPW_Money::format( $data->captured_amount() ) : '–' );
		}
		WP_CLI::log(
			sprintf(
				/* translators: 1: order number, 2: order status, 3: payment method, 4: the paying PaymentIntent, 5: the last PaymentIntent status seen, 6: amount reserved, 7: amount captured */
				__( 'Order #%1$s · %2$s · payment method %3$s · payment %4$s · last seen %5$s · reserved %6$s · %7$s', 'stripe-mobilepay-woocommerce' ),
				$order->get_order_number(),
				$order->get_status(),
				$order->get_payment_method(),
				'' !== $data->paying() ? $data->paying() : '–',
				'' !== $data->status() ? $data->status() : '–',
				$data->authorized() ? SMPW_Money::format( $data->authorized_amount() ) : '–',
				$taken
			)
		);
		foreach ( $data->attempts() as $attempt ) {
			$intent = SMPW_Payments::client()->retrieve_intent( (string) $attempt['id'], (string) $attempt['mode'] );
			/* translators: %s: the error */
			$state = is_wp_error( $intent ) ? sprintf( __( 'error: %s', 'stripe-mobilepay-woocommerce' ), $intent->get_error_message() ) : $intent->status;
			/* translators: 1: attempt number, 2: PaymentIntent ID, 3: "test" or "live", 4: amount, 5: the PaymentIntent's status at Stripe, or the error */
			WP_CLI::log( '  ' . sprintf( __( 'attempt %1$d  %2$s  (%3$s)  %4$s  → %5$s', 'stripe-mobilepay-woocommerce' ), (int) $attempt['attempt'], $attempt['id'], $attempt['mode'], SMPW_Money::format( (int) $attempt['amount'] ), $state ) );
		}
	}

	/**
	 * Sync an order with Stripe now — the payment, then its refunds (a dashboard refund, or one whose answer got lost,
	 * is booked).
	 *
	 * ## OPTIONS
	 *
	 * <order>
	 * : Order ID.
	 */
	public function sync( array $args ): void {
		$order = wc_get_order( (int) ( $args[0] ?? 0 ) );
		if ( ! $order instanceof WC_Order ) {
			WP_CLI::error( __( 'The order does not exist.', 'stripe-mobilepay-woocommerce' ) );
		}
		if ( SMPW_Payments::is_ours( $order ) ) {
			$result = SMPW_Payments::sync_with_refunds( $order, 'cli' );
			/* translators: 1: PaymentIntent status, 2: result of the refund check */
			$line = sprintf( __( '%1$s, refunds %2$s', 'stripe-mobilepay-woocommerce' ), '' !== $result['status'] ? $result['status'] : __( 'nothing to check', 'stripe-mobilepay-woocommerce' ), '' !== $result['refunds'] ? $result['refunds'] : __( 'not checked', 'stripe-mobilepay-woocommerce' ) );
		} else {
			$line = __( 'not a MobilePay order', 'stripe-mobilepay-woocommerce' );
		}
		/* translators: 1: order number, 2: what the sync found, 3: order status */
		WP_CLI::success( sprintf( __( 'Order #%1$s: %2$s → order status %3$s', 'stripe-mobilepay-woocommerce' ), $order->get_order_number(), $line, wc_get_order( $order->get_id() )->get_status() ) );
	}

	/**
	 * Run the hourly sweep now.
	 */
	public function reconcile(): void {
		SMPW_Reconcile::sweep();
		WP_CLI::success( __( 'Reconciled.', 'stripe-mobilepay-woocommerce' ) );
	}

	/**
	 * This site's webhook endpoint at Stripe. "create" and "delete" change the Stripe account's webhook endpoints.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : create, status or delete.
	 *
	 * [--mode=<mode>]
	 * : test or live (default: the Stripe plugin's current mode).
	 */
	public function webhook( array $args, array $assoc ): void {
		$mode = in_array( $assoc['mode'] ?? '', array( 'test', 'live' ), true ) ? (string) $assoc['mode'] : SMPW_Plugin::mode();
		$do   = (string) ( $args[0] ?? 'status' );
		if ( 'create' === $do || 'delete' === $do ) {
			$result = 'create' === $do ? SMPW_Webhook::create( $mode ) : SMPW_Webhook::delete( $mode );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			$done = 'create' === $do
				/* translators: 1: "test" or "live", 2: webhook endpoint ID */
				? __( 'Webhook created (%1$s): %2$s', 'stripe-mobilepay-woocommerce' )
				/* translators: 1: "test" or "live", 2: webhook endpoint ID */
				: __( 'Webhook deleted (%1$s): %2$s', 'stripe-mobilepay-woocommerce' );
			WP_CLI::success( sprintf( $done, $mode, SMPW_Webhook_Store::get( $mode )['id'] ) );
			return;
		}
		$hook = SMPW_Webhook_Store::get( $mode );
		WP_CLI::log(
			sprintf(
				/* translators: 1: "test" or "live", 2: webhook endpoint ID, 3: its URL, 4: when it was created, 5: when the last event arrived, 6: whether the signing secret is stored ("ok" or "missing") */
				__( '%1$s: %2$s → %3$s · created %4$s · last event %5$s · secret %6$s', 'stripe-mobilepay-woocommerce' ),
				$mode,
				'' !== $hook['id'] ? $hook['id'] : __( '(none)', 'stripe-mobilepay-woocommerce' ),
				'' !== $hook['url'] ? $hook['url'] : '–',
				$hook['created'] ? gmdate( 'Y-m-d H:i', (int) $hook['created'] ) . ' UTC' : '–',
				$hook['last_event_at'] ? gmdate( 'Y-m-d H:i', (int) $hook['last_event_at'] ) . ' UTC' : '–',
				'' !== SMPW_Webhook_Store::secret( $mode ) ? __( 'ok', 'stripe-mobilepay-woocommerce' ) : __( 'missing', 'stripe-mobilepay-woocommerce' )
			)
		);
	}
}
