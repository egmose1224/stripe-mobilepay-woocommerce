<?php
defined( 'ABSPATH' ) || exit;

/** The shop's view: the order meta box, notices, e-mails to the shop, the webhook panel in the settings. */
final class SMPW_Admin {

	public static function hooks(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 30, 2 );
		add_action( 'admin_post_smpw_sync', array( __CLASS__, 'manual_sync' ) );
		add_action( 'admin_post_smpw_webhook', array( __CLASS__, 'webhook_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/** Both order screens: HPOS passes the WC_Order, the posts screen a WP_Post. */
	public static function add_meta_box( $screen_id, $object = null ): void {
		$order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : null );
		if ( SMPW_Payments::is_ours( $order ) ) {
			add_meta_box( 'smpw-mobilepay', 'MobilePay', array( __CLASS__, 'render' ), $screen_id, 'side', 'default' );
		}
	}

	public static function render( $object ): void {
		$order = $object instanceof WC_Order ? $object : wc_get_order( $object->ID ?? 0 );
		if ( ! SMPW_Payments::is_ours( $order ) ) {
			return;
		}
		$data   = new SMPW_Order_Data( $order );
		$labels = array(
			'requires_action'         => __( 'Waiting for the customer', 'stripe-mobilepay-woocommerce' ),
			'requires_payment_method' => __( 'Not completed', 'stripe-mobilepay-woocommerce' ),
			'requires_capture'        => __( 'Reserved', 'stripe-mobilepay-woocommerce' ),
			'succeeded'               => __( 'Captured', 'stripe-mobilepay-woocommerce' ),
			'canceled'                => __( 'Canceled/expired', 'stripe-mobilepay-woocommerce' ),
			'processing'              => __( 'Processing', 'stripe-mobilepay-woocommerce' ),
		);
		echo '<ul style="margin:0 0 8px">';
		foreach ( array_reverse( $data->attempts() ) as $attempt ) {
			printf(
				'<li>%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>%s</li>',
				/* translators: %d: MobilePay attempt number */
				esc_html( sprintf( __( 'Attempt %d:', 'stripe-mobilepay-woocommerce' ), (int) $attempt['attempt'] ) ),
				esc_url( 'https://dashboard.stripe.com/' . ( 'test' === $attempt['mode'] ? 'test/' : '' ) . 'payments/' . rawurlencode( (string) $attempt['id'] ) ),
				esc_html( (string) $attempt['id'] ),
				$attempt['id'] === $data->paying() ? ' <strong>' . esc_html__( '(payment)', 'stripe-mobilepay-woocommerce' ) . '</strong>' : ''
			);
		}
		echo '</ul><p>';
		echo esc_html__( 'Status in Stripe:', 'stripe-mobilepay-woocommerce' ) . ' <strong>' . esc_html( $labels[ $data->status() ] ?? ( '' !== $data->status() ? $data->status() : '–' ) ) . '</strong>';
		if ( $data->authorized() ) {
			/* translators: %s: amount */
			echo '<br>' . esc_html( sprintf( __( 'Reserved: %s', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $data->authorized_amount() ) ) );
			if ( ! $data->captured() ) {
				/* translators: %s: date and time */
				echo '<br>' . esc_html( sprintf( __( 'Capture before: %s', 'stripe-mobilepay-woocommerce' ), wp_date( self::date_format(), $data->capture_before() ) ) );
			}
		}
		if ( $data->released() ) {
			echo '<br>' . esc_html__( 'Nothing captured (released)', 'stripe-mobilepay-woocommerce' ); // Settled at "Completed" with nothing due: the hold was released, not captured.
		} elseif ( $data->captured() ) {
			/* translators: %s: amount */
			echo '<br>' . esc_html( sprintf( __( 'Captured: %s', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $data->captured_amount() ) ) );
		}
		if ( $data->precapture_refunded() > 0 ) {
			/* translators: %s: amount */
			echo '<br>' . esc_html( sprintf( __( 'Refunded before capture: %s', 'stripe-mobilepay-woocommerce' ), SMPW_Money::format( $data->precapture_refunded() ) ) );
		}
		echo '</p>';
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=smpw_sync&order=' . $order->get_id() ), 'smpw_sync_' . $order->get_id() );
		printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $url ), esc_html__( 'Sync with Stripe', 'stripe-mobilepay-woocommerce' ) );
	}

	public static function manual_sync(): void {
		$order_id = absint( $_GET['order'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- checked next.
		if ( ! current_user_can( 'edit_shop_orders' ) || ! check_admin_referer( 'smpw_sync_' . $order_id ) ) {
			wp_die( esc_html__( 'Access denied.', 'stripe-mobilepay-woocommerce' ) );
		}
		$order = wc_get_order( $order_id );
		if ( SMPW_Payments::is_ours( $order ) ) {
			$result = SMPW_Payments::sync_with_refunds( $order, 'admin' ); // Refunds too: dashboard refunds are booked without the webhook.
			/* translators: 1: PaymentIntent status, 2: result of the refund check */
			$order->add_order_note( sprintf( __( 'Synced with Stripe (status: %1$s; refunds: %2$s).', 'stripe-mobilepay-woocommerce' ), '' !== $result['status'] ? $result['status'] : __( 'nothing to check', 'stripe-mobilepay-woocommerce' ), '' !== $result['refunds'] ? $result['refunds'] : __( 'not checked', 'stripe-mobilepay-woocommerce' ) ) );
		}
		wp_safe_redirect( $order instanceof WC_Order ? $order->get_edit_order_url() : admin_url() );
		exit;
	}

	/** Under the gateway's settings: the webhook for the current mode, and a button to create/renew it. */
	public static function webhook_panel(): void {
		$mode = SMPW_Plugin::mode();
		$hook = SMPW_Webhook_Store::get( $mode );
		/* translators: %s: "test mode" or "live mode" */
		echo '<h3>' . esc_html( sprintf( __( 'Webhook in Stripe (%s)', 'stripe-mobilepay-woocommerce' ), 'test' === $mode ? __( 'test mode', 'stripe-mobilepay-woocommerce' ) : __( 'live mode', 'stripe-mobilepay-woocommerce' ) ) ) . '</h3>';
		if ( '' === $hook['id'] ) {
			echo '<p><strong>' . esc_html__( 'No webhook yet.', 'stripe-mobilepay-woocommerce' ) . '</strong> ' . esc_html__( 'Without it, the orders depend on the customer coming back from MobilePay and on the scheduled checks.', 'stripe-mobilepay-woocommerce' ) . '</p>';
		} else {
			printf(
				'<p>%s → <code>%s</code><br>%s</p>',
				esc_html( $hook['id'] ),
				esc_html( $hook['url'] ),
				/* translators: 1: date and time the webhook was created, 2: date and time of the last event from Stripe, or "none yet" */
				esc_html( sprintf( __( 'Created %1$s · last event from Stripe: %2$s', 'stripe-mobilepay-woocommerce' ), wp_date( self::date_format(), (int) $hook['created'] ), $hook['last_event_at'] > 0 ? wp_date( self::date_format(), (int) $hook['last_event_at'] ) : __( 'none yet', 'stripe-mobilepay-woocommerce' ) ) )
			);
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=smpw_webhook' ), 'smpw_webhook' );
		printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $url ), '' === $hook['id'] ? esc_html__( 'Create webhook in Stripe', 'stripe-mobilepay-woocommerce' ) : esc_html__( 'Renew webhook in Stripe', 'stripe-mobilepay-woocommerce' ) );
	}

	public static function webhook_action(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'smpw_webhook' ) ) {
			wp_die( esc_html__( 'Access denied.', 'stripe-mobilepay-woocommerce' ) );
		}
		$result = SMPW_Webhook::create( SMPW_Plugin::mode() );
		/* translators: %s: the error */
		set_transient( 'smpw_admin_msg', is_wp_error( $result ) ? sprintf( __( 'The webhook could not be created: %s', 'stripe-mobilepay-woocommerce' ), $result->get_error_message() ) : __( 'The webhook has been created in Stripe.', 'stripe-mobilepay-woocommerce' ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . SMPW_Plugin::GATEWAY_ID ) );
		exit;
	}

	public static function notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$message = get_transient( 'smpw_admin_msg' );
		if ( is_string( $message ) && '' !== $message ) {
			delete_transient( 'smpw_admin_msg' );
			printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( ! str_starts_with( (string) $screen->id, 'woocommerce' ) && 'edit-shop_order' !== $screen->id ) ) {
			return;
		}
		$gateway = SMPW_Plugin::gateway();
		if ( ! $gateway || 'yes' !== $gateway->enabled ) {
			return;
		}
		$mode = SMPW_Plugin::mode();
		if ( ! SMPW_Plugin::stripe_ready( $mode ) ) {
			/* translators: 1: <strong>, 2: </strong> */
			printf( '<div class="notice notice-error"><p>%s</p></div>', sprintf( esc_html__( '%1$sMobilePay is switched on, but the Stripe plugin is not connected%2$s — customers cannot choose MobilePay.', 'stripe-mobilepay-woocommerce' ), '<strong>', '</strong>' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- the text is escaped; the tags are ours.
			return;
		}
		$hook = SMPW_Webhook_Store::get( $mode );
		if ( '' === $hook['id'] ) {
			/* translators: 1: <strong>, 2: </strong> */
			printf( '<div class="notice notice-warning"><p>%s</p></div>', sprintf( esc_html__( '%1$sMobilePay is missing its webhook in Stripe.%2$s Create it under WooCommerce → Settings → Payments → MobilePay.', 'stripe-mobilepay-woocommerce' ), '<strong>', '</strong>' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- the text is escaped; the tags are ours.
			return;
		}
		if ( time() - max( (int) $hook['created'], (int) $hook['last_event_at'] ) > 7 * DAY_IN_SECONDS && self::recent_orders() ) {
			/* translators: 1: <strong>, 2: </strong> */
			printf( '<div class="notice notice-warning"><p>%s</p></div>', sprintf( esc_html__( '%1$sStripe has not sent any MobilePay events for over 7 days%2$s, although there are new MobilePay orders. Check the webhook in the Stripe dashboard.', 'stripe-mobilepay-woocommerce' ), '<strong>', '</strong>' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- the text is escaped; the tags are ours.
		}
	}

	private static function recent_orders(): bool {
		return array() !== wc_get_orders(
			array(
				'payment_method' => SMPW_Plugin::GATEWAY_ID,
				'date_created'   => '>' . ( time() - 7 * DAY_IN_SECONDS ),
				'limit'          => 1,
				'return'         => 'ids',
			)
		);
	}

	/**
	 * A short e-mail to the shop: to the site's admin e-mail address, or wherever the filter smpw_notification_email
	 * sends it.
	 */
	public static function email( WC_Order $order, string $subject, string $body ): void {
		/**
		 * Who gets the plugin's e-mails to the shop (a failed capture, a hold that couldn't be released, a dispute, …).
		 *
		 * @param string   $to    Recipient(s), comma-separated. Default: the site's admin e-mail address. '' sends nothing.
		 * @param WC_Order $order The order the e-mail is about.
		 */
		$to = (string) apply_filters( 'smpw_notification_email', (string) get_option( 'admin_email' ), $order );
		if ( '' === $to ) {
			return;
		}
		/* translators: 1: the shop's name, 2: what happened, 3: order number */
		$subject = sprintf( __( '[%1$s] %2$s – order #%3$s', 'stripe-mobilepay-woocommerce' ), SMPW_Plugin::shop_name(), $subject, $order->get_order_number() );
		/* translators: %s: link to the order in the admin */
		wp_mail( $to, $subject, $body . "\n\n" . sprintf( __( 'Order: %s', 'stripe-mobilepay-woocommerce' ), $order->get_edit_order_url() ) . "\n" );
	}

	/** A short date and time for the meta box and the settings, in the site's language. */
	private static function date_format(): string {
		/* translators: a short date and time in PHP date() format, e.g. "Oct 6, 2026 21:45" */
		return __( 'M j, Y H:i', 'stripe-mobilepay-woocommerce' );
	}
}
