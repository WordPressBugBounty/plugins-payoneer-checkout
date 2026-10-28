<?php
/**
 * Webhook notification handler for Payoneer Checkout.
 */

namespace PayoneerCheckout\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;
use PayoneerCheckout\Plugin;
use WP_REST_Response;

/**
 * Handles incoming webhook notifications from the Payoneer API.
 */
final class WebhookHandler {

	/**
	 * Register the REST API endpoint.
	 */
	public function register() {
		register_rest_route(
			'inpsyde/payoneer-checkout',
			'/listener/notifications',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'handle_notification' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'notificationId' => array(
						'type'     => 'string',
						'required' => true,
					),
					'entity'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'transactionId'  => array(
						'type'     => 'string',
						'required' => false,
					),
					'longId'         => array(
						'type'     => 'string',
						'required' => false,
					),
					'statusCode'     => array(
						'type'     => 'string',
						'required' => false,
					),
					'timestamp'      => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}


	/**
	 * Handle an incoming webhook notification.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_notification( $request ) {
		$data = $request->get_json_params();

		$notification_id = sanitize_text_field( $data['notificationId'] ?? '' );
		$entity          = sanitize_text_field( $data['entity'] ?? '' );

		if ( false !== strpos( (string) $request->get_route(), '/inpsyde/payoneer-checkout/' ) ) {
			Plugin::instance()->logger()->log(
				sprintf( 'Legacy webhook for notificationId %s', $notification_id ),
				'info'
			);
		}

		$order = $this->get_order( $data );

		if ( ! $order ) {
			Plugin::instance()->logger()->log(
				sprintf( 'Webhook order not found [transactionId=%s, longId=%s]', sanitize_text_field( $data['transactionId'] ?? '' ), sanitize_text_field( $data['longId'] ?? '' ) ),
				'error'
			);
			return new WP_REST_Response( array( 'status' => 'order_not_found' ), 404 );
		}

		$expected_token = $order->get_meta( '_payoneer_security_header_value', true );

		if ( empty( $expected_token ) ) {
			Plugin::instance()->logger()->log(
				sprintf( 'Webhook for order #%d has no _payoneer_security_header_value on the order', $order->get_id() ),
				'error'
			);
			return new WP_REST_Response( array( 'status' => 'security_header_missing' ), 410 );
		}

		$token_header = sanitize_text_field( $request->get_header( 'List-Security-Token' ) ?? '' );

		if ( empty( $token_header ) || ! hash_equals( $expected_token, $token_header ) ) {
			Plugin::instance()->logger()->log(
				sprintf( 'Webhook auth failed for order #%d (token mismatch)', $order->get_id() ),
				'error'
			);
			return new WP_REST_Response( array( 'status' => 'auth_failed' ), 401 );
		}

		$dedup_key = $this->build_dedup_key( $order->get_id(), $notification_id, $data );

		if ( get_transient( $dedup_key ) ) {
			return new WP_REST_Response( array( 'status' => 'already_processed' ), 200 );
		}

		set_transient( $dedup_key, '1', 10 * MINUTE_IN_SECONDS );

		if ( 'payment' !== $entity ) {
			return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
		}

		if ( ! empty( $notification_id ) && in_array( $notification_id, $this->get_processed_notification_ids( $order ), true ) ) {
			return new WP_REST_Response( array( 'status' => 'already_processed' ), 200 );
		}

		$order_id = $order->get_id();
		$gateway  = Plugin::instance()->gateway();

		if ( ! $gateway ) {
			Plugin::instance()->logger()->log(
				sprintf( 'Gateway unavailable; cannot process webhook for order #%d', $order_id ),
				'error'
			);
			delete_transient( $dedup_key );
			return new WP_REST_Response( array( 'status' => 'gateway_unavailable' ), 503 );
		}

		Plugin::instance()->logger()->log(
			$this->build_received_log_line( $order_id, $notification_id, $entity, $data ),
			'debug'
		);

		$response_status = 200;
		$response_body   = array( 'status' => 'ok' );

		try {
			$gateway->apply_payment_to_order( $order, $data );
			$this->save_processed_notification_id( $order, $notification_id );
			$order->save();
		} catch ( \Exception $e ) {
			Plugin::instance()->logger()->log(
				sprintf( 'Webhook processing failed for order #%d: %s', $order_id, $e->getMessage() ),
				'error'
			);
			delete_transient( $dedup_key );
			$response_status = 500;
			$response_body   = array( 'status' => 'processing_error' );
		}

		return new WP_REST_Response( $response_body, $response_status );
	}


	/**
	 * Build the structured "Webhook received" log line.
	 *
	 * @param int    $order_id        Resolved WC order id.
	 * @param string $notification_id Payoneer notificationId.
	 * @param string $entity          Webhook entity (already sanitized).
	 * @param array  $data            Webhook payload.
	 *
	 * @return string
	 */
	private function build_received_log_line( $order_id, $notification_id, $entity, $data ) {
		$fields = array(
			'notificationId'     => $notification_id,
			'longId'             => sanitize_text_field( $data['longId'] ?? '' ),
			'transactionId'      => sanitize_text_field( $data['transactionId'] ?? '' ),
			'shortId'            => sanitize_text_field( $data['shortId'] ?? '' ),
			'entity'             => $entity,
			'statusCode'         => sanitize_text_field( $data['statusCode'] ?? '' ),
			'reasonCode'         => sanitize_text_field( $data['reasonCode'] ?? ( $data['reason'] ?? '' ) ),
			'previousStatusCode' => sanitize_text_field( $data['previousStatusCode'] ?? '' ),
			'previousReasonCode' => sanitize_text_field( $data['previousReasonCode'] ?? '' ),
			'interactionCode'    => sanitize_text_field( $data['interactionCode'] ?? '' ),
			'interactionReason'  => sanitize_text_field( $data['interactionReason'] ?? '' ),
			'resultCode'         => sanitize_text_field( $data['resultCode'] ?? '' ),
			'resultInfo'         => sanitize_text_field( $data['resultInfo'] ?? '' ),
			'network'            => sanitize_text_field( $data['network'] ?? '' ),
			'amount'             => isset( $data['amount'] ) ? (string) $data['amount'] : '',
			'currency'           => sanitize_text_field( $data['currency'] ?? '' ),
			'timestamp'          => sanitize_text_field( $data['timestamp'] ?? '' ),
			'reference'          => sanitize_text_field( $data['payment']['reference'] ?? ( $data['reference'] ?? '' ) ),
		);

		$parts = array();
		foreach ( $fields as $key => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			$parts[] = $key . '=' . ( false === strpos( $value, ' ' ) ? $value : '"' . $value . '"' );
		}

		return sprintf( 'Webhook received for order #%d [%s]', $order_id, implode( ', ', $parts ) );
	}


	/**
	 * Build the dedup transient key namespaced by order id + notification id.
	 *
	 * @param int    $order_id        Resolved WC order id.
	 * @param string $notification_id Payoneer notificationId (may be empty).
	 * @param array  $data            Full webhook payload (used as fallback hash).
	 *
	 * @return string
	 */
	private function build_dedup_key( $order_id, $notification_id, $data ) {
		$suffix = $notification_id ? $notification_id : md5( (string) wp_json_encode( $data ) );
		return 'payoneer_notif_' . $order_id . '_' . $suffix;
	}


	/**
	 * Retrieve the list of processed notification IDs from the order meta.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return array
	 */
	private function get_processed_notification_ids( $order ) {
		$ids = $order->get_meta( '_payoneer_webhooks_received', true );

		return is_array( $ids ) ? $ids : array();
	}


	/**
	 * Save the processed notification ID to the order meta.
	 *
	 * @param \WC_Order $order           The order.
	 * @param string    $notification_id The notification ID to record.
	 */
	private function save_processed_notification_id( $order, $notification_id ) {
		if ( empty( $notification_id ) ) {
			return;
		}

		$order->update_meta_data(
			'_payoneer_webhooks_received',
			array_unique(
				array_merge(
					$this->get_processed_notification_ids( $order ),
					array( $notification_id )
				)
			)
		);
	}


	/**
	 * Resolve the WooCommerce order from a webhook payload.
	 *
	 * @param array $data Webhook payload.
	 *
	 * @return \WC_Order|null
	 */
	private function get_order( $data ) {
		$order = $this->get_order_by_meta_lookup( $data );
		if ( $order ) {
			return $order;
		}

		return $this->get_order_by_reference( $data );
	}


	/**
	 * Resolve the order via the payload reference (order number); the security token authenticates.
	 *
	 * @param array $data Webhook payload.
	 *
	 * @return \WC_Order|null
	 */
	private function get_order_by_reference( $data ) {
		$reference = sanitize_text_field( $data['payment']['reference'] ?? ( $data['reference'] ?? '' ) );

		if ( '' === $reference ) {
			return null;
		}

		$order = wc_get_order( absint( $reference ) );

		return $order instanceof \WC_Order ? $order : null;
	}


	/**
	 * Resolve the order via meta lookup on transactionId, charge longId, or refund metas.
	 *
	 * @param array $data Webhook payload.
	 *
	 * @return \WC_Order|null
	 */
	private function get_order_by_meta_lookup( $data ) {
		$transaction_id = sanitize_text_field( $data['transactionId'] ?? '' );
		$long_id        = sanitize_text_field( $data['longId'] ?? '' );

		if ( ! empty( $transaction_id ) ) {
			$orders = wc_get_orders(
				array(
					'transaction_id' => $transaction_id,
					'type'           => 'shop_order',
					'limit'          => 1,
				)
			);

			if ( ! empty( $orders ) ) {
				return $orders[0];
			}

			$order = $this->find_order_by_meta( array( '_payoneer_payment_transaction_id' ), $transaction_id );
			if ( $order ) {
				return $order;
			}
		}

		if ( ! empty( $long_id ) ) {
			$order = $this->find_order_by_meta( array( '_payoneer_payment_charge_id', '_payoneer-long-id' ), $long_id );
			if ( $order ) {
				return $order;
			}
		}

		$refund = false;
		if ( ! empty( $long_id ) ) {
			$refund = $this->find_order_by_meta( array( '_payoneer_payout_id' ), $long_id, 'shop_order_refund' );
		}
		if ( ! $refund && ! empty( $transaction_id ) ) {
			$refund = $this->find_order_by_meta( array( '_payoneer_refund_txn_id' ), $transaction_id, 'shop_order_refund' );
		}
		if ( $refund ) {
			$parent = wc_get_order( $refund->get_parent_id() );
			if ( $parent ) {
				return $parent;
			}
		}

		return null;
	}


	/**
	 * Find one order/refund by meta data.
	 *
	 * @param array  $meta_keys  Meta keys to match.
	 * @param string $meta_value Exact meta value.
	 * @param string $type       Order type to search.
	 *
	 * @return \WC_Abstract_Order|bool Either an order/refund or false when not found.
	 */
	private function find_order_by_meta( $meta_keys, $meta_value, $type = 'shop_order' ) {
		global $wpdb;

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$meta_query = array( 'relation' => 'OR' );
			foreach ( $meta_keys as $meta_key ) {
				$meta_query[] = array(
					'key'   => $meta_key,
					'value' => $meta_value,
				);
			}

			$orders   = wc_get_orders(
				array(
					'type'       => $type,
					'limit'      => 1,
					'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			);
			$order_id = current( $orders ) ? current( $orders )->get_id() : false;
		} else {
			$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders interpolated, params prepared.
			$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT DISTINCT ID FROM $wpdb->posts as posts LEFT JOIN $wpdb->postmeta as meta ON posts.ID = meta.post_id WHERE meta.meta_value = %s AND meta.meta_key IN ({$placeholders}) AND posts.post_type = %s", array_merge( array( $meta_value ), $meta_keys, array( $type ) ) ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		}

		if ( ! empty( $order_id ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! empty( $order ) && $order->get_status() !== 'trash' ) {
			return $order;
		}

		return false;
	}
}
