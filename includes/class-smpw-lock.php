<?php
defined( 'ABSPATH' ) || exit;

/**
 * One order at a time: a MySQL named lock per order (server-wide, so the name includes the database),
 * re-entrant within one PHP request — a refund started inside a sync may take the lock again.
 */
final class SMPW_Lock {

	/** @var array<string, int> */
	private static array $held = array();

	/**
	 * Take the order's lock (waiting up to $wait seconds). The first acquisition in a request also forgets this request's
	 * copies of the order (forget()), so the caller's wc_get_order() right after reads what the last holder wrote.
	 */
	public static function acquire( int $order_id, int $wait = 15 ): bool {
		$name = self::name( $order_id );
		if ( isset( self::$held[ $name ] ) ) {
			++self::$held[ $name ];
			return true;
		}
		global $wpdb;
		$got = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $wait ) );
		if ( $got ) {
			self::$held[ $name ] = 1;
			self::forget( $order_id );
		}
		return $got;
	}

	public static function release( int $order_id ): void {
		$name = self::name( $order_id );
		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}
		if ( --self::$held[ $name ] > 0 ) {
			return;
		}
		unset( self::$held[ $name ] );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	public static function name( int $order_id ): string {
		return 'smpw_' . substr( md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $order_id ), 0, 40 );
	}

	/**
	 * The request may have loaded the order before it waited for the lock, while another request changed it: with a
	 * persistent object cache (e.g. Redis) this request keeps its own copies in memory, and wc_get_order() would hand
	 * back the old order. Drop them — the order's meta cache, WooCommerce's order cache and the HPOS data store's
	 * cache, the refund totals the payments reckon with, and the posts table's rows — so the next read comes from the
	 * database. Best effort: if clearing fails, the next read may come from the cache, as it would without this.
	 */
	private static function forget( int $order_id ): void {
		try {
			if ( function_exists( 'wp_cache_delete' ) ) {
				if ( class_exists( 'WC_Data' ) ) {
					wp_cache_delete( WC_Data::generate_meta_cache_key( $order_id, 'orders' ), 'orders' );
				}
				if ( class_exists( 'WC_Cache_Helper' ) ) {
					// WC_Order::get_total_refunded() and friends cache per order under the "orders" prefix this request knows.
					$prefix = WC_Cache_Helper::get_cache_prefix( 'orders' );
					foreach ( array( 'refund_ids', 'total_refunded', 'total_tax_refunded', 'total_shipping_refunded', 'total_shipping_tax_refunded' ) as $key ) {
						wp_cache_delete( $prefix . $key . $order_id, 'orders' );
					}
				}
				wp_cache_delete( $order_id, 'posts' );
				wp_cache_delete( $order_id, 'post_meta' );
			}
			if ( function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) ) {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
			}
			if ( class_exists( 'WC_Data_Store' ) ) {
				WC_Data_Store::load( 'order' )->clear_cached_data( array( $order_id ) ); // HPOS data-store cache; a no-op otherwise.
			}
		} catch ( Throwable $e ) {
			SMPW_Log::warning( 'lock: the order\'s cached copies could not be cleared', array( 'order' => $order_id, 'error' => $e->getMessage() ) );
		}
	}
}
