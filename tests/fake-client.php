<?php
/**
 * A Stripe that lives in memory — for the scenarios and the WP-CLI integration test. It behaves like Stripe for
 * MobilePay: a new PaymentIntent waits for the customer (requires_action); the test then approves, declines or
 * expires it.
 */

defined( 'ABSPATH' ) || exit;

final class SMPW_Test_Client implements SMPW_Client {

	public array $intents              = array();
	public array $refunds              = array();
	public array $captures             = array();
	public ?WP_Error $next_capture_error = null;
	private int $seq                   = 0;

	private function id( string $prefix ): string {
		return $prefix . '_fake' . ( ++$this->seq ) . bin2hex( random_bytes( 4 ) );
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'smpw_stripe', 'No such object', array( 'status' => 404, 'retryable' => false ) );
	}

	public function create_intent( array $params, string $idempotency_key, string $mode ) {
		$id                   = $this->id( 'pi' );
		$this->intents[ $id ] = (object) array(
			'id'                => $id,
			'status'            => 'requires_action',
			'amount'            => (int) $params['amount'],
			'currency'          => (string) $params['currency'],
			'amount_capturable' => 0,
			'amount_received'   => 0,
			'metadata'          => (object) $params['metadata'],
			'next_action'       => (object) array(
				'type'            => 'redirect_to_url',
				'redirect_to_url' => (object) array( 'url' => 'https://pm-redirects.stripe.test/authorize/' . $id ),
			),
		);
		return clone $this->intents[ $id ];
	}

	public function retrieve_intent( string $id, string $mode ) {
		return isset( $this->intents[ $id ] ) ? clone $this->intents[ $id ] : self::not_found();
	}

	public function capture_intent( string $id, int $amount, string $idempotency_key, string $mode ) {
		if ( null !== $this->next_capture_error ) {
			$error                    = $this->next_capture_error;
			$this->next_capture_error = null;
			return $error;
		}
		$intent = $this->intents[ $id ] ?? null;
		if ( ! $intent || 'requires_capture' !== $intent->status ) {
			return new WP_Error( 'smpw_stripe', 'This PaymentIntent could not be captured', array( 'status' => 400, 'retryable' => false ) );
		}
		$intent->status            = 'succeeded';
		$intent->amount_received   = $amount;
		$intent->amount_capturable = 0;
		$this->captures[ $id ]     = $amount;
		return clone $intent;
	}

	public function cancel_intent( string $id, string $reason, string $idempotency_key, string $mode ) {
		$intent = $this->intents[ $id ] ?? null;
		if ( ! $intent || in_array( $intent->status, array( 'succeeded', 'canceled' ), true ) ) {
			return new WP_Error( 'smpw_stripe', 'You cannot cancel this PaymentIntent', array( 'status' => 400, 'retryable' => false ) );
		}
		$intent->status            = 'canceled';
		$intent->amount_capturable = 0;
		return clone $intent;
	}

	public function create_refund( array $params, string $idempotency_key, string $mode ) {
		$id                   = $this->id( 're' );
		$this->refunds[ $id ] = (object) array(
			'id'             => $id,
			'payment_intent' => (string) $params['payment_intent'],
			'amount'         => (int) $params['amount'],
			'status'         => 'succeeded',
			'metadata'       => (object) ( $params['metadata'] ?? array() ),
		);
		return clone $this->refunds[ $id ];
	}

	public function list_refunds( string $intent_id, string $mode ) {
		return (object) array( 'data' => array_values( array_filter( $this->refunds, static fn( $r ) => $r->payment_intent === $intent_id ) ) );
	}

	public function retrieve_dispute( string $id, string $mode ) {
		return (object) array( 'id' => $id, 'status' => 'needs_response', 'amount' => 1000, 'reason' => 'fraudulent' );
	}

	public function create_webhook_endpoint( array $params, string $idempotency_key, string $mode ) {
		return (object) array( 'id' => 'we_fake', 'url' => (string) $params['url'], 'secret' => 'fake-signing-secret' );
	}

	public function delete_webhook_endpoint( string $id, string $mode ) {
		return (object) array( 'id' => $id, 'deleted' => true );
	}

	// What the customer (or Stripe) does.

	public function approve( string $id ): void {
		$this->intents[ $id ]->status            = 'requires_capture';
		$this->intents[ $id ]->amount_capturable = $this->intents[ $id ]->amount;
	}

	public function decline( string $id ): void {
		$this->intents[ $id ]->status = 'requires_payment_method';
	}

	public function expire( string $id ): void {
		$this->intents[ $id ]->status            = 'canceled';
		$this->intents[ $id ]->amount_capturable = 0;
	}

	public function foreign_refund( string $id, int $amount ): string {
		$rid                   = $this->id( 're' );
		$this->refunds[ $rid ] = (object) array( 'id' => $rid, 'payment_intent' => $id, 'amount' => $amount, 'status' => 'succeeded', 'metadata' => (object) array() );
		return $rid;
	}
}
