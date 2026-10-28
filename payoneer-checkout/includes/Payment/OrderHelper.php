<?php
/**
 * Helper class for order-related operations in Payoneer Checkout.
 */

namespace PayoneerCheckout\Payment;

use PayoneerCheckout\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Static helper for order-related operations in Payoneer Checkout.
 */
final class OrderHelper {

	/**
	 * Meta key for lock payment to prevent multiple simultaneous payment attempts.
	 */
	const META_PAYMENT_LOCK = '_payoneer_payment_locked';

	/**
	 * Meta key for the awaiting-webhook flag (timestamp when set).
	 */
	const META_AWAITING_WEBHOOK = '_payoneer_awaiting_webhook';


	/**
	 * Acquire a payment lock for the order.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return bool True if the order is already locked by another request.
	 */
	public static function lock_order_payment( $order ) {
		if ( self::is_order_payment_locked( $order ) ) {
			return true;
		}

		$order->update_meta_data( self::META_PAYMENT_LOCK, time() + 30 * MINUTE_IN_SECONDS );
		$order->save_meta_data();

		return false;
	}


	/**
	 * Release the payment lock for the order.
	 *
	 * @param \WC_Order $order The order.
	 */
	public static function unlock_order_payment( $order ) {
		$order->delete_meta_data( self::META_PAYMENT_LOCK );
		$order->save_meta_data();
	}


	/**
	 * Whether the order currently has an active (non-expired) payment lock.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return bool
	 */
	public static function is_order_payment_locked( $order ) {
		$order->read_meta_data( true );
		$expiry = $order->get_meta( self::META_PAYMENT_LOCK, true );

		return $expiry > 0 && time() <= $expiry;
	}


	/**
	 * Whether the order is still inside the awaiting-webhook suppression window.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return bool
	 */
	public static function is_awaiting_webhook( $order ) {
		$is_awaiting = $order->get_meta( self::META_AWAITING_WEBHOOK, true );

		if ( 'yes' === $is_awaiting ) {
			$awaiting_at = ! empty( $order->get_date_modified() ) ? (int) $order->get_date_modified()->getTimestamp() : 0;
		} else {
			$awaiting_at = (int) $is_awaiting;
		}

		return $awaiting_at > 0 && ( time() - $awaiting_at ) < ( 30 * MINUTE_IN_SECONDS );
	}


	/**
	 * Mark an order as awaiting asynchronous webhook confirmation.
	 *
	 * @param \WC_Order $order The order.
	 * @param string    $note  Optional status-change note.
	 */
	public static function put_order_awaiting_payment( $order, $note = '' ) {
		$order->update_meta_data( self::META_AWAITING_WEBHOOK, time() );

		if ( ! $order->has_status( 'pending' ) ) {
			$order->update_status( 'pending', $note );
			return;
		}

		if ( '' !== $note ) {
			$order->add_order_note( $note );
		}
		$order->save();
	}


	/**
	 * Remove the awaiting-webhook meta flag. Caller persists the order.
	 *
	 * @param \WC_Order $order The order.
	 */
	public static function clear_awaiting_webhook_flag( $order ) {
		$order->delete_meta_data( self::META_AWAITING_WEBHOOK );
	}


	/**
	 * Clear LIST-related meta so a fresh session can be created.
	 *
	 * @param \WC_Order $order The order.
	 */
	public static function reset_payment_meta( $order ) {
		$order->delete_meta_data( '_payoneer-long-id' );
		self::clear_awaiting_webhook_flag( $order );
		self::unlock_order_payment( $order );
		$order->save();

		Utils::cleanup_cached_list();
	}
}
