<?php
/**
 * Payoneer Gateway class.
 */

namespace PayoneerCheckout\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PayoneerCheckout\Payment\OrderHelper;
use PayoneerCheckout\Payment\PayloadHelper;
use PayoneerCheckout\Plugin;
use PayoneerCheckout\Utils;
use WC_Admin_Settings;
use WC_Payment_Gateway_CC;

/**
 * Payment gateway for Payoneer Checkout.
 */
final class WC_Payoneer_Gateway extends WC_Payment_Gateway_CC {

	/**
	 * Gateway ID.
	 */
	const ID = 'payoneer-checkout';


	/**
	 * Whether the given payment method belongs to this plugin.
	 *
	 * @param string $payment_method Payment method ID from the order.
	 *
	 * @return bool
	 */
	public static function is_payoneer_payment_method( $payment_method ) {
		return 0 === strpos( (string) $payment_method, 'payoneer-' );
	}


	/** @var Plugin */
	private Plugin $plugin;


	/** @var string Payout transaction ID for linking to the WC refund object via on_order_refunded(). */
	private string $last_refund_txn_id = '';

	/** @var string Payout longId — unique per refund — used to identify refunds across webhooks. */
	private string $last_refund_long_id = '';

	/** @var bool True when the most recent refund API response was a synchronous success (no webhook needed). */
	private bool $last_refund_sync_success = false;

	/** @var \Automattic\WooCommerce\StoreApi\Payments\PaymentResult|null Captured Blocks PaymentResult so process_payment can write paymentDetails back to it. */
	public static $blocks_payment_result = null;


	/**
	 * Initialize Payment Gateway.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_description = __( 'Accept secure payments on your WooCommerce store.', 'payoneer-checkout' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );
		$this->plugin             = Plugin::instance();

		$this->init_form_fields();
		$this->init_settings();

		$this->method_title = $this->resolve_method_title();
		$this->title        = $this->get_title();
		$this->description  = $this->get_description();
		$this->icon         = $this->resolve_icon();

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);

		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 2 );
		add_filter( 'woocommerce_gateway_title', array( $this, 'maybe_use_order_payment_method_title' ), 10, 2 );
	}


	/**
	 * Match saved payment method title on the admin order screen.
	 *
	 * @param string $title      Gateway title produced by WC_Payment_Gateway::get_title().
	 * @param string $gateway_id Gateway id passed to the filter.
	 *
	 * @return string
	 */
	public function maybe_use_order_payment_method_title( $title, $gateway_id ) {
		if ( self::ID !== $gateway_id ) {
			return $title;
		}

		$order = self::resolve_admin_order_context();
		if ( ! $order instanceof \WC_Order ) {
			return $title;
		}

		$stored = (string) $order->get_payment_method_title();

		return '' !== $stored ? $stored : $title;
	}


