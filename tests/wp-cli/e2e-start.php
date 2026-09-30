<?php
/**
 * End-to-end helper — real Stripe, test mode: creates an order and starts MobilePay, then prints the order id and
 * Stripe's test page ("Authorize test payment" / "Fail test payment"). Run it on a staging site in Stripe test mode,
 * from the plugin's folder:
 *   wp eval-file tests/wp-cli/e2e-start.php [product id]
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SMPW_Plugin' ) || 'test' !== SMPW_Plugin::mode() ) {
	WP_CLI::error( 'Only with Stripe MobilePay for WooCommerce active and the Stripe plugin in test mode.' );
}
$product = ! empty( $args[0] ) ? wc_get_product( (int) $args[0] ) : ( wc_get_products( array( 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'limit' => 1, 'orderby' => 'price', 'order' => 'ASC' ) )[0] ?? null );
if ( ! $product instanceof WC_Product ) {
	WP_CLI::error( 'No product.' );
}
$order = wc_create_order( array( 'created_via' => 'store-api' ) );
$order->add_product( $product, 1 );
$order->set_address( array( 'first_name' => 'E2E', 'last_name' => 'MobilePay', 'email' => 'mobilepay-e2e@example.invalid', 'phone' => '+4512345678', 'address_1' => 'Test Street 1', 'postcode' => '1000', 'city' => 'Copenhagen', 'country' => 'DK' ), 'billing' );
$order->set_payment_method( 'smpw_mobilepay' );
$order->calculate_totals();
$order->set_status( 'pending' );
$order->save();
$url = SMPW_Payments::start( wc_get_order( $order->get_id() ) );
WP_CLI::log( 'order: ' . $order->get_id() );
WP_CLI::log( 'url:   ' . $url );
