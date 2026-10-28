<?php
/**
 * Utility methods.
 */

namespace PayoneerCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Order;

/**
 * Utils class
 */
final class Utils {

	/**
	 * What type of request is this?
	 *
	 * @param  string $type admin, ajax, cron or frontend.
	 *
	 * @return bool
	 */
	public static function is_request( $type ) {

		switch ( $type ) {
			case 'admin':
				return is_admin();
			case 'ajax':
				return wp_doing_ajax();
			case 'cron':
				return wp_doing_cron();
			case 'frontend':
				return ( ! is_admin() || wp_doing_ajax() ) && ! wp_doing_cron();
			case 'rest':
				return defined( 'REST_REQUEST' ) && REST_REQUEST || ( method_exists( WC(), 'is_rest_api_request' ) && WC()->is_rest_api_request() );
			default:
				return false;
		}
	}


	/**
	 * Get the current tab token from the request.
	 *
	 * @return string
	 */
	public static function request_tab_token() {
		if ( isset( $_SERVER['HTTP_X_PAYONEER_TAB'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PAYONEER_TAB'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the classic checkout submit carries its own nonce.
		return isset( $_POST['payoneer_tab_token'] ) ? sanitize_text_field( wp_unslash( $_POST['payoneer_tab_token'] ) ) : '';
	}


	/**
	 * Generate unique token identifier for the checkout attempt.
	 *
	 * @return string
	 */
	public static function page_tab_token() {
		static $token;
		if ( null === $token ) {
			$token = wp_generate_uuid4();
		}
		return $token;
	}


	/**
	 * Get the plugin url.
	 *
	 * @return string
	 */
	public static function plugin_url() {
		return untrailingslashit( plugins_url( '/', PLUGIN_FILE ) );
	}


	/**
	 * Get the plugin path.
	 *
	 * @return string
	 */
	public static function plugin_path() {
		return untrailingslashit( plugin_dir_path( PLUGIN_FILE ) );
	}


	/**
	 * Get the template path.
	 *
	 * @return string
	 */
	public static function template_path() {
		// Allow 3rd party plugin filter template path from their plugin.
		return apply_filters( 'payoneer_checkout_template_path', 'payoneer-checkout/' );
	}


	/**
	 * Get Ajax URL.
	 *
	 * @return string
	 */
	public static function ajax_url() {
		return admin_url( 'admin-ajax.php', 'relative' );
	}


	/**
	 * Get the gateway settings URL.
	 *
	 * @return string
	 */
	public static function settings_url() {
		if ( ! defined( 'WC_VERSION' ) ) {
			return admin_url( 'admin.php' );
		}
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Gateways\WC_Payoneer_Gateway::ID );
	}


	/**
	 * Get a value from the WooCommerce session.
	 *
	 * @param string $key Session key (without the payoneer_ prefix).
	 *
	 * @return mixed
	 */
	public static function get_session_key( $key ) {
		return WC()->session ? WC()->session->get( 'payoneer_' . $key ) : '';
	}


	/**
	 * Set a value in the WooCommerce session.
	 *
	 * @param string $key   Session key (without the payoneer_ prefix).
	 * @param mixed  $value Value to store.
	 *
	 * @return void
	 */
	public static function set_session_key( $key, $value ) {
		if ( WC()->session ) {
			WC()->session->set( 'payoneer_' . $key, $value );
		}
	}


	/**
	 * Clear the cached LIST session keys from the WooCommerce session.
	 */
	public static function cleanup_cached_list() {
		self::set_session_key( 'list_long_id', '' );
		self::set_session_key( 'list_transaction_id', '' );
		self::set_session_key( 'list_expires_at', '' );
		self::set_session_key( 'list_applicable_networks', '' );
		self::set_session_key( 'list_payload_hash', '' );
		self::set_session_key( 'list_pending_order_id', '' );
	}


	/**
	 * Resolve the order currently in flight in this session via plugin or WC markers.
	 *
	 * @return \WC_Order|null
	 */
	public static function get_current_pending_order() {
		$session = WC()->session;
		if ( ! $session ) {
			return null;
		}

		$order_id = (int) self::get_session_key( 'list_pending_order_id' );
		if ( ! $order_id ) {
			$order_id = (int) $session->get( 'order_awaiting_payment' );
		}
		if ( ! $order_id ) {
			$order_id = (int) $session->get( 'store_api_draft_order' );
		}
		if ( ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof \WC_Order ? $order : null;
	}


	/**
	 * Detach the session order markers so WooCommerce creates a fresh order on the next checkout.
	 */
	public static function detach_pending_order() {
		$session = WC()->session;
		if ( ! $session ) {
			return;
		}

		$session->set( 'order_awaiting_payment', null );
		$session->set( 'store_api_draft_order', null );
		self::set_session_key( 'list_pending_order_id', '' );
	}


	/**
	 * Network codes that render inside the SDK `cards` drop-in component.
	 */
	const CC_CODES = array( 'VISA', 'MASTERCARD', 'AMEX', 'DISCOVER', 'JCB', 'UNIONPAY', 'CARTEBANCAIRE', 'APPLEPAY', 'GOOGLEPAY' );


	/**
	 * Gateway IDs this plugin owns besides the primary one: the hosted page plus one per network.
	 */
	public static function network_gateway_ids() {
		$ids = array( 'payoneer-hosted' );

		foreach ( self::NETWORK_ORDER as $code ) {
			$ids[] = 'payoneer-' . strtolower( $code );
		}

		return $ids;
	}


	/**
	 * Default network display order.
	 */
	const NETWORK_ORDER = array( 'AFFIRM', 'AFTERPAY', 'KLARNA', 'BANCONTACT', 'EPS', 'IDEAL', 'MULTIBANCO', 'P24' );


	/**
	 * Override for network labels.
	 */
	const NETWORK_LABELS = array(
		'IDEAL' => 'iDEAL | Wero',
		'EPS'   => 'EPS',
		'P24'   => 'P24',
	);


	/**
	 * Resolve a network's display label.
	 *
	 * @param string $code      Network code.
	 * @param string $raw_label Raw label from the API.
	 *
	 * @return string
	 */
	public static function format_network_label( $code, $raw_label = '' ) {
		$code = strtoupper( (string) $code );

		if ( isset( self::NETWORK_LABELS[ $code ] ) ) {
			return self::NETWORK_LABELS[ $code ];
		}

		$base = '' !== $raw_label ? $raw_label : $code;
		if ( $base === strtoupper( $base ) ) {
			return ucwords( strtolower( $base ) );
		}

		return $base;
	}


	/**
	 * Automatically prefix a gateway title with "Test: " while the plugin is in sandbox mode.
	 *
	 * @param string $title   Resolved gateway title.
	 * @param bool   $is_test Whether sandbox mode is active.
	 *
	 * @return string
	 */
	public static function apply_test_prefix( $title, $is_test ) {
		if ( $is_test && 0 !== stripos( (string) $title, 'Test:' ) ) {
			return 'Test: ' . $title;
		}

		return $title;
	}


	/**
	 * Whether the current request is targeting the WooCommerce Payments settings screen — either the admin page (?page=wc-settings&tab=checkout) or the React UI's REST endpoint (/wc-admin/settings/payments/...).
	 *
	 * @return bool
	 */
	public static function is_payments_settings_screen() {
		if ( is_admin() ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
			$tab  = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';

			if ( 'wc-settings' === $page && 'checkout' === $tab && empty( $_GET['section'] ) ) {
				return true;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}

		if ( self::is_request( 'rest' ) ) {
			$route = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

			return false !== strpos( $route, 'wc-admin/settings/payments' );
		}

		return false;
	}


	/**
	 * Check whether the current admin screen is the gateway settings page.
	 *
	 * @return bool
	 */
	public static function is_settings_page() {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page    = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'wc-settings' === $page && 'checkout' === $tab && Gateways\WC_Payoneer_Gateway::ID === $section;
	}


	/**
	 * Check if the current page is the checkout page.
	 *
	 * @return bool
	 */
	public static function is_checkout_page() {
		return is_checkout() || self::is_blocks_checkout_page();
	}


	/**
	 * Check if the current page is the checkout page.
	 *
	 * @return bool
	 */
	public static function is_blocks_checkout_page() {
		return has_block( 'woocommerce/checkout' );
	}


	/**
	 * Get the current order to be paid.
	 *
	 * @return WC_Order|null
	 */
	public static function get_current_order() {

		static $order;

		$order_id = (int) get_query_var( 'order-pay' );

		if ( ! $order_id ) {
			return null;
		}

		if ( is_a( $order, WC_Order::class ) && $order->get_id() === $order_id ) {
			return $order;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return null;
		}

		return $order;
	}
}
