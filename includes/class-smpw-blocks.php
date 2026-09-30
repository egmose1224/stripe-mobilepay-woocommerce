<?php
defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/** MobilePay in the checkout block: the logo (or the title, without one), the description, the order button's text. */
final class SMPW_Blocks extends AbstractPaymentMethodType {

	protected $name = 'smpw_mobilepay';

	public static function register( $registry ): void {
		$registry->register( new self() );
	}

	public function initialize() {
		$this->settings = (array) get_option( 'woocommerce_smpw_mobilepay_settings', array() );
	}

	public function is_active() {
		return 'yes' === ( $this->settings['enabled'] ?? 'no' );
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'smpw-checkout',
			plugins_url( 'assets/js/checkout.js', SMPW_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			SMPW_VERSION,
			true
		);
		wp_enqueue_style( 'smpw-checkout', plugins_url( 'assets/css/checkout.css', SMPW_FILE ), array(), SMPW_VERSION );
		return array( 'smpw-checkout' );
	}

	public function get_payment_method_data() {
		$gateway = SMPW_Plugin::gateway();
		return array(
			'title'       => $gateway ? $gateway->get_title() : 'MobilePay',
			'description' => $gateway ? $gateway->get_description() : SMPW_Gateway::default_description(),
			'logo'        => SMPW_Plugin::logo_url(), // '' → the label shows the title as text.
			'button'      => __( 'Buy now with MobilePay', 'stripe-mobilepay-woocommerce' ),
			'countries'   => array( 'DK', 'FI' ),
			'supports'    => array( 'products' ),
		);
	}
}
