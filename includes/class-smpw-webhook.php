<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stripe → /?wc-api=smpw_webhook — this site's own endpoint, one per mode. Verified by its signature, then
 * routed to sync() / sync_refunds() / dispute(). The payload only routes; decisions always fetch the PaymentIntent
 * from Stripe. Answers: 200 handled/ignored, 400 bad signature/payload, 503 retry.
 */
final class SMPW_Webhook {

	public const EVENTS = array(
		'payment_intent.amount_capturable_updated',
		'payment_intent.succeeded',
		'payment_intent.payment_failed',
		'payment_intent.canceled',
		'payment_intent.requires_action',
		'payment_intent.processing',
		'charge.captured',
		'charge.refunded',
		'charge.refund.updated',
		'refund.created',
		'refund.updated',
		'refund.failed',
		'charge.dispute.created',
		'charge.dispute.closed',
	);

	public static function hooks(): void {
		add_action( 'woocommerce_api_smpw_webhook', array( __CLASS__, 'handle' ) );
	}

	public static function url(): string {
		return add_query_arg( 'wc-api', 'smpw_webhook', home_url( '/' ) );
	}

	public static function handle(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			self::respond( 405, 'method' );
		}
		$payload = (string) file_get_contents( 'php://input' );
		$event   = json_decode( $payload );
		if ( ! is_object( $event ) || empty( $event->id ) || empty( $event->type ) ) {
			self::respond( 400, 'payload' );
		}
		$mode   = empty( $event->livemode ) ? 'test' : 'live';
		$header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? (string) wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- verified as a signature below.
		if ( ! SMPW_Signature::verify( $payload, $header, SMPW_Webhook_Store::secret( $mode ), time() ) ) {
			SMPW_Log::warning( 'webhook: bad signature', array( 'event' => (string) $event->id, 'mode' => $mode ) );
			self::respond( 400, 'signature' );
		}
		SMPW_Webhook_Store::touch( $mode );
		$result = self::process( $event, $mode );
		SMPW_Log::info( 'webhook', array( 'event' => (string) $event->id, 'type' => (string) $event->type, 'result' => $result ) );
		self::respond( 'retry' === $result ? 503 : 200, $result );
	}

	private static function respond( int $code, string $result ): void {
		status_header( $code );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( array( 'result' => $result ) );
		exit;
	}

	/** @return string handled | ignored | duplicate | retry */
	public static function process( object $event, string $mode ): string {
		[ $kind, $intent_id ] = self::route( $event );
		if ( 'ignore' === $kind || '' === $intent_id ) {
			return 'ignored';
		}
		if ( 'intent' === $kind && '1' !== (string) ( $event->data->object->metadata->smpw ?? '' ) ) {
			return 'ignored'; // A card payment, etc.
		}
		$order = SMPW_Order_Data::find_order( $intent_id );
		if ( null === $order ) {
			return 'ignored'; // Not made here (another site on the same Stripe account, or not MobilePay).
		}
		$seen = 'smpw_evt_' . md5( (string) $event->id );
		if ( get_transient( $seen ) ) {
			return 'duplicate';
		}
		if ( ! SMPW_Payments::is_ours( $order ) ) {
			$result = SMPW_Payments::close_orphans( $order, 'webhook' );
		} elseif ( 'dispute' === $kind ) {
			$result = SMPW_Payments::dispute( $order, (string) ( $event->data->object->id ?? '' ), $mode );
		} else {
			$result = SMPW_Payments::sync( $order, 'webhook:' . $event->type, $intent_id );
			if ( 'refunds' === $kind && ! in_array( $result, array( 'locked', 'error' ), true ) ) {
				$result = SMPW_Payments::sync_refunds( wc_get_order( $order->get_id() ) );
			}
		}
		if ( in_array( $result, array( 'locked', 'error' ), true ) ) {
			return 'retry';
		}
		set_transient( $seen, 1, DAY_IN_SECONDS );
		return 'handled';
	}

	/** Pure: [kind, PaymentIntent id]; kind = intent | refunds | dispute | ignore. */
	public static function route( object $event ): array {
		$type   = (string) ( $event->type ?? '' );
		$object = $event->data->object ?? null;
		if ( ! is_object( $object ) ) {
			return array( 'ignore', '' );
		}
		if ( str_starts_with( $type, 'payment_intent.' ) ) {
			return array( 'intent', (string) ( $object->id ?? '' ) );
		}
		if ( 'charge.captured' === $type || 'charge.refunded' === $type || str_starts_with( $type, 'charge.refund.' ) || str_starts_with( $type, 'refund.' ) ) {
			return array( 'refunds', (string) ( $object->payment_intent ?? '' ) );
		}
		if ( str_starts_with( $type, 'charge.dispute.' ) ) {
			return array( 'dispute', (string) ( $object->payment_intent ?? '' ) );
		}
		return array( 'ignore', '' );
	}

	/**
	 * Create this site's endpoint at Stripe for $mode, keep its secret (encrypted), remove the previous one.
	 * This changes the Stripe account's webhook endpoints.
	 *
	 * @return true|WP_Error
	 */
	public static function create( string $mode ) {
		$old      = SMPW_Webhook_Store::get( $mode );
		$endpoint = SMPW_Payments::client()->create_webhook_endpoint(
			array(
				'url'            => self::url(),
				'enabled_events' => self::EVENTS,
				'api_version'    => SMPW_Stripe::api_version(),
				'description'    => 'Stripe MobilePay for WooCommerce (' . SMPW_Plugin::site() . ')',
				'metadata'       => array(
					'smpw'      => '1',
					'smpw_site' => SMPW_Plugin::site(),
				),
			),
			'smpw-webhook-' . wp_generate_uuid4(),
			$mode
		);
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}
		if ( empty( $endpoint->id ) || empty( $endpoint->secret ) ) {
			return new WP_Error( 'smpw_webhook', __( 'Stripe answered without a webhook ID or signing secret.', 'stripe-mobilepay-woocommerce' ) );
		}
		SMPW_Webhook_Store::save( $mode, (string) $endpoint->id, (string) ( $endpoint->url ?? self::url() ), (string) $endpoint->secret );
		if ( '' !== $old['id'] && $old['id'] !== (string) $endpoint->id ) {
			SMPW_Payments::client()->delete_webhook_endpoint( $old['id'], $mode );
		}
		SMPW_Log::info( 'webhook created', array( 'mode' => $mode, 'id' => (string) $endpoint->id ) );
		return true;
	}

	/** @return true|WP_Error */
	public static function delete( string $mode ) {
		$old = SMPW_Webhook_Store::get( $mode );
		if ( '' === $old['id'] ) {
			return true;
		}
		$result = SMPW_Payments::client()->delete_webhook_endpoint( $old['id'], $mode );
		if ( is_wp_error( $result ) && 404 !== (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
			return $result;
		}
		SMPW_Webhook_Store::forget( $mode );
		return true;
	}
}
