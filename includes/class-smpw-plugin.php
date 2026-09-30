<?php
defined( 'ABSPATH' ) || exit;

/** Wiring and environment facts: mode, site, Stripe connection, the gateway, its place in the checkout. */
final class SMPW_Plugin {

	public const GATEWAY_ID = 'smpw_mobilepay';

	/** Where a shop may put the official MobilePay logo. It is a trademark, so the plugin doesn't ship it. */
	private const LOGO = 'assets/img/mobilepay.svg';

	public static function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ): array {
				$gateways[] = 'SMPW_Gateway';
				return $gateways;
			}
		);
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( 'SMPW_Blocks', 'register' ) );
		SMPW_Payments::hooks();
		SMPW_Return::hooks();
		SMPW_Webhook::hooks();
		SMPW_Reconcile::hooks();
		SMPW_Admin::hooks();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'smpw', 'SMPW_CLI' );
		}
	}

	public static function activate(): void {
		self::put_first();
		SMPW_Reconcile::schedule();
	}

	public static function deactivate(): void {
		SMPW_Reconcile::unschedule();
	}

	/** MobilePay first in WooCommerce's payment order → the checkout block pre-selects it. */
	public static function put_first(): void {
		$order = get_option( 'woocommerce_gateway_order', array() );
		$order = is_array( $order ) ? $order : array();
		unset( $order[ self::GATEWAY_ID ] );
		$lowest = array() === $order ? 0 : (int) min( array_map( 'intval', $order ) );
		update_option( 'woocommerce_gateway_order', array( self::GATEWAY_ID => min( 0, $lowest ) - 1 ) + $order );
	}

	/** The Stripe plugin's mode: MobilePay follows it (e.g. test mode on a staging site, live mode in the shop). */
	public static function mode(): string {
		return class_exists( 'WC_Stripe_Mode' ) && WC_Stripe_Mode::is_test() ? 'test' : 'live';
	}

	public static function stripe_ready( string $mode = '' ): bool {
		return class_exists( 'WC_Stripe_Helper' ) && WC_Stripe_Helper::is_connected( '' === $mode ? self::mode() : $mode );
	}

	/** This install, as written into PaymentIntent metadata — a staging site and a local copy may share Stripe's test mode. */
	public static function site(): string {
		$parts = wp_parse_url( home_url() );
		return strtolower( (string) ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
	}

	/** The shop's name as plain text (WordPress stores it with HTML entities). */
	public static function shop_name(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/** The MobilePay logo's URL when the shop has added it (see the README), else '' — then the title is shown instead. */
	public static function logo_url(): string {
		return is_file( SMPW_DIR . '/' . self::LOGO ) ? plugins_url( self::LOGO, SMPW_FILE ) : '';
	}

	public static function gateway(): ?SMPW_Gateway {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateway = WC()->payment_gateways()->payment_gateways()[ self::GATEWAY_ID ] ?? null;
		return $gateway instanceof SMPW_Gateway ? $gateway : null;
	}

	/** Switched on and able to take payments (smpw_offered()). */
	public static function offered(): bool {
		$gateway = self::gateway();
		return null !== $gateway && 'yes' === $gateway->enabled && self::stripe_ready();
	}
}
