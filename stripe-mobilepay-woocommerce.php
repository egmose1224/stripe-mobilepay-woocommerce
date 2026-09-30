<?php
/**
 * Plugin Name:          Stripe MobilePay for WooCommerce
 * Plugin URI:           https://github.com/egmose1224/stripe-mobilepay-woocommerce
 * Description:          Unofficial MobilePay payment method for WooCommerce on your existing Stripe account (through the official WooCommerce Stripe Payment Gateway): reserved at checkout, captured when the order is completed. Requires the WooCommerce Stripe Payment Gateway plugin by WooCommerce (https://wordpress.org/plugins/woocommerce-gateway-stripe/), connected to your Stripe account: its API keys are used.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce, woocommerce-gateway-stripe
 * Author:               egmose1224
 * Author URI:           https://github.com/egmose1224
 * License:              0BSD
 * License URI:          https://opensource.org/license/0bsd
 * Text Domain:          stripe-mobilepay-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.1
 *
 * Why: the official WooCommerce Stripe plugin has no MobilePay, and it leaves out payment methods it doesn't know.
 * This plugin creates MobilePay PaymentIntents itself (manual capture), sends the customer to MobilePay, and keeps
 * the order in step with Stripe through three paths into one state machine: the customer's return, its own signed
 * webhook, and scheduled reconciliation. It has no API keys of its own: each call to Stripe reads the secret key for
 * the current mode from the official plugin's settings, which is why that plugin must be installed and connected.
 */

defined( 'ABSPATH' ) || exit;

const SMPW_VERSION = '1.0.0';
const SMPW_FILE    = __FILE__;
const SMPW_DIR     = __DIR__;

// SMPW_Foo_Bar → includes/class-smpw-foo-bar.php.
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'SMPW_' ) ) {
			return;
		}
		$file = SMPW_DIR . '/includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SMPW_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SMPW_FILE, true );
		}
	}
);

add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'stripe-mobilepay-woocommerce', false, dirname( plugin_basename( SMPW_FILE ) ) . '/languages' );
	},
	0
);

add_action( 'plugins_loaded', array( 'SMPW_Plugin', 'boot' ), 20 );
register_activation_hook( __FILE__, array( 'SMPW_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SMPW_Plugin', 'deactivate' ) );

/**
 * Whether MobilePay is switched on and can take payments right now — for themes, e.g. to show a MobilePay logo in the
 * footer only then.
 */
function smpw_offered(): bool {
	return class_exists( 'SMPW_Plugin' ) && SMPW_Plugin::offered();
}