	/**
	 * Resolve the order being viewed on the current admin screen.
	 *
	 * @return \WC_Order|null
	 */
	private static function resolve_admin_order_context() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return null;
		}

		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen ) {
			return null;
		}

		if ( 'woocommerce_page_wc-orders' === $screen->id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended.
			$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			return $id ? wc_get_order( $id ) : null;
		}

		if ( 'shop_order' === $screen->id ) {
			global $post;
			return $post ? wc_get_order( $post->ID ) : null;
		}

		return null;
	}


	/**
	 * Override the order payment method title on the admin order screen to match the saved value.
	 */
	public static function override_order_payment_method() {
		$order = self::resolve_admin_order_context();
		if ( ! $order instanceof \WC_Order || self::ID !== $order->get_payment_method() ) {
			return;
		}

		$stored = (string) $order->get_payment_method_title();
		if ( '' === $stored ) {
			return;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( isset( $gateways[ self::ID ] ) ) {
			$gateways[ self::ID ]->method_title = $stored;
		}
	}


	/**
	 * Override the WC-derived option key to preserve the legacy literal across upgrades.
	 *
	 * @return string
	 */
	public function get_option_key() {
		return 'woocommerce_payoneer_checkout_settings';
	}


	/**
	 * Resolve the checkout title based on integration type and test mode.
	 *
	 * @return string
	 */
	public function get_title() {
		$key   = $this->resolve_field_key( 'title' );
		$title = $this->get_option( $key, $this->plugin->settings()->get_default_setting( $key ) );

		$title = Utils::apply_test_prefix( $title, $this->plugin->is_test_mode() );

		return apply_filters( 'woocommerce_gateway_title', $title, $this->id );
	}


	/**
	 * Resolve the checkout description based on integration type and test mode.
	 *
	 * @return string
	 */
	public function get_description() {
		$key = $this->resolve_field_key( 'description' );
		return $this->get_option( $key );
	}


	/**
	 * Build the prefixed setting key for the active integration type and mode.
	 *
	 * @param string $base Base field name ('title' or 'description').
	 *
	 * @return string
	 */
	private function resolve_field_key( $base ) {
		if ( $this->plugin->is_hosted_integration() ) {
			return 'hosted_' . $base;
		}

		return ( $this->plugin->is_test_mode() ? 'test_' : '' ) . $base;
	}


	/**
	 * Resolve the admin gateway title based on integration type.
	 *
	 * @return string
	 */
	public function resolve_method_title() {
		if ( $this->plugin->is_hosted_integration() ) {
			return __( 'Payoneer - Hosted Payment Page', 'payoneer-checkout' );
		}

		return __( 'Payoneer - Credit / Debit cards', 'payoneer-checkout' );
	}


	/**
	 * Resolve the checkout icon URL based on integration type.
	 *
	 * @return string
	 */
	public function resolve_icon() {
		if ( $this->plugin->is_hosted_integration() ) {
			return '';
		}

		return Utils::plugin_url() . '/assets/images/cc-logo.svg';
	}


	/**
	 * Initialize gateway form fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = $this->plugin->settings()->get_settings( true );
	}


	/**
	 * Render the two-column settings layout.
	 *
	 * @return void
	 */
	public function admin_options() {
		$this->plugin->maybe_refresh_networks( true );
		$this->plugin->maybe_refresh_networks( false );
		$this->plugin->settings()->render_settings_page( $this );
	}


	/**
	 * Save admin options, validate credentials, and refresh settings.
	 *
	 * @return void
	 */
	public function process_admin_options() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the nonce before calling process_admin_options().
		$is_live_mode_posted = isset( $_POST[ 'woocommerce_' . self::ID . '_live_mode' ] );
		$inactive_code_key   = $is_live_mode_posted ? 'sandbox_merchant_code' : 'merchant_code';
		$inactive_token_key  = $is_live_mode_posted ? 'sandbox_merchant_token' : 'merchant_token';
		$old_inactive_code   = $this->plugin->get_gateway_setting( $inactive_code_key );
		$old_inactive_token  = $this->plugin->get_gateway_setting( $inactive_token_key );

		$old_test_division = $this->plugin->get_gateway_setting( 'sandbox_store_code' );
		$old_live_division = $this->plugin->get_gateway_setting( 'store_code' );

		parent::process_admin_options();

		$this->validate_credentials( $old_inactive_code, $old_inactive_token );

		if ( $this->plugin->get_gateway_setting( 'sandbox_store_code' ) !== $old_test_division ) {
			$this->plugin->update_disabled_networks( array(), true );
			$this->plugin->update_network_settings( array(), true );
		}
		if ( $this->plugin->get_gateway_setting( 'store_code' ) !== $old_live_division ) {
			$this->plugin->update_disabled_networks( array(), false );
			$this->plugin->update_network_settings( array(), false );
		}

		$this->plugin->maybe_refresh_networks( true, true );
		$this->plugin->maybe_refresh_networks( false, true );

		$this->plugin->settings()->save_enabled_networks();
		$this->plugin->settings()->save_network_settings();
		$this->plugin->logger()->log( 'Payment networks saved' );

		$this->init_form_fields();
	}


	/**
	 * Validate the posted API credentials against the Payoneer API.
	 *
	 * @param string|null $old_inactive_code  Stored inactive-mode merchant code captured before parent::process_admin_options() saved POST values.
	 * @param string|null $old_inactive_token Stored inactive-mode token captured before parent::process_admin_options() saved POST values.
	 *
	 * @return void
	 */
	public function validate_credentials( $old_inactive_code = null, $old_inactive_token = null ) {
		$is_test_mode = ! wc_string_to_bool( $this->get_posted_config_value( 'live_mode' ) );

		$this->plugin->logger()->log( 'Validating API credentials (test_mode: ' . ( $is_test_mode ? 'yes' : 'no' ) . ')' );

		$this->plugin->update_gateway_setting( 'live_mode', $is_test_mode ? 'no' : 'yes' );

		$active_code_key    = $is_test_mode ? 'sandbox_merchant_code' : 'merchant_code';
		$active_token_key   = $is_test_mode ? 'sandbox_merchant_token' : 'merchant_token';
		$inactive_code_key  = $is_test_mode ? 'merchant_code' : 'sandbox_merchant_code';
		$inactive_token_key = $is_test_mode ? 'merchant_token' : 'sandbox_merchant_token';

		$active_code    = $this->get_posted_config_value( $active_code_key );
		$active_token   = $this->get_posted_config_value( $active_token_key );
		$inactive_code  = $this->get_posted_config_value( $inactive_code_key );
		$inactive_token = $this->get_posted_config_value( $inactive_token_key );

		$this->plugin->update_gateway_setting( $active_code_key, $active_code );
		$this->plugin->update_gateway_setting( $active_token_key, $active_token );
		$this->plugin->update_gateway_setting( $inactive_code_key, $inactive_code );
		$this->plugin->update_gateway_setting( $inactive_token_key, $inactive_token );

		if ( empty( $active_code ) || empty( $active_token ) ) {
			WC_Admin_Settings::add_error(
				__( 'Merchant Code and Payment Code are required.', 'payoneer-checkout' )
			);
		}

		if ( null !== $old_inactive_code && $old_inactive_code !== $inactive_code ) {
			$this->plugin->update_validated_credentials( false, ! $is_test_mode );
		} elseif ( null !== $old_inactive_token && $old_inactive_token !== $inactive_token ) {
			$this->plugin->update_validated_credentials( false, ! $is_test_mode );
		}

		$response = $this->plugin->api()->get_divisions();

		if ( $response['success'] && ! empty( $response['body'] ) ) {
			$this->plugin->update_validated_credentials( true, $is_test_mode );

			$divisions = $response['body']['divisions'] ?? $response['body'] ?? array();
			if ( ! is_array( $divisions ) ) {
				$divisions = array();
			}

			$sanitized = array();
			foreach ( $divisions as $division ) {
				if ( ! is_array( $division ) ) {
					continue;
				}
				$sanitized[] = array(
					'name'        => sanitize_text_field( $division['name'] ?? '' ),
					'displayName' => sanitize_text_field( $division['displayName'] ?? '' ),
				);
			}
			$this->plugin->update_divisions( $sanitized, $is_test_mode );

			// Save both division selections, validated against known divisions.
			$known_live_names = array_column( $this->plugin->get_divisions(), 'name' );
			$known_test_names = array_column( $this->plugin->get_divisions( true ), 'name' );

			$posted_live_store = $this->get_posted_config_value( 'store_code' );
			$posted_test_store = $this->get_posted_config_value( 'sandbox_store_code' );

			if ( in_array( $posted_live_store, $known_live_names, true ) ) {
				$this->plugin->update_gateway_setting( 'store_code', $posted_live_store );
			}
			if ( in_array( $posted_test_store, $known_test_names, true ) ) {
				$this->plugin->update_gateway_setting( 'sandbox_store_code', $posted_test_store );
			}

			// Auto-select when only one division is available for the active mode.
			if ( count( $sanitized ) === 1 ) {
				$default_division = reset( $sanitized );
				if ( ! empty( $default_division['name'] ) ) {
					$store_key = $is_test_mode ? 'sandbox_store_code' : 'store_code';
					$this->plugin->update_gateway_setting( $store_key, $default_division['name'] );
				}
			}

			$this->plugin->logger()->log( 'API credentials validated successfully (' . count( $sanitized ) . ' divisions found)' );
			return;
		}

		$this->plugin->logger()->log( 'API credential validation failed: ' . ( $response['error'] ?? 'Unknown error' ), 'error' );

		WC_Admin_Settings::add_error(
			__( 'Failed to validate API credentials. Please verify your credentials and save again.', 'payoneer-checkout' )
		);
		$this->plugin->update_validated_credentials( false, $is_test_mode );
		$this->plugin->update_divisions( array(), $is_test_mode );
	}


	/**
	 * Render payment fields on the checkout form.
	 *
	 * @return void
	 */
	public function payment_fields() {

		if ( $this->description ) {
			echo wp_kses_post( $this->description );
		}

		if ( $this->plugin->is_hosted_integration() ) {
			return;
		}

		printf(
			'<div class="payoneer-dropin-mount" data-dropin-name="cards" data-long-id="%s"></div>',
			esc_attr( self::get_list_id() )
		);
	}


	/**
	 * Whether the cached LIST session is still usable.
	 *
	 * @return bool
	 */
	public static function is_list_valid() {
		$long_id = Utils::get_session_key( 'list_long_id' );

		if ( empty( $long_id ) ) {
			return false;
		}

		$expires_at = (int) Utils::get_session_key( 'list_expires_at' );

		return ! $expires_at || time() < $expires_at;
	}


	/**
	 * Return a valid LIST session longId, creating one if necessary.
	 *
	 * @return string
	 */
	public static function get_list_id() {
		if ( self::is_list_valid() ) {
			return Utils::get_session_key( 'list_long_id' );
		}

		Utils::cleanup_cached_list();

		$long_id = self::recover_long_id_from_draft_order();

		if ( ! empty( $long_id ) ) {
			return $long_id;
		}

		return self::create_list_session();
	}


	/**
	 * Create a new LIST session and store it in the WC session.
	 *
	 * @param \WC_Order|null $order         Optional order for pay-for-order context.
	 * @param bool           $save_to_order Whether to save the new longId to the order meta.
	 *
	 * @return string
	 */
	public static function create_list_session( $order = null, $save_to_order = false ) {
		$plugin = Plugin::instance();

		if ( null === $order ) {
			$order = Utils::get_current_order();
		}

		$payload = null;
		if ( $order ) {
			$payload = PayloadHelper::build_list_payload_from_order( $order, 'EMBEDDED' );
		} else {
			$payload = PayloadHelper::build_list_payload();
		}

		if ( empty( $payload ) ) {
			return '';
		}

		$response = $plugin->api()->create_list( $payload );

		if ( ! $response['success'] ) {
			$plugin->logger()->log( 'LIST creation failed: ' . ( $response['error'] ?? 'Unknown' ), 'error' );
			return '';
		}

		$long_id        = $response['body']['identification']['longId'] ?? '';
		$transaction_id = $response['body']['identification']['transactionId'] ?? '';
		$expires_at     = isset( $response['body']['expiresAt'] ) ? strtotime( $response['body']['expiresAt'] ) : ( time() + 30 * MINUTE_IN_SECONDS );

		if ( ! empty( $long_id ) ) {
			Utils::set_session_key( 'list_long_id', $long_id );
			Utils::set_session_key( 'list_transaction_id', $transaction_id );
			Utils::set_session_key( 'list_expires_at', $expires_at ? $expires_at : 0 );
		}

		$applicable = Plugin::extract_applicable_networks( $response['body'], $order );

		if ( WC()->cart ) {
			Utils::set_session_key( 'list_payload_hash', Plugin::instance()->compute_list_payload_hash() );
		}

		$plugin->logger()->log(
			sprintf(
				'LIST session created (longId: %s, applicable: %s)',
				$long_id,
				implode( ', ', $applicable )
			)
		);

		if ( $save_to_order && $order ) {
			$order->update_meta_data( '_payoneer-long-id', $long_id );
			$order->save_meta_data();
		}

		return $long_id;
	}


	/**
	 * Process the payment for a given order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the woocommerce-process-checkout nonce before dispatching process_payment().
		$fallback_to_hosted = wc_clean( wp_unslash( $_POST['payoneer_use_hosted'] ?? '' ) );
		$is_hosted          = $this->plugin->is_hosted_integration() || '1' === $fallback_to_hosted;

		if ( ! empty( $order->get_meta( '_payoneer-long-id', true ) ) ) {
			$retry_result = $this->resolve_existing_list( $order, $is_hosted );
			if ( null !== $retry_result ) {
				if ( ! $order->is_paid() ) {
					$pending_note = self::build_payment_note(
						__( 'Payment pending confirmation from Payoneer.', 'payoneer-checkout' ),
						array( 'transactionId' => $order->get_transaction_id() )
					);
					OrderHelper::put_order_awaiting_payment( $order, $pending_note );
				}

				return $retry_result;
			}
		}

		if ( $is_hosted ) {
			return $this->process_hosted_payment( $order );
		}

		return $this->process_embedded_payment( $order );
	}


	/**
	 * Reconcile any existing LIST attached to a pending order before creating a new one.
	 *
	 * @param \WC_Order $order     The order being submitted.
	 * @param bool      $is_hosted Whether the request is in hosted mode.
	 *
	 * @return array|null
	 */
	private function resolve_existing_list( $order, $is_hosted ) {
		$existing_long_id = $order->get_meta( '_payoneer-long-id', true );

		if ( empty( $existing_long_id ) ) {
			return null;
		}

		$response = Plugin::fetch_list_state( $existing_long_id );

		if ( empty( $response ) || empty( $response['success'] ) ) {
			$code = isset( $response['code'] ) ? (int) $response['code'] : 0;

			// Only a definitive 404 (LIST gone) may reset.
			if ( 404 === $code ) {
				OrderHelper::reset_payment_meta( $order );
				return null;
			}

			wc_add_notice( __( 'There was an error processing your payment. Please try again.', 'payoneer-checkout' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$body        = $response['body'] ?? array();
		$status_code = Plugin::derive_list_status_code( $body );

		if ( 'charged' === $status_code ) {
			OrderHelper::unlock_order_payment( $order );

			$identification = $body['identification'] ?? array();
			$this->apply_payment_to_order(
				$order,
				array(
					'statusCode'    => 'charged',
					'reasonCode'    => 'debited',
					'transactionId' => sanitize_text_field( (string) ( $identification['transactionId'] ?? '' ) ),
				)
			);

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		if ( 'ended' === $status_code ) {
			OrderHelper::unlock_order_payment( $order );

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		if ( $this->is_terminal_list_state( $status_code ) ) {
			OrderHelper::reset_payment_meta( $order );
			return null;
		}

		if ( $is_hosted ) {
			OrderHelper::lock_order_payment( $order );

			$redirect_url = esc_url_raw( $body['redirect']['url'] ?? '' );

			if ( ! empty( $redirect_url ) ) {
				Plugin::instance()->logger()->log(
					sprintf(
						'Resuming hosted LIST %s for order #%d (state: %s) instead of minting a new one.',
						$existing_long_id,
						$order->get_id(),
						$status_code
					)
				);

				return array(
					'result'   => 'success',
					'redirect' => $redirect_url,
				);
			}

			Plugin::instance()->logger()->log(
				sprintf(
					'Refusing parallel hosted payment for order #%d: LIST %s is in non-terminal state "%s" with no resumable redirect URL.',
					$order->get_id(),
					$existing_long_id,
					$status_code
				),
				'error'
			);

			wc_add_notice(
				__( 'A payment for this order is already in progress. Please wait for it to complete, or contact support if the issue persists.', 'payoneer-checkout' ),
				'error'
			);

			return array( 'result' => 'failure' );
		}

		if ( 'listed' === $status_code ) {
			OrderHelper::unlock_order_payment( $order );

			return null;
		}

		if ( ! OrderHelper::is_order_payment_locked( $order ) ) {
			OrderHelper::unlock_order_payment( $order );

			Plugin::instance()->logger()->log(
				sprintf(
					'Allowing retry on LIST %s for order #%d after payment lock expired (state: %s).',
					$existing_long_id,
					$order->get_id(),
					$status_code
				),
				'warning'
			);

			return null;
		}

		OrderHelper::lock_order_payment( $order );

		Plugin::instance()->logger()->log(
			sprintf(
				'Refusing parallel payment attempt for order #%d: LIST %s is in non-terminal state "%s".',
				$order->get_id(),
				$existing_long_id,
				$status_code
			),
			'error'
		);

		wc_add_notice(
			__( 'A payment for this order is already in progress. Please wait for it to complete, or contact support if the issue persists.', 'payoneer-checkout' ),
			'error'
		);

		return array( 'result' => 'failure' );
	}


	/**
	 * Whether the given LIST status.code is a terminal non-success state.
	 *
	 * @param string $status_code LIST status.code value.
	 *
	 * @return bool
	 */
	private function is_terminal_list_state( $status_code ) {
		return in_array(
			$status_code,
			array( 'aborted', 'expired', 'closed', 'failed', 'canceled', 'declined', 'rejected' ),
			true
		);
	}


	/**
	 * Process a refund for a given order.
	 *
	 * @param int        $order_id WooCommerce order ID.
	 * @param float|null $amount   Refund amount.
	 * @param string     $reason   Refund reason.
	 *
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {

		$order    = wc_get_order( $order_id );
		$response = null;

		try {
			if ( ! $order ) {
				throw new \Exception( __( 'Order not found.', 'payoneer-checkout' ) );
			}

			$charge_long_id = $order->get_meta( '_payoneer_payment_charge_id', true );

			if ( empty( $charge_long_id ) ) {
				throw new \Exception( __( 'Cannot process refund: missing charge reference. The payment may not have been confirmed yet.', 'payoneer-checkout' ) );
			}

			foreach ( $order->get_refunds() as $existing_refund ) {
				if ( $existing_refund->get_meta( '_payoneer_awaiting_refund_confirmation', true ) ) {
					throw new \Exception( __( 'A previous refund is still awaiting confirmation from Payoneer. Please wait for it to be confirmed before issuing another refund.', 'payoneer-checkout' ) );
				}
			}

			if ( ! $amount || $amount <= 0 ) {
				throw new \Exception( __( 'Invalid refund amount.', 'payoneer-checkout' ) );
			}

			$currency = $order->get_currency();

			$pending_txn_id = $order->get_meta( '_payoneer_pending_refund_txn_id', true );
			if ( empty( $pending_txn_id ) ) {
				$pending_txn_id = wp_generate_uuid4();
				$order->update_meta_data( '_payoneer_pending_refund_txn_id', $pending_txn_id );
			}

			$payload = array(
				'transactionId' => $pending_txn_id,
				'payment'       => array(
					'reference' => $order->get_order_number(),
					'amount'    => (float) $amount,
					'currency'  => $currency,
				),
			);

			$order->update_meta_data( '_payoneer_status_before_refund', $order->get_status() );
			$order->save();

			$response = $this->plugin->api()->payout( $charge_long_id, $payload );

			if ( ! $response['success'] || empty( $response['body'] ) ) {
				throw new \Exception( $response['error'] ?? __( 'Refund request failed.', 'payoneer-checkout' ) );
			}

			$this->last_refund_txn_id       = $pending_txn_id;
			$this->last_refund_long_id      = sanitize_text_field( $response['body']['identification']['longId'] ?? '' );
			$response_status_code           = sanitize_text_field( $response['body']['status']['code'] ?? '' );
			$this->last_refund_sync_success = in_array( $response_status_code, array( 'paid_out', 'paid_out_partial' ), true );

			$heading = sprintf(
				/* translators: %s: refund amount */
				__( 'Refunded %s processed, pending confirmation from Payoneer.', 'payoneer-checkout' ),
				wc_price( $amount, array( 'currency' => $currency ) )
			);
			$note = self::build_payment_note( $heading, array( 'transactionId' => $pending_txn_id ) );

			if ( ! empty( $reason ) ) {
				/* translators: %s: refund reason */
				$note .= ' ' . sprintf( __( 'Reason: %s', 'payoneer-checkout' ), $reason );
			}

			$order->add_order_note( $note );
			$order->save();

			return true;
		} catch ( \Exception $e ) {
			$this->plugin->logger()->log(
				sprintf( 'Refund failed for order #%s: %s', $order ? $order->get_order_number() : $order_id, $e->getMessage() ),
				'error'
			);

			$http_code = isset( $response['code'] ) ? (int) $response['code'] : 0;
			if ( $order && $http_code >= 400 && $http_code < 500 ) {
				$order->delete_meta_data( '_payoneer_pending_refund_txn_id' );
				$order->delete_meta_data( '_payoneer_status_before_refund' );
				$order->save();
			}

			return new \WP_Error( 'payoneer_refund_error', $e->getMessage() );
		}
	}


	/**
	 * Link the payout transaction ID to the WC refund object after creation.
	 *
	 * @param int $order_id  WooCommerce order ID.
	 * @param int $refund_id WooCommerce refund ID.
	 */
	public function on_order_refunded( $order_id, $refund_id ) {
		if ( empty( $this->last_refund_txn_id ) && empty( $this->last_refund_long_id ) ) {
			return;
		}

		$refund = new \WC_Order_Refund( $refund_id );
		if ( ! empty( $this->last_refund_txn_id ) ) {
			$refund->update_meta_data( '_payoneer_refund_txn_id', $this->last_refund_txn_id );
		}
		if ( ! empty( $this->last_refund_long_id ) ) {
			$refund->update_meta_data( '_payoneer_payout_id', $this->last_refund_long_id );
		}
		if ( ! $this->last_refund_sync_success ) {
			$refund->update_meta_data( '_payoneer_awaiting_refund_confirmation', '1' );
		}
		$refund->save();

		// Clear the pending refund data if the refund is already confirmed.
		if ( $this->last_refund_sync_success ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete_meta_data( '_payoneer_pending_refund_txn_id' );
				$order->delete_meta_data( '_payoneer_status_before_refund' );
				$order->save();
			}
		}

		$this->last_refund_txn_id       = '';
		$this->last_refund_long_id      = '';
		$this->last_refund_sync_success = false;
	}


	/**
	 * Block the further refunds while there is a refund awaiting confirmation.
	 *
	 * @param \WC_Order|false $order The order.
	 *
	 * @return bool
	 */
	public function can_refund_order( $order ) {
		if ( ! $order || ! $this->supports( 'refunds' ) ) {
			return false;
		}

		if ( $order->get_meta( '_payoneer_pending_refund_txn_id', true ) ) {
			return false;
		}

		foreach ( $order->get_refunds() as $existing_refund ) {
			if ( $existing_refund->get_meta( '_payoneer_awaiting_refund_confirmation', true ) ) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Get the order's webhook security token, generating a stable one on first use.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 *
	 * @return string
	 */
	private function get_or_create_security_token( $order ) {
		$token = (string) $order->get_meta( '_payoneer_security_header_value', true );

		return ! empty( $token ) ? $token : wp_generate_password( 32, false );
	}


	/**
	 * Process a hosted payment by creating a HOSTED LIST and redirecting.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 *
	 * @return array
	 */
	private function process_hosted_payment( $order ) {

		$security_token = $this->get_or_create_security_token( $order );

		$payload  = PayloadHelper::build_list_payload_from_order( $order, 'HOSTED', $security_token );
		$response = $this->plugin->api()->create_list( $payload );

		if ( ! $response['success'] ) {
			$this->plugin->logger()->log( 'Hosted LIST creation failed: ' . ( $response['error'] ?? 'Unknown' ), 'error' );
			wc_add_notice( __( 'Payment processing failed. Please try again.', 'payoneer-checkout' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$long_id        = sanitize_text_field( $response['body']['identification']['longId'] ?? '' );
		$transaction_id = sanitize_text_field( $response['body']['identification']['transactionId'] ?? '' );
		$redirect_url   = esc_url( $response['body']['redirect']['url'] ?? '' );
		$expires_at     = isset( $response['body']['expiresAt'] ) ? (int) strtotime( $response['body']['expiresAt'] ) : ( time() + 30 * MINUTE_IN_SECONDS );

		if ( empty( $redirect_url ) ) {
			$this->plugin->logger()->log( 'Hosted payment missing redirect URL for order #' . $order->get_order_number(), 'error' );
			wc_add_notice( __( 'Payment processing failed. Please try again.', 'payoneer-checkout' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$this->save_payment_meta( $order, $long_id, $transaction_id, $security_token, $expires_at );

		$pending_note = self::build_payment_note(
			__( 'Payment pending confirmation from Payoneer.', 'payoneer-checkout' ),
			array( 'transactionId' => $transaction_id )
		);
		OrderHelper::put_order_awaiting_payment( $order, $pending_note );

		Utils::cleanup_cached_list();

		$this->plugin->logger()->log(
			sprintf(
				'Hosted payment redirect for order #%s [longId=%s, transactionId=%s]',
				$order->get_order_number(),
				$long_id,
				$transaction_id
			)
		);

		return array(
			'result'   => 'success',
			'redirect' => $redirect_url,
		);
	}


	/**
	 * Process an embedded payment by updating the LIST with real order data.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 *
	 * @return array
	 */
	private function process_embedded_payment( $order ) {
		return $this->process_network_embedded_payment( $order );
	}


	/**
	 * Process an embedded payment on behalf of a network gateway.
	 *
	 * @param \WC_Order $order        The WooCommerce order.
	 * @param string    $network_code Network code (empty for the main gateway).
	 *
	 * @return array
	 */
	public function process_network_embedded_payment( $order, $network_code = '' ) {
		if ( ! headers_sent() && WC()->session && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
			WC()->session->set_customer_session_cookie( true );
		}

		$order_long_id   = $order->get_meta( '_payoneer-long-id', true );
		$session_long_id = Utils::get_session_key( 'list_long_id' );
		$long_id         = ! empty( $session_long_id ) ? $session_long_id : $order_long_id;

		if ( empty( $long_id ) ) {
			$new_long_id = $this->plugin->reset_session();
			return $this->session_expired_failure( $new_long_id );
		}

		$security_token = $this->get_or_create_security_token( $order );

		// Update the LIST with real order data.
		$transaction_id = Utils::get_session_key( 'list_transaction_id' );
		if ( empty( $session_long_id ) && ! empty( $order_long_id ) ) {
			$transaction_id = (string) $order->get_transaction_id();
		}

		$payload  = PayloadHelper::build_update_payload_from_order( $order, $transaction_id, $security_token );
		$response = $this->plugin->api()->update_list( $long_id, $payload );

		if ( ! $response['success'] ) {
			$interaction_code = $response['body']['interaction']['code'] ?? '';

			if ( 'ABORT' === $interaction_code ) {
				$list_expires_at = (int) $order->get_meta( '_payoneer_list_expires_at', true );
				if ( ! $list_expires_at ) {
					$list_expires_at = (int) Utils::get_session_key( 'list_expires_at' );
				}
				if ( $list_expires_at && time() >= $list_expires_at ) {
					$this->plugin->logger()->log(
						sprintf( 'LIST %s for order #%d returned ABORT on update after the session expired; treating as expired session.', $long_id, $order->get_id() ),
						'info'
					);
					return $this->session_expired_failure( $this->plugin->reset_session() );
				}

				$this->plugin->logger()->log(
					sprintf(
						'LIST %s for order #%d returned ABORT on update (likely pending/functioning at Payoneer). Keeping the LIST intact and refusing the parallel attempt so it can settle.',
						$long_id,
						$order->get_id()
					),
					'warning'
				);
				wc_add_notice( __( 'A payment for this order is already in progress. Please wait for it to complete, or contact support if the issue persists.', 'payoneer-checkout' ), 'error' );
				return array( 'result' => 'failure' );
			}

			if ( 'RELOAD' === $interaction_code ) {
				$this->plugin->logger()->log(
					sprintf( 'LIST update for order #%d returned RELOAD interaction (LIST is busy). Surfacing failure to the customer without resetting the session.', $order->get_id() ),
					'info'
				);
				wc_add_notice( __( 'A payment for this order is already in progress. Please wait for it to complete, or contact support if the issue persists.', 'payoneer-checkout' ), 'error' );
				return array( 'result' => 'failure' );
			}

			$this->plugin->logger()->log( 'LIST update failed: ' . ( $response['error'] ?? 'Unknown' ), 'error' );
			wc_add_notice( __( 'Payment processing failed. Please try again.', 'payoneer-checkout' ), 'error' );
			return array( 'result' => 'failure' );
		}

		Plugin::extract_applicable_networks( $response['body'], $order );

		$session_expires_at = (int) Utils::get_session_key( 'list_expires_at' );
		$this->save_payment_meta( $order, $long_id, $transaction_id, $security_token, $session_expires_at );

		$pending_note = self::build_payment_note(
			__( 'Payment pending confirmation from Payoneer.', 'payoneer-checkout' ),
			array( 'transactionId' => $transaction_id )
		);
		OrderHelper::put_order_awaiting_payment( $order, $pending_note );

		// Release the cached LIST for the order for network gateways.
		if ( ! empty( $network_code ) && ! $this->plugin->settings()->is_cc_network( $network_code ) ) {
			Utils::cleanup_cached_list();
			Utils::detach_pending_order();
		}

		if ( ! empty( $network_code ) ) {
			$order->update_meta_data( '_payoneer_network_code', $network_code );
			$order->save();
		}

		if ( '' === (string) $order->get_payment_method_title() ) {
			$order->set_payment_method_title( $this->get_title() );
			$order->save();
		}

		$this->plugin->logger()->log(
			sprintf(
				'LIST updated for order #%s [longId=%s, transactionId=%s]',
				$order->get_order_number(),
				$long_id,
				$transaction_id
			)
		);

		return array(
			'result'            => 'success',
			'redirect'          => $this->get_return_url( $order ),
			'payoneer_wc_nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
		);
	}


	/**
	 * Build a session-expired failure response.
	 *
	 * @param string $new_long_id The freshly-created LIST longId from reset_session().
	 *
	 * @return array
	 */
	private function session_expired_failure( $new_long_id, $refresh = true ) {
		$result = array( 'result' => 'failure' );
		if ( $refresh ) {
			$result['refresh'] = true;
		}

		if ( self::$blocks_payment_result instanceof \Automattic\WooCommerce\StoreApi\Payments\PaymentResult ) {
			self::$blocks_payment_result->set_payment_details(
				array_merge(
					self::$blocks_payment_result->payment_details,
					array(
						'payoneer_long_id'         => (string) $new_long_id,
						'payoneer_session_reset'   => '1',
						'payoneer_session_message' => __( 'Payment session expired. Please try again.', 'payoneer-checkout' ),
					)
				)
			);
			self::$blocks_payment_result->set_status( 'failure' );
			return $result;
		}

		WC()->session->set( 'refresh_totals', true );
		if ( $refresh ) {
			WC()->session->set( 'payoneer_pay_for_order_refresh', true );
		}
		wc_add_notice( __( 'Payment session expired. Please try again.', 'payoneer-checkout' ), 'error' );
		return $result;
	}


	/**
	 * Store Payoneer LIST identifiers on the order.
	 *
	 * @param \WC_Order $order          The WooCommerce order.
	 * @param string    $long_id        Payoneer LIST session longId.
	 * @param string    $transaction_id Payoneer transaction ID.
	 * @param string    $security_token Security token for webhook verification.
	 * @param int       $expires_at     LIST session expiry (Unix timestamp).
	 */
	private function save_payment_meta( $order, $long_id, $transaction_id, $security_token, $expires_at = 0 ) {
		if ( ! empty( $security_token ) ) {
			$order->update_meta_data( '_payoneer_security_header_value', $security_token );
		}

		if ( ! empty( $long_id ) ) {
			$order->update_meta_data( '_payoneer-long-id', $long_id );

			$tab_token = Utils::request_tab_token();
			if ( '' !== $tab_token ) {
				$order->update_meta_data( '_payoneer_payment_tab_token', $tab_token );
			}
		}
		if ( ! empty( $transaction_id ) ) {
			$order->set_transaction_id( $transaction_id );
			$order->update_meta_data( '_payoneer_payment_transaction_id', $transaction_id );
		}
		if ( $expires_at > 0 ) {
			$order->update_meta_data( '_payoneer_list_expires_at', $expires_at );
		}

		$merchant_id = $this->plugin->api_username();
		if ( '' !== $merchant_id ) {
			$order->update_meta_data( '_payoneer_merchant_id', $merchant_id );
		}

		$order->save();

		if ( ! empty( $long_id ) ) {
			Utils::set_session_key( 'list_pending_order_id', $order->get_id() );
			OrderHelper::lock_order_payment( $order );
		}
	}


	/**
	 * Register hooks to return JSON for embedded pay-for-order submissions.
	 */
	public static function setup_pay_for_order_hooks() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by WC_Form_Handler::pay_action.
		$payment_method_id = wc_clean( wp_unslash( $_POST['payment_method'] ?? '' ) );

		if ( ! self::is_payoneer_payment_method( $payment_method_id ) ) {
			return;
		}

		if ( Plugin::instance()->is_hosted_integration() ) {
			return;
		}

		add_filter( 'woocommerce_payment_successful_result', array( __CLASS__, 'send_pay_for_order_success' ) );
		add_action( 'woocommerce_after_pay_action', array( __CLASS__, 'send_pay_for_order_failure' ) );
	}


	/**
	 * Return JSON on successful pay-for-order instead of redirecting.
	 *
	 * @param array $result Payment result from process_payment().
	 */
	public static function send_pay_for_order_success( $result ) {
		wp_send_json_success( $result );
	}


	/**
	 * Return a JSON failure when a pay-for-order submission fails.
	 */
	public static function send_pay_for_order_failure() {
		$refresh = false;
		if ( WC()->session && WC()->session->get( 'payoneer_pay_for_order_refresh' ) ) {
			$refresh = true;
			WC()->session->set( 'payoneer_pay_for_order_refresh', null );
		}

		wp_send_json_error(
			array(
				'messages' => wc_print_notices( true ),
				'refresh'  => $refresh,
			)
		);
	}


	/**
	 * Attempt to recover a LIST longId from a draft or pending order.
	 *
	 * @return string The recovered longId, or empty string if not found.
	 */
	private static function recover_long_id_from_draft_order() {
		$draft_order = Utils::get_current_pending_order();

		if ( ! $draft_order instanceof \WC_Order ) {
			return '';
		}

		// Only recover from orders that haven't been finalized.
		if ( ! $draft_order->has_status( array( 'draft', 'checkout-draft', 'pending' ) ) ) {
			return '';
		}

		$draft_long_id = $draft_order->get_meta( '_payoneer-long-id', true );
		if ( empty( $draft_long_id ) ) {
			return '';
		}

		$draft_expires_at = (int) $draft_order->get_meta( '_payoneer_list_expires_at', true );
		if ( $draft_expires_at <= 0 || $draft_expires_at <= time() ) {
			return '';
		}

		// Only restore if cart hash matches (same checkout, not a changed cart).
		$cart      = WC()->cart;
		$cart_hash = $cart ? $cart->get_cart_hash() : '';

		if ( ! empty( $cart_hash ) && ! $draft_order->has_cart_hash( $cart_hash ) ) {
			return '';
		}

		// Restore to session.
		Utils::set_session_key( 'list_long_id', $draft_long_id );
		Utils::set_session_key( 'list_expires_at', $draft_expires_at );

		$transaction_id = $draft_order->get_transaction_id();

		if ( $transaction_id ) {
			Utils::set_session_key( 'list_transaction_id', $transaction_id );
		}

		Plugin::instance()->logger()->log(
			sprintf(
				'Recovered LIST session from order #%d [longId=%s]',
				$draft_order->get_id(),
				$draft_long_id
			)
		);

		return $draft_long_id;
	}


	/**
	 * Determine gateway availability on the checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( ! $this->plugin->get_validated_credentials() ) {
			return false;
		}

		if ( $this->plugin->is_embedded_integration() && ! $this->plugin->is_card_network_applicable() ) {
			return false;
		}

		return true;
	}


	/**
	 * Whether the gateway needs setup; drives WC Payments Hub "Action needed" badge.
	 */
	public function needs_setup() {
		return ! $this->plugin->get_validated_credentials();
	}


	/**
	 * Alias `merchant_code` to the sandbox key while in test mode so WooCommerce doesn't flag the gateway as needing setup.
	 *
	 * @param string $key         Setting key.
	 * @param mixed  $empty_value Default when the option is missing.
	 *
	 * @return mixed
	 */
	public function get_option( $key, $empty_value = null ) {
		if ( 'merchant_code' === $key && $this->plugin->is_test_mode() ) {
			$sandbox = parent::get_option( 'sandbox_merchant_code', $empty_value );
			if ( ! empty( $sandbox ) ) {
				return $sandbox;
			}
		}
		return parent::get_option( $key, $empty_value );
	}


	/**
	 * Read a posted configuration value, falling back to the stored option.
	 *
	 * @param string $key Setting key (without the gateway prefix).
	 *
	 * @return mixed
	 */
	private function get_posted_config_value( $key ) {
		$field_key = 'woocommerce_' . self::ID . '_' . $key;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the nonce before calling process_admin_options().
		return isset( $_POST[ $field_key ] ) ? wc_clean( wp_unslash( $_POST[ $field_key ] ) ) : $this->get_option( $key );
	}


	/**
	 * Apply payment data directly to an order.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @param array     $data  Payment result data.
	 */
	public function apply_payment_to_order( $order, $data ) {
		$status_code    = sanitize_text_field( $data['statusCode'] ?? '' );
		$transaction_id = sanitize_text_field( $data['transactionId'] ?? '' );

		$this->apply_payment_status( $order, $status_code, $transaction_id, $data );

		do_action( 'payoneer_checkout_payment_processed', $order, $data );
	}


	/**
	 * Map a Payoneer status code to the appropriate WooCommerce order action.
	 *
	 * @param \WC_Order $order          The WooCommerce order.
	 * @param string    $status_code    Payoneer status code.
	 * @param string    $transaction_id Payoneer transaction ID.
	 * @param array     $data           Full payment result data.
	 */
	private function apply_payment_status( $order, $status_code, $transaction_id, $data ) {
		$reason_code   = sanitize_text_field( $data['reasonCode'] ?? ( $data['reason'] ?? '' ) );
		$incoming_long = sanitize_text_field( $data['longId'] ?? '' );

		if ( $this->is_parent_charge_refund_event( $order, $incoming_long, $reason_code ) ) {
			$this->plugin->logger()->log(
				sprintf(
					'Parent-charge refund event for order #%d ignored [reasonCode=%s, longId=%s]',
					$order->get_id(),
					$reason_code,
					$incoming_long
				),
				'debug'
			);
			return;
		}

		switch ( $status_code ) {
			case 'charged':
				$this->handle_charge_webhook( $order, $transaction_id, $data );
				break;

			case 'paid_out':
			case 'paid_out_partial':
				$this->handle_refund_webhook( $order, $data );
				break;

			case 'failed':
			case 'declined':
			case 'rejected':
			case 'aborted':
			case 'canceled':
				$this->handle_failure_webhook( $order, $data );
				break;

			case 'preauthorized':
				$this->handle_preauthorized_webhook( $order, $data );
				break;

			case 'charged_back':
				$this->handle_chargeback_webhook( $order, $data );
				break;
		}
	}


	/**
	 * Handle a chargeback webhook (statusCode=charged_back).
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @param array     $data  Webhook payload data.
	 */
	private function handle_chargeback_webhook( $order, $data ) {
		$order->add_order_note(
			self::build_payment_note( __( 'Payoneer payment charged back.', 'payoneer-checkout' ), $data )
		);
		Plugin::flag_order( $order->get_id(), Plugin::CHARGEBACK_ORDERS_OPTION );
	}


	/**
	 * Build an order note from a payment result.
	 *
	 * @param string $heading Summary line.
	 * @param array  $data    Payment result data.
	 *
	 * @return string
	 */
	public static function build_payment_note( $heading, $data ) {
		$parts = array();

		if ( ! empty( $data['transactionId'] ) ) {
			/* translators: %s: transaction ID */
			$parts[] = sprintf( __( 'Transaction ID: %s', 'payoneer-checkout' ), sanitize_text_field( $data['transactionId'] ) );
		}

		if ( ! empty( $data['network'] ) ) {
			/* translators: %s: payment network name */
			$parts[] = sprintf( __( 'Network: %s', 'payoneer-checkout' ), sanitize_text_field( $data['network'] ) );
		}

		if ( empty( $parts ) ) {
			return $heading;
		}

		return $heading . ' (' . implode( ', ', $parts ) . ')';
	}


	/**
	 * Handle a charge webhook (statusCode=charged).
	 *
	 * @param \WC_Order $order          The WooCommerce order.
	 * @param string    $transaction_id Sanitized transactionId from the payload.
	 * @param array     $data           Webhook payload data.
	 */
	private function handle_charge_webhook( $order, $transaction_id, $data ) {
		$reason_code   = sanitize_text_field( $data['reasonCode'] ?? ( $data['reason'] ?? '' ) );
		$incoming_long = sanitize_text_field( $data['longId'] ?? '' );

		if ( $order->has_status( 'refunded' ) ) {
			$this->plugin->logger()->log(
				sprintf( 'Charge webhook for refunded order #%d ignored [longId=%s]', $order->get_id(), $incoming_long ),
				'warning'
			);
			return;
		}

		if ( $order->is_paid() ) {
			$this->persist_charge_long_id( $order, $incoming_long );
			$this->plugin->logger()->log(
				sprintf(
					'Charge webhook for already-paid order #%d skipped [reasonCode=%s, longId=%s]',
					$order->get_id(),
					$reason_code,
					$incoming_long
				),
				'info'
			);
			return;
		}

		if ( $order->has_status( 'failed' ) ) {
			$this->plugin->logger()->log(
				sprintf(
					'Out-of-order charged webhook arriving after failed status; order #%d upgraded to paid',
					$order->get_id()
				),
				'warning'
			);
		}

		$this->persist_charge_long_id( $order, $incoming_long, true );
		Plugin::persist_network_meta( $order, $data['network'] ?? '' );

		$order->add_order_note(
			self::build_payment_note( __( 'Payoneer payment complete.', 'payoneer-checkout' ), $data )
		);
		OrderHelper::clear_awaiting_webhook_flag( $order );
		$order->payment_complete( $transaction_id );
	}


	/**
	 * Handle a successful refund webhook (paid_out / paid_out_partial).
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @param array     $data  Webhook payload data.
	 */
	private function handle_refund_webhook( $order, $data ) {
		if ( $this->complete_legacy_refund_intent( $order, $data ) ) {
			return;
		}

		$long_id        = sanitize_text_field( $data['longId'] ?? '' );
		$transaction_id = sanitize_text_field( $data['transactionId'] ?? '' );

		$refund = $this->find_refund( $order, $long_id );

		if ( $refund ) {
			if ( $refund->get_meta( '_payoneer_awaiting_refund_confirmation', true ) ) {
				$refund->delete_meta_data( '_payoneer_awaiting_refund_confirmation' );
				$refund->save();
			}

			$order->delete_meta_data( '_payoneer_pending_refund_txn_id' );
			$order->delete_meta_data( '_payoneer_status_before_refund' );

			$amount   = isset( $data['amount'] ) ? (float) $data['amount'] : (float) $refund->get_amount();
			$currency = $order->get_currency();
			$heading  = sprintf(
				/* translators: %s: refund amount */
				__( 'Refunded %s confirmed.', 'payoneer-checkout' ),
				wc_price( $amount, array( 'currency' => $currency ) )
			);
			$order->add_order_note( self::build_payment_note( $heading, $data ) );
			$order->save();
			return;
		}

		if ( 'refunded' === $order->get_status() ) {
			$order->add_order_note(
				self::build_payment_note(
					__( 'Payoneer refund webhook received but order is already fully refunded; no WC refund created.', 'payoneer-checkout' ),
					$data
				)
			);
			$order->save();
			return;
		}

		$failed_history = (array) $order->get_meta( '_payoneer_failed_refund_long_ids', true );
		if ( '' !== $long_id && in_array( $long_id, $failed_history, true ) ) {
			$this->plugin->logger()->log(
				sprintf( 'Refund webhook for order #%d ignored: longId %s previously force-deleted by handle_refund_failure', $order->get_id(), $long_id ),
				'warning'
			);
			$order->add_order_note(
				self::build_payment_note(
					__( 'Payoneer refund webhook ignored: this refund had previously failed and been removed. No duplicate refund created.', 'payoneer-checkout' ),
					$data
				)
			);
			$order->save();
			return;
		}

		$amount = isset( $data['amount'] ) ? (float) $data['amount'] : 0.0;
		$reason = sanitize_text_field( $data['reference'] ?? '' );

		if ( $amount <= 0 ) {
			$this->plugin->logger()->log(
				sprintf(
					'Merchant-portal refund webhook for order #%d skipped: missing or invalid amount',
					$order->get_id()
				),
				'warning'
			);
			return;
		}

		$new_refund = wc_create_refund(
			array(
				'amount'   => $amount,
				'reason'   => $reason,
				'order_id' => $order->get_id(),
			)
		);

		if ( is_wp_error( $new_refund ) ) {
			$this->plugin->logger()->log(
				sprintf(
					'Failed to create WC refund for merchant-initiated Payoneer refund on order #%d: %s',
					$order->get_id(),
					$new_refund->get_error_message()
				),
				'error'
			);
			return;
		}

		if ( ! empty( $long_id ) ) {
			$new_refund->update_meta_data( '_payoneer_payout_id', $long_id );
		}
		if ( ! empty( $transaction_id ) ) {
			$new_refund->update_meta_data( '_payoneer_refund_txn_id', $transaction_id );
		}
		$new_refund->save();

		$order->delete_meta_data( '_payoneer_pending_refund_txn_id' );
		$order->delete_meta_data( '_payoneer_status_before_refund' );

		$order->add_order_note(
			self::build_payment_note( __( 'Payoneer refund received from merchant portal.', 'payoneer-checkout' ), $data )
		);
		$order->save();
	}


	/**
	 * Handle an async refund failure by deleting the WC refund and reverting order status.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @param array     $data  Webhook payload data.
	 */
	private function handle_refund_failure( $order, $data ) {
		$long_id        = sanitize_text_field( $data['longId'] ?? '' );
		$reason_code    = sanitize_text_field( $data['reason'] ?? '' );
		$transaction_id = sanitize_text_field( $data['transactionId'] ?? '' );
		$refund         = $this->find_refund_for_failure( $order, $long_id, $transaction_id );

		$status_before_refund = $order->get_meta( '_payoneer_status_before_refund', true );
		if ( empty( $status_before_refund ) ) {
			$status_before_refund = 'processing';
		}

		$failed_long_id = '';
		if ( $refund ) {
			$failed_long_id = (string) $refund->get_meta( '_payoneer_payout_id', true );
			$refund_id      = $refund->get_id();

			$refund->delete( true );

			do_action( 'woocommerce_refund_deleted', $refund_id, $order->get_id() );
		}

		$order = wc_get_order( $order->get_id() );

		if ( '' !== $failed_long_id ) {
			$history   = (array) $order->get_meta( '_payoneer_failed_refund_long_ids', true );
			$history[] = $failed_long_id;
			$order->update_meta_data( '_payoneer_failed_refund_long_ids', array_values( array_unique( array_filter( $history ) ) ) );
		}

		if ( ! empty( $reason_code ) ) {
			$order->update_meta_data( '_payoneer_refund_failure_reason', $reason_code );
		}
		$order->update_meta_data( '_payoneer_refund_failed_at', time() );
		$order->delete_meta_data( '_payoneer_pending_refund_txn_id' );
		$order->delete_meta_data( '_payoneer_status_before_refund' );

		$order->add_order_note(
			self::build_payment_note( __( 'Payoneer refund failed. The refund has been reversed.', 'payoneer-checkout' ), $data )
		);

		$order->update_status( $status_before_refund, __( 'Order status restored after refund failure.', 'payoneer-checkout' ) );

		Plugin::flag_order( $order->get_id(), Plugin::FAILED_REFUNDS_OPTION );
	}


	/**
	 * Handle a terminal failure webhook (failed / declined / rejected / aborted / canceled).
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @param array     $data  Webhook payload data.
	 */
	private function handle_failure_webhook( $order, $data ) {
		if ( $this->fail_legacy_refund_intent( $order, $data ) ) {
			return;
		}

		$long_id        = sanitize_text_field( $data['longId'] ?? '' );
		$transaction_id = sanitize_text_field( $data['transactionId'] ?? '' );

		if ( $this->find_refund_for_failure( $order, $long_id, $transaction_id ) ) {
			$this->handle_refund_failure( $order, $data );
			return;
		}

		$note = self::build_payment_note( __( 'Payoneer payment failed.', 'payoneer-checkout' ), $data );

		$result_info = sanitize_text_field( $data['resultInfo'] ?? '' );
		if ( '' !== $result_info ) {
			$note = rtrim( $note, '.' ) . '. ' . $result_info;
		}

		if ( $order->has_status( 'refunded' ) ) {
			$order->add_order_note(
				$note . "\n" . __( '(Ignored: order is already fully refunded.)', 'payoneer-checkout' )
			);
			$order->save();
			return;
		}

		if ( $order->is_paid() ) {
			$order->add_order_note(
				$note . "\n" . __( '(Ignored: order already completed.)', 'payoneer-checkout' )
			);
			$order->save();
			return;
		}

		OrderHelper::clear_awaiting_webhook_flag( $order );
		$this->add_note_or_update_status( $order, 'failed', $note );
	}


	/**
	 * Handle a preauthorized webhook by transitioning the order to on-hold.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @param array     $data  Webhook payload data.
	 */
	private function handle_preauthorized_webhook( $order, $data ) {
		if ( $order->has_status( 'refunded' ) ) {
			$this->plugin->logger()->log(
				sprintf( 'Preauthorized webhook for refunded order #%d ignored', $order->get_id() ),
				'warning'
			);
			$order->add_order_note(
				self::build_payment_note(
					__( 'Payoneer preauthorized webhook ignored: order is already fully refunded.', 'payoneer-checkout' ),
					$data
				)
			);
			$order->save();
			return;
		}

		$long_id = sanitize_text_field( $data['longId'] ?? '' );

		if ( $order->is_paid() ) {
			$this->persist_charge_long_id( $order, $long_id );
			$this->plugin->logger()->log(
				sprintf( 'Preauthorized webhook for already-paid order #%d ignored', $order->get_id() ),
				'info'
			);
			return;
		}

		$this->persist_charge_long_id( $order, $long_id, true );

		$note = self::build_payment_note( __( 'Payoneer payment preauthorized.', 'payoneer-checkout' ), $data );

		OrderHelper::clear_awaiting_webhook_flag( $order );
		$this->add_note_or_update_status( $order, 'on-hold', $note );
	}


	/**
	 * Persist the charge longId to the order.
	 *
	 * @param \WC_Order $order   The WooCommerce order.
	 * @param string    $long_id Incoming charge longId.
	 * @param bool      $force   When true, overwrite an existing value.
	 */
	private function persist_charge_long_id( $order, $long_id, $force = false ) {
		if ( empty( $long_id ) ) {
			return;
		}

		if ( ! $force && ! empty( $order->get_meta( '_payoneer_payment_charge_id', true ) ) ) {
			return;
		}

		$order->update_meta_data( '_payoneer_payment_charge_id', $long_id );
		$order->save_meta_data();
	}


	/**
	 * Add a note to the order if it already has the target status; otherwise transition to it.
	 *
	 * @param \WC_Order $order  The WooCommerce order.
	 * @param string    $status Target WooCommerce status slug (without the wc- prefix).
	 * @param string    $note   Note to attach.
	 */
	private function add_note_or_update_status( $order, $status, $note ) {
		if ( $order->has_status( $status ) ) {
			$order->add_order_note( $note );
			$order->save();
			return;
		}

		$order->update_status( $status, $note );
	}


	/**
	 * Capture the Blocks PaymentResult so process_payment can write paymentDetails back.
	 *
	 * @param \Automattic\WooCommerce\StoreApi\Payments\PaymentContext $context Payment context.
	 * @param \Automattic\WooCommerce\StoreApi\Payments\PaymentResult  $result  Payment result.
	 */
	public static function capture_blocks_payment_result( $context, $result ) {
		if ( self::ID === $context->payment_method ) {
			self::$blocks_payment_result = $result;
		}
	}


	/**
	 * Apply a successful refund webhook against a legacy refund intent.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @param array     $data  Webhook payload data.
	 *
	 * @return bool
	 */
	private function complete_legacy_refund_intent( $order, $data ) {
		list( $intent, $refund, $refund_id ) = $this->resolve_legacy_refund_intent( $order );
		if ( null === $intent ) {
			return false;
		}

		if ( ! $refund instanceof \WC_Order_Refund ) {
			$this->discard_orphaned_refund_intent( $order, $refund_id );
			return true;
		}

		$long_id = sanitize_text_field( $data['longId'] ?? '' );
		if ( '' !== $long_id ) {
			$refund->update_meta_data( '_payoneer_payout_id', $long_id );
			$refund->save();
		}

		$this->clear_legacy_refund_intent( $order );
		$order->add_order_note(
			self::build_payment_note( __( 'Payoneer refund confirmed.', 'payoneer-checkout' ), $data )
		);
		$order->save();

		return true;
	}


	/**
	 * Apply a failure webhook against a legacy refund intent.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @param array     $data  Webhook payload data.
	 *
	 * @return bool
	 */
	private function fail_legacy_refund_intent( $order, $data ) {
		list( $intent, $refund, $refund_id ) = $this->resolve_legacy_refund_intent( $order );
		if ( null === $intent ) {
			return false;
		}

		if ( ! $refund instanceof \WC_Order_Refund ) {
			$this->discard_orphaned_refund_intent( $order, $refund_id );
			return true;
		}

		$reason_code = sanitize_text_field( $data['reasonCode'] ?? ( $data['reason'] ?? '' ) );
		$refund->delete( true );

		if ( '' !== $reason_code ) {
			$order->update_meta_data( '_payoneer_refund_failure_reason', $reason_code );
		}
		$order->update_meta_data( '_payoneer_refund_failed_at', time() );
		$order->add_order_note(
			self::build_payment_note( __( 'Payoneer refund failed. The refund has been reversed.', 'payoneer-checkout' ), $data )
		);
		$order->save();

		Plugin::flag_order( $order->get_id(), Plugin::FAILED_REFUNDS_OPTION );

		return true;
	}


	/**
	 * Read the legacy refund intent and resolve its target WC refund.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 *
	 * @return array{0: array|null, 1: \WC_Order_Refund|null, 2: int}
	 */
	private function resolve_legacy_refund_intent( $order ) {
		$intent = $order->get_meta( '_payoneer-refund-intent', true );
		if ( ! is_array( $intent ) ) {
			return array( null, null, 0 );
		}

		$refund_id = (int) ( $intent['id'] ?? 0 );

		if ( $refund_id <= 0 ) {
			return array( null, null, 0 );
		}

		$refund = wc_get_order( $refund_id );
		if ( ! $refund instanceof \WC_Order_Refund || $refund->get_parent_id() !== $order->get_id() ) {
			return array( $intent, null, $refund_id );
		}

		return array( $intent, $refund, $refund_id );
	}


	/**
	 * Drop the legacy refund-intent + status meta from the order.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 *
	 * @return void
	 */
	private function clear_legacy_refund_intent( $order ) {
		$order->delete_meta_data( '_payoneer-refund-intent' );
		$order->delete_meta_data( '_payoneer-refund-status' );
	}


	/**
	 * Clear and log a refund intent whose WC refund target cannot be resolved.
	 *
	 * @param \WC_Order $order     The WooCommerce order.
	 * @param int       $refund_id Refund id that could not be resolved.
	 *
	 * @return void
	 */
	private function discard_orphaned_refund_intent( $order, $refund_id ) {
		$this->clear_legacy_refund_intent( $order );
		$order->save();
		$this->plugin->logger()->log(
			sprintf( 'Refund intent on order #%d references missing WC refund ID %d.', $order->get_id(), $refund_id ),
			'error'
		);
	}


	/**
	 * Find a WC refund by its Payoneer payout longId.
	 *
	 * @param \WC_Order $order   The parent order.
	 * @param string    $long_id The Payoneer refund payout longId.
	 *
	 * @return \WC_Order_Refund|null
	 */
	private function find_refund( $order, $long_id ) {
		if ( '' === $long_id ) {
			return null;
		}

		foreach ( $order->get_refunds() as $refund ) {
			if ( $refund->get_meta( '_payoneer_payout_id', true ) === $long_id ) {
				return $refund;
			}
		}

		return null;
	}


	/**
	 * Locate an existing WC refund matching either the failure-webhook longId or transactionId.
	 *
	 * @param \WC_Order $order          The order.
	 * @param string    $long_id        Webhook longId (may be the refund longId or the parent charge longId).
	 * @param string    $transaction_id Webhook transactionId (client-generated UUID stored on the refund).
	 *
	 * @return \WC_Order_Refund|null
	 */
	private function find_refund_for_failure( $order, $long_id, $transaction_id ) {
		if ( '' === $long_id && '' === $transaction_id ) {
			return null;
		}

		foreach ( $order->get_refunds() as $refund ) {
			if ( '' !== $long_id && $refund->get_meta( '_payoneer_payout_id', true ) === $long_id ) {
				return $refund;
			}
			if ( '' !== $transaction_id && $refund->get_meta( '_payoneer_refund_txn_id', true ) === $transaction_id ) {
				return $refund;
			}
		}

		return null;
	}


	/**
	 * Whether an incoming webhook is the parent-charge update fired alongside a refund payout.
	 *
	 * @param \WC_Order $order         The WooCommerce order.
	 * @param string    $incoming_long Sanitized longId from the webhook.
	 * @param string    $reason_code   Sanitized reasonCode from the webhook.
	 *
	 * @return bool
	 */
	private function is_parent_charge_refund_event( $order, $incoming_long, $reason_code ) {
		if ( '' === $incoming_long || '' === $reason_code ) {
			return false;
		}
		$stored_long = (string) $order->get_meta( '_payoneer_payment_charge_id', true );
		if ( '' === $stored_long || $stored_long !== $incoming_long ) {
			return false;
		}
		return in_array( $reason_code, array( 'paid_out_partial', 'paid_out' ), true );
	}
}
