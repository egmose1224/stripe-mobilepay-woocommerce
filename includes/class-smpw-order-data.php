<?php
defined( 'ABSPATH' ) || exit;

/** The plugin's data on one order (meta), in one place. Callers save() after changing it. */
final class SMPW_Order_Data {

	public const ATTEMPTS        = '_smpw_intents';
	public const INTENT_ID       = '_smpw_intent_id';
	public const CURRENT         = '_smpw_intent';
	public const PAYING          = '_smpw_paid_intent';
	public const STATUS          = '_smpw_status';
	public const SYNCED          = '_smpw_synced_at';
	public const AUTH_AMOUNT     = '_smpw_authorized_amount';
	public const AUTH_AT         = '_smpw_authorized_at';
	public const CAPTURE_BEFORE  = '_smpw_capture_before';
	public const CAPTURED        = '_smpw_captured';
	public const CAPTURED_AMOUNT = '_smpw_captured_amount';
	public const PRECAPTURE      = '_smpw_precapture_refunded';
	public const SESSION         = '_smpw_session';
	public const FLAGS           = '_smpw_flags';
	public const REFUND_ID       = '_smpw_refund_id';
	public const HOLD_DAYS       = 7;
	// The capture: what it was worked out from (set_capture_basis()) and its tries (next_capture_try()).
	public const CAPTURED_INTENT     = '_smpw_captured_intent';
	public const REFUNDED_AT_CAPTURE = '_smpw_refunded_at_capture';
	public const CAPTURE_REFUNDS     = '_smpw_capture_refunds';
	public const CAPTURE_TRIES       = '_smpw_capture_tries';
	/** The releases/closes tried on the order's attempts (next_cancel_try()), and the refunds of duplicates (next_dupe_try()). */
	public const CANCEL_TRIES = '_smpw_cancel_tries';
	public const DUPE_TRIES   = '_smpw_dupe_tries';
	/** CAPTURED's value for a hold released at "Completed" because nothing was due (never 'yes': nothing was paid). */
	public const RELEASED = 'released';

	private WC_Order $order;

	public function __construct( WC_Order $order ) {
		$this->order = $order;
	}

	public function order(): WC_Order {
		return $this->order;
	}

