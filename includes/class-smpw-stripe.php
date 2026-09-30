<?php
defined( 'ABSPATH' ) || exit;

/**
 * The only code that talks to Stripe. The secret key comes from the Stripe plugin's settings for the
 * requested mode at call time — never stored or logged here. Every POST that creates or moves money
 * carries our own idempotency key, so a retry (network error, 429, 5xx) can never do it twice.
 */
final class SMPW_Stripe implements SMPW_Client {

	public const API              = 'https://api.stripe.com/v1/';
	public const FALLBACK_VERSION = '2026-03-25.dahlia';
	private const RETRIES         = 2;

	/** @var callable(string, string, array): (array{code: int, body: string, request_id: string}|WP_Error) */
	private $transport;

	/** @var callable(string): string */
	private $keys;

	/** @var callable(float): void */
	private $sleep;

	public function __construct( ?callable $transport = null, ?callable $keys = null, ?callable $sleep = null ) {
		$this->transport = $transport ?? array( self::class, 'wp_transport' );
		$this->keys      = $keys ?? array( self::class, 'stripe_plugin_key' );
		$this->sleep     = $sleep ?? static function ( float $seconds ): void {
			usleep( (int) ( $seconds * 1000000 ) );
		};
	}

	public function create_intent( array $params, string $idempotency_key, string $mode ) {
		return $this->request( 'POST', 'payment_intents', $params, $mode, $idempotency_key );
	}

	public function retrieve_intent( string $id, string $mode ) {
		return $this->request( 'GET', 'payment_intents/' . rawurlencode( $id ), array( 'expand' => array( 'latest_charge' ) ), $mode );
	}

	public function capture_intent( string $id, int $amount, string $idempotency_key, string $mode ) {
		return $this->request( 'POST', 'payment_intents/' . rawurlencode( $id ) . '/capture', array( 'amount_to_capture' => $amount ), $mode, $idempotency_key );
	}

	public function cancel_intent( string $id, string $reason, string $idempotency_key, string $mode ) {
		return $this->request( 'POST', 'payment_intents/' . rawurlencode( $id ) . '/cancel', array( 'cancellation_reason' => $reason ), $mode, $idempotency_key );
	}

	public function create_refund( array $params, string $idempotency_key, string $mode ) {
		return $this->request( 'POST', 'refunds', $params, $mode, $idempotency_key );
	}

	public function list_refunds( string $intent_id, string $mode ) {
		return $this->request( 'GET', 'refunds', array( 'payment_intent' => $intent_id, 'limit' => 100 ), $mode );
	}

	public function retrieve_dispute( string $id, string $mode ) {
		return $this->request( 'GET', 'disputes/' . rawurlencode( $id ), array(), $mode );
	}

	public function create_webhook_endpoint( array $params, string $idempotency_key, string $mode ) {
		return $this->request( 'POST', 'webhook_endpoints', $params, $mode, $idempotency_key );
	}

	public function delete_webhook_endpoint( string $id, string $mode ) {
		return $this->request( 'DELETE', 'webhook_endpoints/' . rawurlencode( $id ), array(), $mode );
	}

