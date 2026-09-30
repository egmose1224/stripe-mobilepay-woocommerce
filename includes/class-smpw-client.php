<?php
defined( 'ABSPATH' ) || exit;

/** What the plugin needs from Stripe. Every method returns the decoded Stripe object or a WP_Error. */
interface SMPW_Client {

	public function create_intent( array $params, string $idempotency_key, string $mode );

	public function retrieve_intent( string $id, string $mode );

	public function capture_intent( string $id, int $amount, string $idempotency_key, string $mode );

	public function cancel_intent( string $id, string $reason, string $idempotency_key, string $mode );

	public function create_refund( array $params, string $idempotency_key, string $mode );

	public function list_refunds( string $intent_id, string $mode );

	public function retrieve_dispute( string $id, string $mode );

	public function create_webhook_endpoint( array $params, string $idempotency_key, string $mode );

	public function delete_webhook_endpoint( string $id, string $mode );
}