	/** @return array<int, array{id: string, attempt: int, amount: int, currency: string, mode: string, created: int}> */
	public function attempts(): array {
		$list = json_decode( (string) $this->order->get_meta( self::ATTEMPTS ), true );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	public function attempt( string $intent_id ): ?array {
		foreach ( $this->attempts() as $attempt ) {
			if ( ( $attempt['id'] ?? '' ) === $intent_id ) {
				return $attempt;
			}
		}
		return null;
	}

	public function next_attempt(): int {
		return count( $this->attempts() ) + 1;
	}

	public function add_attempt( string $intent_id, int $attempt, int $amount, string $mode ): void {
		$list   = $this->attempts();
		$list[] = array( 'id' => $intent_id, 'attempt' => $attempt, 'amount' => $amount, 'currency' => 'dkk', 'mode' => $mode, 'created' => time() );
		$this->order->update_meta_data( self::ATTEMPTS, wp_json_encode( $list ) );
		$this->order->add_meta_data( self::INTENT_ID, $intent_id );
		$this->order->update_meta_data( self::CURRENT, $intent_id );
	}

	public function current(): string {
		return (string) $this->order->get_meta( self::CURRENT );
	}

	public function paying(): string {
		return (string) $this->order->get_meta( self::PAYING );
	}

	public function set_paying( string $intent_id ): void {
		$this->order->update_meta_data( self::PAYING, $intent_id );
		$this->order->update_meta_data( self::CURRENT, $intent_id );
	}

	/** 'paying' (it authorized the order), 'current' (newest attempt of an unpaid order) or 'other'. */
	public function role( string $intent_id, bool $unpaid ): string {
		$paying = $this->paying();
		if ( '' !== $paying ) {
			return $paying === $intent_id ? 'paying' : 'other';
		}
		if ( $intent_id !== $this->current() ) {
			return 'other';
		}
		return $unpaid ? 'current' : 'paying';
	}

	public function status(): string {
		return (string) $this->order->get_meta( self::STATUS );
	}

	public function set_status( string $status ): void {
		$this->order->update_meta_data( self::STATUS, $status );
		$this->order->update_meta_data( self::SYNCED, (string) time() );
	}

	public function authorized(): bool {
		return '' !== (string) $this->order->get_meta( self::AUTH_AT );
	}

	public function mark_authorized( int $amount, int $at ): void {
		$this->order->update_meta_data( self::AUTH_AMOUNT, (string) $amount );
		$this->order->update_meta_data( self::AUTH_AT, (string) $at );
		$this->order->update_meta_data( self::CAPTURE_BEFORE, (string) ( $at + self::HOLD_DAYS * DAY_IN_SECONDS ) );
		$this->order->update_meta_data( self::CAPTURED, 'no' );
	}

	/** The hold is gone and the order is paid again: forget that authorization. Attempts and flags stay. */
	public function clear_authorization(): void {
		foreach ( array( self::PAYING, self::AUTH_AMOUNT, self::AUTH_AT, self::CAPTURE_BEFORE, self::CAPTURED, self::CAPTURED_AMOUNT, self::PRECAPTURE ) as $key ) {
			$this->order->delete_meta_data( $key );
		}
	}

	public function authorized_amount(): int {
		return (int) $this->order->get_meta( self::AUTH_AMOUNT );
	}

	public function capture_before(): int {
		return (int) $this->order->get_meta( self::CAPTURE_BEFORE );
	}

	/**
	 * The payment is settled: captured, or released at "Completed" with nothing due (released(); then
	 * captured_amount() is 0). Either way the state table treats the hold as dealt with.
	 */
	public function captured(): bool {
		return in_array( $this->order->get_meta( self::CAPTURED ), array( 'yes', self::RELEASED ), true );
	}

	public function mark_captured( int $amount ): void {
		$this->order->update_meta_data( self::CAPTURED, 'yes' );
		$this->order->update_meta_data( self::CAPTURED_AMOUNT, (string) $amount );
	}

	/**
	 * Settled without a capture: at "Completed" nothing was due (all refunded), so the hold was released. Never 'yes'
	 * in CAPTURED: code that reads that meta directly must not take a released hold for a payment.
	 */
	public function mark_released(): void {
		$this->order->update_meta_data( self::CAPTURED, self::RELEASED );
		$this->order->update_meta_data( self::CAPTURED_AMOUNT, '0' );
	}

	public function released(): bool {
		return self::RELEASED === $this->order->get_meta( self::CAPTURED );
	}

	public function captured_amount(): int {
		return (int) $this->order->get_meta( self::CAPTURED_AMOUNT );
	}

	/**
	 * What a capture (or a release with nothing due) was worked out from: the attempt it used, WooCommerce's refunded
	 * total then (minor units), and the WooCommerce refunds its amount left out (ids; none for a capture made outside
	 * the shop).
	 */
	public function set_capture_basis( string $intent_id, int $refunded, array $refund_ids ): void {
		$this->order->update_meta_data( self::CAPTURED_INTENT, $intent_id );
		$this->order->update_meta_data( self::REFUNDED_AT_CAPTURE, (string) $refunded );
		$this->order->update_meta_data( self::CAPTURE_REFUNDS, wp_json_encode( array_values( array_map( 'intval', $refund_ids ) ) ) );
	}

	public function refunded_at_capture(): int {
		return (int) $this->order->get_meta( self::REFUNDED_AT_CAPTURE );
	}

	/** @return int[] */
	public function capture_refund_ids(): array {
		$ids = json_decode( (string) $this->order->get_meta( self::CAPTURE_REFUNDS ), true );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/** The attempt that pays the order: the paying one, else the one a capture was recorded on. '' when neither. */
	public function payment(): string {
		$paying = $this->paying();
		if ( '' !== $paying ) {
			return $paying;
		}
		return $this->captured() ? (string) $this->order->get_meta( self::CAPTURED_INTENT ) : '';
	}

	/** Each capture try gets its own number, so its own idempotency key (Stripe replays a saved answer — a 5xx too — for 24 h). */
	public function next_capture_try(): int {
		$try = (int) $this->order->get_meta( self::CAPTURE_TRIES ) + 1;
		$this->order->update_meta_data( self::CAPTURE_TRIES, (string) $try );
		return $try;
	}

	/** The same for releasing a hold or closing an attempt (any of the order's attempts, any path): a number per try. */
	public function next_cancel_try(): int {
		$try = (int) $this->order->get_meta( self::CANCEL_TRIES ) + 1;
		$this->order->update_meta_data( self::CANCEL_TRIES, (string) $try );
		return $try;
	}

	/** The same for refunding a duplicate payment (SMPW_Payments::refund_duplicate()): a number per try. */
	public function next_dupe_try(): int {
		$try = (int) $this->order->get_meta( self::DUPE_TRIES ) + 1;
		$this->order->update_meta_data( self::DUPE_TRIES, (string) $try );
		return $try;
	}

	public function precapture_refunded(): int {
		return (int) $this->order->get_meta( self::PRECAPTURE );
	}

	public function add_precapture_refund( int $amount ): void {
		$this->order->update_meta_data( self::PRECAPTURE, (string) ( $this->precapture_refunded() + $amount ) );
	}

	public function session(): string {
		return (string) $this->order->get_meta( self::SESSION );
	}

	public function set_session( string $key ): void {
		$this->order->update_meta_data( self::SESSION, $key );
	}

	/** Once-only markers (a note that must not repeat on every sync). */
	public function flag( string $name ): bool {
		return in_array( $name, $this->flags(), true );
	}

	public function set_flag( string $name ): void {
		$flags = $this->flags();
		if ( ! in_array( $name, $flags, true ) ) {
			$flags[] = $name;
			$this->order->update_meta_data( self::FLAGS, wp_json_encode( array_slice( $flags, -50 ) ) );
		}
	}

	private function flags(): array {
		$flags = json_decode( (string) $this->order->get_meta( self::FLAGS ), true );
		return is_array( $flags ) ? $flags : array();
	}

	public function save(): void {
		$this->order->save();
	}

	/** The order that made a PaymentIntent (lookup rows written by add_attempt), whatever its payment method now. */
	public static function find_order( string $intent_id ): ?WC_Order {
		if ( '' === $intent_id ) {
			return null;
		}
		$ids = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'limit'      => 1,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => self::INTENT_ID, 'value' => $intent_id ),
				),
			)
		);
		$order = $ids ? wc_get_order( (int) $ids[0] ) : false;
		return $order instanceof WC_Order ? $order : null;
	}
}
