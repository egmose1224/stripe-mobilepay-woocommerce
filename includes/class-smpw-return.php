<?php
defined( 'ABSPATH' ) || exit;

/**
 * The customer back from MobilePay: /?wc-api=smpw_return&order=…&key=…&attempt=…
 * Works without the WooCommerce session (the MobilePay app may open another browser): order + order key
 * identify the order; Stripe's own query parameters are never trusted — sync() asks Stripe.
 */
final class SMPW_Return {

	public const MAX_TRIES = 10;

	/** For the customer after an attempt that didn't go through. */
	public static function failed_message(): string {
		return __( 'The MobilePay payment was not completed. Please try again, or choose another payment method.', 'stripe-mobilepay-woocommerce' );
	}

	/** For the customer when Stripe hasn't answered in time. */
	public static function pending_message(): string {
		return __( 'We are still waiting for an answer from MobilePay. If you have approved the payment, your order will go through by itself, and you will receive an order confirmation by e-mail.', 'stripe-mobilepay-woocommerce' );
	}

	public static function hooks(): void {
		add_action( 'woocommerce_api_smpw_return', array( __CLASS__, 'handle' ) );
		add_filter( 'render_block_woocommerce/checkout', array( __CLASS__, 'checkout_notice' ) );
		// The same message where the checkout block isn't: the order-pay page (WooCommerce's classic form, also inside the
		// block page) and a classic checkout — before WooCommerce prints its own notices there (priority 10).
		add_action( 'before_woocommerce_pay', array( __CLASS__, 'page_notice' ), 5 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'page_notice' ), 5 );
	}

	/** $pay_page: the attempt was started on the order's payment page (order-pay), which a retry goes back to. */
	public static function url( WC_Order $order, int $attempt, bool $pay_page = false ): string {
		return self::build_url( home_url( '/' ), $order->get_id(), $order->get_order_key(), $attempt, $pay_page );
	}

	/** Pure. */
	public static function build_url( string $home, int $order_id, string $key, int $attempt, bool $pay_page = false ): string {
		$args = array(
			'wc-api'  => 'smpw_return',
			'order'   => $order_id,
			'key'     => $key,
			'attempt' => $attempt,
		);
		if ( $pay_page ) {
			$args['pay'] = 1;
		}
		return rtrim( $home, '/' ) . '/?' . http_build_query( $args, '', '&' );
	}

	/**
	 * Pure: where a customer goes to try again — 'order-pay' (the order's own payment page) for an attempt started
	 * there while the order can still be paid (a Failed order paid again, a payment link: the cart may be empty),
	 * else 'checkout' (the cart is intact; WooCommerce reuses the pending order).
	 */
	public static function retry_page( bool $pay_page, bool $needs_payment ): string {
		return $pay_page && $needs_payment ? 'order-pay' : 'checkout';
	}

	/** WooCommerce's wc-api dispatcher calls this; every branch ends the request itself. */
	public static function handle(): void {
		nocache_headers();
		// phpcs:disable WordPress.Security.NonceVerification -- a link from Stripe; the order key is the credential.
		$order_id = absint( $_GET['order'] ?? 0 );
		$key      = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
		$attempt  = absint( $_GET['attempt'] ?? 0 );
		$try      = min( self::MAX_TRIES, absint( $_GET['try'] ?? 0 ) );
		$pay_page = 1 === absint( $_GET['pay'] ?? 0 );
		// phpcs:enable
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
		if ( ! SMPW_Payments::is_ours( $order ) ) {
			wp_safe_redirect( $order->needs_payment() ? wc_get_checkout_url() : $order->get_checkout_order_received_url() );
			exit;
		}
		$status     = SMPW_Payments::sync( $order, 'return' );
		$order      = wc_get_order( $order_id );
		$authorized = ( new SMPW_Order_Data( $order ) )->authorized();
		$outcome    = self::outcome( $order->get_status(), $status, $try, $authorized );
		if ( 'thanks' === $outcome ) {
			SMPW_Payments::empty_carts( $order );
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
		if ( 'wait' === $outcome ) {
			self::render_wait( add_query_arg( 'try', $try + 1, self::url( $order, $attempt, $pay_page ) ) );
			exit;
		}
		$back = 'order-pay' === self::retry_page( $pay_page, $order->needs_payment() ) ? $order->get_checkout_payment_url() : wc_get_checkout_url();
		wp_safe_redirect( add_query_arg( 'smpw', $outcome, $back ) );
		exit;
	}

	/**
	 * Pure: 'thanks' (paid) | 'wait' (Stripe hasn't answered yet) | 'pending' (gave up waiting) | 'failed'.
	 * $authorized is SMPW_Order_Data::authorized() for this order: an on-hold order is only paid once it has been
	 * authorized — see SMPW_Decision::unpaid() — so an on-hold order that was never authorized waits (or times out)
	 * like a pending one instead of reaching the thank-you page.
	 */
	public static function outcome( string $order_status, string $intent_status, int $try, bool $authorized ): string {
		$unpaid = SMPW_Decision::unpaid( $order_status, $authorized );
		if ( ! $unpaid && in_array( $order_status, array( 'processing', 'completed', 'on-hold' ), true ) ) {
			return 'thanks';
		}
		$unanswered = in_array( $intent_status, array( '', 'requires_action', 'requires_confirmation', 'processing', 'requires_capture', 'succeeded', 'locked', 'error' ), true );
		if ( $unanswered && $unpaid ) {
			return $try < self::MAX_TRIES ? 'wait' : 'pending';
		}
		return 'failed';
	}

	/** The message after a MobilePay attempt (?smpw=failed|pending): 'failed', 'pending' or ''. */
	private static function flag(): string {
		$flag = sanitize_key( wp_unslash( $_GET['smpw'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $flag, array( 'failed', 'pending' ), true ) ? $flag : '';
	}

	/** Above the checkout block. On the order-pay page — rendered through this block too — page_notice() prints it. */
	public static function checkout_notice( $html ) {
		$flag = self::flag();
		if ( '' === $flag || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) ) {
			return $html;
		}
		return self::notice_html( $flag ) . $html;
	}

	/** On the order-pay page and a classic checkout: WooCommerce's own notice, where its notices go. */
	public static function page_notice(): void {
		$flag = self::flag();
		if ( '' !== $flag && function_exists( 'wc_print_notice' ) ) {
			wc_print_notice( esc_html( 'failed' === $flag ? self::failed_message() : self::pending_message() ), 'failed' === $flag ? 'error' : 'notice' );
		}
	}

	/** No side effects: the notice's HTML. */
	public static function notice_html( string $flag ): string {
		$failed = 'failed' === $flag;
		return sprintf(
			'<div class="wc-block-components-notice-banner is-%1$s smpw-notice" role="%2$s"><div class="wc-block-components-notice-banner__content">%3$s</div></div>',
			$failed ? 'error' : 'info',
			$failed ? 'alert' : 'status',
			htmlspecialchars( $failed ? self::failed_message() : self::pending_message(), ENT_QUOTES, 'UTF-8' )
		);
	}

	/** "Waiting for an answer from MobilePay …" — reloads itself every 3 s (each reload asks Stripe again). */
	private static function render_wait( string $url ): void {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$href = esc_url( $url );
		/* translators: %s: the shop's name */
		$title = sprintf( __( 'Waiting for MobilePay – %s', 'stripe-mobilepay-woocommerce' ), SMPW_Plugin::shop_name() );
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta http-equiv="refresh" content="3;url=' . $href . '"><title>' . esc_html( $title ) . '</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#fff;color:#1e1e1e;font-family:system-ui,sans-serif;text-align:center}main{max-width:28rem}h1{font-size:1.4rem}p{line-height:1.5}.s{width:36px;height:36px;margin:0 auto 16px;border:4px solid #ddd;border-top-color:#555;border-radius:50%;animation:r 1s linear infinite}@keyframes r{to{transform:rotate(360deg)}}@media (prefers-reduced-motion:reduce){.s{animation:none}}</style></head><body><main><div class="s" aria-hidden="true"></div><h1>' . esc_html__( 'Waiting for an answer from MobilePay …', 'stripe-mobilepay-woocommerce' ) . '</h1><p>' . esc_html__( 'This page refreshes by itself. Please don\'t close it — we\'ll send you on as soon as the payment is confirmed.', 'stripe-mobilepay-woocommerce' ) . '</p><p><a href="' . $href . '">' . esc_html__( 'Refresh now', 'stripe-mobilepay-woocommerce' ) . '</a></p></main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $href is escaped above.
	}
}
