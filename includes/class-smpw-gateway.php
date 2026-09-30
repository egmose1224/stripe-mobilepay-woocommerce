<?php
defined( 'ABSPATH' ) || exit;

/** MobilePay as a WooCommerce payment method. The work is done in SMPW_Payments. */
final class SMPW_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = SMPW_Plugin::GATEWAY_ID;
		$this->method_title       = 'MobilePay (Stripe)';
		$this->method_description = __( 'MobilePay through the shop\'s Stripe account. The amount is reserved when the order is placed and captured when the order is marked Completed. Uses the Stripe plugin\'s connection and mode (test/live).', 'stripe-mobilepay-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );
		$this->icon               = SMPW_Plugin::logo_url(); // '' without the logo: WooCommerce then shows no icon.
		$this->order_button_text  = __( 'Buy now with MobilePay', 'stripe-mobilepay-woocommerce' ); // The classic checkout and the order-pay page (the block: placeOrderButtonLabel).
		$this->init_form_fields();
		$this->init_settings();
		$this->title       = (string) $this->get_option( 'title', 'MobilePay' );
		$this->description = (string) $this->get_option( 'description', self::default_description() );
		$this->enabled     = (string) $this->get_option( 'enabled', 'no' );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/** The text under MobilePay at checkout, until the shop writes its own. */
	public static function default_description(): string {
		return __( 'You will be sent to MobilePay to approve the payment in the app. The amount is reserved now and only charged when your order is shipped.', 'stripe-mobilepay-woocommerce' );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable', 'stripe-mobilepay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer MobilePay at checkout', 'stripe-mobilepay-woocommerce' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'   => __( 'Title at checkout', 'stripe-mobilepay-woocommerce' ),
				'type'    => 'text',
				'default' => 'MobilePay',
			),
			'description' => array(
				'title'   => __( 'Description at checkout', 'stripe-mobilepay-woocommerce' ),
				'type'    => 'textarea',
				'default' => self::default_description(),
			),
		);
	}

	public function is_available(): bool {
		if ( ! parent::is_available() || ! SMPW_Plugin::stripe_ready() || 'DKK' !== get_woocommerce_currency() ) {
			return false;
		}
		$country = function_exists( 'WC' ) && WC()->customer ? (string) WC()->customer->get_billing_country() : '';
		if ( '' !== $country && ! in_array( $country, array( 'DK', 'FI' ), true ) ) {
			return false; // MobilePay's customer countries (Stripe).
		}
		if ( function_exists( 'WC' ) && WC()->cart && ! is_admin() ) {
			$total = (float) WC()->cart->get_total( 'edit' );
			if ( $total > 0 && $total < 2.5 ) {
				return false; // Below Stripe's DKK minimum.
			}
		}
		return true;
	}

	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		// WooCommerce's order-pay form (a Failed order paid again, a payment link): a failed attempt returns there.
		$pay_page = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' );
		try {
			return array(
				'result'   => 'success',
				'redirect' => SMPW_Payments::start( $order, $pay_page ),
			);
		} catch ( SMPW_Exception $e ) {
			if ( ! WC()->is_rest_api_request() ) {
				wc_add_notice( $e->getMessage(), 'error' ); // Classic checkout; the block checkout shows 'message'.
			}
			return array(
				'result'  => 'failure',
				'message' => $e->getMessage(),
			);
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || null === $amount ) {
			return new WP_Error( 'smpw_refund', __( 'Enter an amount to refund.', 'stripe-mobilepay-woocommerce' ) );
		}
		return SMPW_Payments::refund( $order, (float) $amount, (string) $reason );
	}

	public function admin_options(): void {
		parent::admin_options();
		SMPW_Admin::webhook_panel();
	}
}