	/** @return object|WP_Error */
	public function request( string $method, string $path, array $params, string $mode, string $idempotency_key = '' ) {
		$secret = (string) ( $this->keys )( $mode );
		if ( '' === $secret ) {
			/* translators: %s: "test" or "live" */
			return new WP_Error( 'smpw_not_connected', sprintf( __( 'Stripe is not connected in %s mode.', 'stripe-mobilepay-woocommerce' ), 'test' === $mode ? 'test' : 'live' ), array( 'retryable' => false ) );
		}
		$query   = self::encode( $params );
		$url     = self::API . $path . ( 'POST' !== $method && '' !== $query ? '?' . $query : '' );
		$headers = array(
			'Authorization'  => 'Bearer ' . $secret,
			'Stripe-Version' => self::api_version(),
			'User-Agent'     => 'stripe-mobilepay-woocommerce/' . ( defined( 'SMPW_VERSION' ) ? SMPW_VERSION : 'dev' ),
		);
		if ( 'POST' === $method ) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			if ( '' !== $idempotency_key ) {
				$headers['Idempotency-Key'] = $idempotency_key;
			}
		}
		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'body'    => 'POST' === $method ? $query : null,
			'timeout' => 30,
		);

		for ( $try = 0; ; $try++ ) {
			$result = self::parse( ( $this->transport )( $method, $url, $args ) );
			if ( ! is_wp_error( $result ) || $try >= self::RETRIES || ! self::retryable( $result ) ) {
				break;
			}
			( $this->sleep )( 0 === $try ? 0.5 : 1.5 );
		}

		SMPW_Log::info(
			'stripe',
			array(
				'call'            => $method . ' ' . $path,
				'result'          => is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : (string) ( $result->status ?? $result->id ?? 'ok' ),
				'idempotency_key' => $idempotency_key,
				'request_id'      => is_wp_error( $result ) ? (string) ( $result->get_error_data()['request_id'] ?? '' ) : '',
			)
		);
		return $result;
	}

	/**
	 * @param array{code: int, body: string, request_id?: string}|WP_Error $response
	 * @return object|WP_Error
	 */
	public static function parse( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'smpw_network', $response->get_error_message(), array( 'retryable' => true, 'request_id' => '' ) );
		}
		$code = (int) ( $response['code'] ?? 0 );
		$data = json_decode( (string) ( $response['body'] ?? '' ) );
		if ( $code >= 200 && $code < 300 && is_object( $data ) ) {
			return $data;
		}
		$error = is_object( $data ) && isset( $data->error ) ? $data->error : null;
		return new WP_Error(
			'smpw_stripe',
			/* translators: %d: HTTP status code */
			(string) ( $error->message ?? sprintf( __( 'Stripe returned HTTP %d', 'stripe-mobilepay-woocommerce' ), $code ) ),
			array(
				'status'       => $code,
				'type'         => (string) ( $error->type ?? '' ),
				'code'         => (string) ( $error->code ?? '' ),
				'decline_code' => (string) ( $error->decline_code ?? '' ),
				'request_id'   => (string) ( $response['request_id'] ?? '' ),
				// 409: the key is in use by the same request still running (idempotency_key_in_use) — asked again, Stripe
				// answers what that one got. Stripe's own libraries retry it too.
				'retryable'    => 0 === $code || 409 === $code || 429 === $code || $code >= 500,
			)
		);
	}

	public static function retryable( WP_Error $error ): bool {
		$data = $error->get_error_data();
		return is_array( $data ) && ! empty( $data['retryable'] );
	}

	/** Stripe's form encoding: nested arrays in brackets, booleans as "true"/"false", nulls left out. */
	public static function encode( array $params ): string {
		return http_build_query( self::normalize( $params ), '', '&' );
	}

	private static function normalize( array $params ): array {
		foreach ( $params as $key => $value ) {
			if ( null === $value ) {
				unset( $params[ $key ] );
			} elseif ( is_bool( $value ) ) {
				$params[ $key ] = $value ? 'true' : 'false';
			} elseif ( is_array( $value ) ) {
				$params[ $key ] = self::normalize( $value );
			}
		}
		return $params;
	}

	/** The API version the Stripe plugin pins (webhook payloads then have the same shape). */
	public static function api_version(): string {
		return class_exists( 'WC_Stripe_API' ) && defined( 'WC_Stripe_API::STRIPE_API_VERSION' ) ? (string) constant( 'WC_Stripe_API::STRIPE_API_VERSION' ) : self::FALLBACK_VERSION;
	}

	/** The Stripe plugin's secret key for $mode ("Connect with Stripe" stored it in its settings). */
	public static function stripe_plugin_key( string $mode ): string {
		if ( ! class_exists( 'WC_Stripe_Helper' ) ) {
			return '';
		}
		$settings = WC_Stripe_Helper::get_stripe_settings();
		return trim( (string) ( 'test' === $mode ? ( $settings['test_secret_key'] ?? '' ) : ( $settings['secret_key'] ?? '' ) ) );
	}

	/** @return array{code: int, body: string, request_id: string}|WP_Error */
	public static function wp_transport( string $method, string $url, array $args ) {
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'code'       => (int) wp_remote_retrieve_response_code( $response ),
			'body'       => (string) wp_remote_retrieve_body( $response ),
			'request_id' => (string) wp_remote_retrieve_header( $response, 'request-id' ),
		);
	}
}
