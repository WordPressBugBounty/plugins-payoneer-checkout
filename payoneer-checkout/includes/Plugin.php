<?php
/**
 * Plugin class.
 */

namespace PayoneerCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Enums\OrderStatus;
use PayoneerCheckout\Admin\Notices;
use PayoneerCheckout\Admin\Settings;
use PayoneerCheckout\Gateways\WC_Payoneer_Gateway;
use PayoneerCheckout\Gateways\WC_Payoneer_Network_Gateway;
use PayoneerCheckout\Payment\OrderHelper;
use PayoneerCheckout\Payment\PayloadHelper;
use PayoneerCheckout\Utils;

/**
 * OOP plugin orchestrator responsible for registering gateways and services.
 */
final class Plugin {

	/**
	 * Option holding the list of order IDs with a failed refund pending merchant attention.
	 */
	const FAILED_REFUNDS_OPTION = 'payoneer_failed_refund_orders';

	/**
	 * Option holding the list of order IDs charged back and pending merchant attention.
	 */
	const CHARGEBACK_ORDERS_OPTION = 'payoneer_chargeback_orders';

	/** @var self|null */
	private static ?self $instance = null;

	/** @var Settings */
	private Settings $settings;

	/** @var Notices */
	private Notices $notices;

	/** @var API|null */
	private ?API $api = null;

	/** @var Logger|null */
	private ?Logger $logger = null;

	/** @var WC_Payoneer_Gateway|null */
	private ?WC_Payoneer_Gateway $gateway = null;


	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}


	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings = new Settings( $this );
		$this->notices  = new Notices( $this );

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
	}


	/**
	 * Get the settings instance.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->settings;
	}


	/**
	 * Get the API client instance (lazy-loaded).
	 *
	 * @return API
	 */
	public function api() {
		if ( $this->api === null ) {
			$this->api = new API( $this );
		}

		return $this->api;
	}


	/**
	 * Get the logger instance (lazy-loaded).
	 *
	 * @return Logger
	 */
	public function logger() {
		if ( $this->logger === null ) {
			$this->logger = new Logger( $this );
		}

		return $this->logger;
	}


	/**
	 * Get the gateway instance (lazy-loaded from WC registry).
	 *
	 * @return WC_Payoneer_Gateway|null
	 */
	public function gateway() {
		if ( $this->gateway === null ) {
			$gateways      = WC()->payment_gateways()->payment_gateways();
			$this->gateway = $gateways[ WC_Payoneer_Gateway::ID ] ?? null;
		}

		return $this->gateway;
	}


	/**
	 * Get the notices instance.
	 *
	 * @return Notices
	 */
	public function notices() {
		return $this->notices;
	}


	/**
	 * Initialize the plugin services.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_payoneer_checkout_validate_credentials', array( $this, 'ajax_validate_credentials' ) );
		add_action( 'wc_ajax_payoneer_checkout_reset_session', array( $this, 'ajax_reset_session' ) );
		add_action( 'set_logged_in_cookie', array( $this, 'inherit_logged_in_cookie_in_request' ) );
		add_action( 'woocommerce_before_pay_action', array( WC_Payoneer_Gateway::class, 'setup_pay_for_order_hooks' ) );
		add_filter( 'woocommerce_order_needs_payment', array( $this, 'maybe_suppress_payment_needed' ), 10, 2 );
		add_filter( 'woocommerce_cancel_unpaid_order', array( $this, 'maybe_skip_unpaid_cancel' ), 10, 2 );
		add_filter( 'woocommerce_order_details_status', array( $this, 'maybe_extend_order_status_text' ), 10, 2 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'maybe_suppress_order_actions' ), 10, 2 );
		add_action( 'add_meta_boxes', array( WC_Payoneer_Gateway::class, 'override_order_payment_method' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'sync_billing_customer_fields' ) );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'update_checkout_data' ) );
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'maybe_update_list' ) );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( $this, 'maybe_update_list' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'recover_stuck_pending_order' ), 5 );
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( $this, 'recover_on_blocks_submit' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'recover_on_classic_render' ) );
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( WC_Payoneer_Gateway::class, 'capture_blocks_payment_result' ), 1, 2 );

		$metabox = new Admin\Metabox( $this );
		$metabox->register();
	}


	/**
	 * Hook for plugins_loaded action.
	 *
	 * @return void
	 */
	public function plugins_loaded() {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_filter( 'woocommerce_order_get_payment_method', array( $this, 'map_hosted_payment_method' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
		add_action( 'template_redirect', array( $this, 'handle_payment_return' ) );
		add_action( 'template_redirect', array( $this, 'maybe_prime_checkout_list' ) );
		add_action( 'before_woocommerce_pay', array( $this, 'maybe_create_order_list' ) );
	}


	/**
	 * Register the webhook notification REST endpoint.
	 */
	public function register_webhook_endpoint() {
		$webhook_handler = new Payment\WebhookHandler();
		$webhook_handler->register();
	}


	/**
	 * Register the payment gateway and network proxy gateways with WooCommerce.
	 *
	 * @param array $gateways Existing payment gateways.
	 *
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = WC_Payoneer_Gateway::class;

		if ( ! $this->is_enabled() || ! $this->get_validated_credentials() ) {
			return $gateways;
		}

		if ( $this->is_hosted_integration() && Utils::is_payments_settings_screen() ) {
			return $gateways;
		}

		$is_test          = $this->is_test_mode();
		$networks         = $this->get_networks( $is_test );
		$enabled_networks = $this->get_enabled_networks( $is_test );

		if ( empty( $networks ) || empty( $enabled_networks ) ) {
			return $gateways;
		}

		$network_settings = $this->get_network_settings( $is_test );

		$settings = $this->settings();

		foreach ( $enabled_networks as $code ) {
			if ( ! isset( $networks[ $code ] ) ) {
				continue;
			}

			if ( $settings->is_cc_network( $code ) ) {
				continue;
			}

			$gateways[] = new WC_Payoneer_Network_Gateway( $code, $networks[ $code ], $network_settings[ $code ] ?? array(), $is_test );
		}

		return $gateways;
	}


	/**
	 * Route legacy v3 hosted orders to the primary gateway at read time so refunds resolve — there is no v4 gateway registered under the 'payoneer-hosted' id. Network ids keep their own gateway.
	 *
	 * @param string         $payment_method Raw stored value.
	 * @param \WC_Order|null $order          The order being read.
	 *
	 * @return string
	 */
	public function map_hosted_payment_method( $payment_method, $order = null ) {
		if ( 'payoneer-hosted' === $payment_method ) {
			return WC_Payoneer_Gateway::ID;
		}

		if ( ! $order instanceof \WC_Order || WC_Payoneer_Gateway::is_payoneer_payment_method( $payment_method ) ) {
			return $payment_method;
		}

		if ( '' === (string) $order->get_meta( '_payoneer_payment_charge_id', true ) ) {
			return $payment_method;
		}

		if ( $order->get_transaction_id( 'edit' ) !== (string) $order->get_meta( '_payoneer_payment_transaction_id', true ) ) {
			return $payment_method;
		}

		return WC_Payoneer_Gateway::ID;
	}


	// -------------------------------------------------------------------------
	// Gateway setting accessors
	// -------------------------------------------------------------------------

	/**
	 * Read a single value from the WooCommerce gateway settings option.
	 *
	 * @param string $key Setting key.
	 *
	 * @return string
	 */
	public function get_gateway_setting( $key ) {
		$settings = get_option( 'woocommerce_payoneer_checkout_settings', array() );

		return $settings[ $key ] ?? '';
	}


	/**
	 * Whether a gateway setting key has been persisted.
	 *
	 * @param string $key Setting key.
	 *
	 * @return bool
	 */
	public function gateway_setting_exists( $key ) {
		$settings = get_option( 'woocommerce_payoneer_checkout_settings', array() );

		return is_array( $settings ) && array_key_exists( $key, $settings );
	}


	/**
	 * Update a single key inside the WooCommerce gateway settings option.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 *
	 * @return void
	 */
	public function update_gateway_setting( $key, $value ) {
		$settings         = get_option( 'woocommerce_payoneer_checkout_settings', array() );
		$settings[ $key ] = $value;
		update_option( 'woocommerce_payoneer_checkout_settings', $settings );

		$this->settings()->mirror_to_legacy_option( $settings );
	}


	/**
	 * Whether test mode is active.
	 *
	 * @return bool
	 */
	public function is_test_mode() {
		return 'yes' !== $this->get_gateway_setting( 'live_mode' );
	}


	/**
	 * Whether the gateway is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return 'yes' === $this->get_gateway_setting( 'enabled' );
	}


	/**
	 * Whether logging is enabled, defaulting to on until the merchant saves a choice.
	 *
	 * @return bool
	 */
	public function is_logging_enabled() {
		return ! $this->gateway_setting_exists( 'logging' ) || 'yes' === $this->get_gateway_setting( 'logging' );
	}


	/**
	 * Whether the merchant is both enabled and has validated credentials.
	 *
	 * @return bool
	 */
	public function is_merchant_connected() {
		return $this->is_enabled() && $this->get_validated_credentials();
	}


	/**
	 * Get the active API username (Payoneer merchant code), respecting test mode.
	 *
	 * @return string
	 */
	public function api_username() {
		$key = $this->is_test_mode() ? 'sandbox_merchant_code' : 'merchant_code';

		return $this->get_gateway_setting( $key );
	}


	/**
	 * Get the active API token, respecting test mode.
	 *
	 * @return string
	 */
	public function api_token() {
		$key = $this->is_test_mode() ? 'sandbox_merchant_token' : 'merchant_token';

		return $this->get_gateway_setting( $key );
	}


	/**
	 * Get the API base URL for the given or current mode.
	 *
	 * @param bool|null $is_test_mode Override test mode detection. Uses stored setting when null.
	 *
	 * @return string
	 */
	public function gateway_url( $is_test_mode = null ) {
		$is_test_mode = $is_test_mode ?? $this->is_test_mode();
		$gateway_url  = $is_test_mode ? API::SANDBOX_URL : API::PRODUCTION_URL;

		if ( $is_test_mode && defined( 'PAYONEER_CHECKOUT_GATEWAY_URL' ) && ! empty( \PAYONEER_CHECKOUT_GATEWAY_URL ) ) {
			$gateway_url = \PAYONEER_CHECKOUT_GATEWAY_URL;
		}

		return $gateway_url;
	}


	/**
	 * Get the checkout SDK environment for the current mode.
	 *
	 * @return string
	 */
	public function get_checkout_environment() {
		$is_test_mode = $this->is_test_mode();
		$env          = $is_test_mode ? 'test' : 'live';

		if ( ! $is_test_mode ) {
			return $env;
		}

		$checkout_env_override = $this->get_runtime_override_value(
			array(
				'PAYONEER_CHECKOUT_ENVIRONMENT',
			)
		);
		$gateway_env_override  = $this->get_runtime_override_value(
			array(
				'PAYONEER_CHECKOUT_GATEWAY_ENV',
				'PAYONEER_API_ENV',
			)
		);

		if ( '' !== $checkout_env_override ) {
			$env = $checkout_env_override;
		} elseif ( '' !== $gateway_env_override ) {
			$env = $gateway_env_override;
		}

		return $env;
	}

	/**
	 * Return the first non-empty runtime override from constants or environment.
	 *
	 * @param array $keys Constant/environment names to check in order.
	 *
	 * @return string
	 */
	private function get_runtime_override_value( $keys ) {
		foreach ( $keys as $key ) {
			if ( defined( $key ) && '' !== trim( (string) constant( $key ) ) ) {
				return trim( (string) constant( $key ) );
			}

			$value = getenv( $key );
			if ( false !== $value && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}

		return '';
	}


	/**
	 * Get the configured integration type.
	 *
	 * @return string
	 */
	public function get_integration_type() {
		$type = $this->get_gateway_setting( 'payment_flow' );

		return in_array( $type, array( 'embedded', 'hosted' ), true ) ? $type : 'embedded';
	}


	/**
	 * Get if the configured integration type is hosted.
	 *
	 * @return bool
	 */
	public function is_hosted_integration() {
		return 'hosted' === $this->get_integration_type();
	}


	/**
	 * Get if the configured integration type is embedded.
	 *
	 * @return bool
	 */
	public function is_embedded_integration() {
		return 'embedded' === $this->get_integration_type();
	}


	// -------------------------------------------------------------------------
	// Validated credentials state (per-mode, stored in gateway settings)
	// -------------------------------------------------------------------------

	/**
	 * Whether credentials have been validated for a given mode.
	 *
	 * @param bool|null $test Explicit mode, or null to use the current test_mode setting.
	 *
	 * @return bool
	 */
	public function get_validated_credentials( $test = null ) {
		if ( null === $test ) {
			$test = $this->is_test_mode();
		}

		$key = $test ? 'test_validated_credentials' : 'validated_credentials';

		return 'yes' === $this->get_gateway_setting( $key );
	}


	/**
	 * Persist the credential validation state for a given mode.
	 *
	 * @param bool      $validated Whether credentials are validated.
	 * @param bool|null $test      Explicit mode, or null to use the current test_mode setting.
	 *
	 * @return void
	 */
	public function update_validated_credentials( $validated, $test = null ) {
		if ( null === $test ) {
			$test = $this->is_test_mode();
		}

		$key = $test ? 'test_validated_credentials' : 'validated_credentials';
		$this->update_gateway_setting( $key, $validated ? 'yes' : '' );
	}


	// -------------------------------------------------------------------------
	// Divisions storage
	// -------------------------------------------------------------------------

	/**
	 * Get the stored merchant divisions.
	 *
	 * @param bool $test Whether to get test divisions.
	 *
	 * @return array
	 */
	public function get_divisions( $test = false ) {
		$option = $test
			? 'woocommerce_payoneer_checkout_test_divisions'
			: 'woocommerce_payoneer_checkout_divisions';

		return get_option( $option, array() );
	}


	/**
	 * Persist the merchant divisions.
	 *
	 * @param array $divisions Divisions data from the API.
	 * @param bool  $test      Whether to store as test divisions.
	 *
	 * @return void
	 */
	public function update_divisions( $divisions, $test = false ) {
		$option = $test
			? 'woocommerce_payoneer_checkout_test_divisions'
			: 'woocommerce_payoneer_checkout_divisions';

		update_option( $option, $divisions );
	}


	// -------------------------------------------------------------------------
	// Selected division
	// -------------------------------------------------------------------------

	/**
	 * Get the currently selected division, respecting test mode.
	 *
	 * @return string
	 */
	public function get_selected_division() {
		$key = $this->is_test_mode() ? 'sandbox_store_code' : 'store_code';

		return $this->get_gateway_setting( $key );
	}


	// -------------------------------------------------------------------------
	// Networks storage
	// -------------------------------------------------------------------------

	/**
	 * Get the stored payment networks.
	 *
	 * @param bool $test Whether to get test networks.
	 *
	 * @return array
	 */
	public function get_networks( $test = false ) {
		$key = $test ? 'test_networks' : 'networks';

		$value = $this->get_gateway_setting( $key );

		return ! empty( $value ) ? $value : array();
	}


	/**
	 * Persist the payment networks.
	 *
	 * @param array $networks Networks data from the API.
	 * @param bool  $test     Whether to store as test networks.
	 *
	 * @return void
	 */
	public function update_networks( $networks, $test = false ) {
		$key = $test ? 'test_networks' : 'networks';
		$this->update_gateway_setting( $key, $networks );
	}


	/**
	 * Get the enabled network codes.
	 *
	 * @param bool $test Whether to get test enabled networks.
	 *
	 * @return array
	 */
	public function get_enabled_networks( $test = false ) {
		$codes = array_keys( $this->get_networks( $test ) );

		return array_values( array_diff( $codes, $this->get_disabled_networks( $test ) ) );
	}


	/**
	 * Get the network codes the merchant explicitly disabled.
	 *
	 * @param bool $test Whether to get test disabled networks.
	 *
	 * @return array
	 */
	public function get_disabled_networks( $test = false ) {
		$value = $this->get_gateway_setting( $test ? 'test_disabled_networks' : 'disabled_networks' );

		return is_array( $value ) ? $value : array();
	}


	/**
	 * Persist the explicitly disabled network codes.
	 *
	 * @param array $codes Array of network code strings.
	 * @param bool  $test  Whether to store as test disabled networks.
	 *
	 * @return void
	 */
	public function update_disabled_networks( $codes, $test = false ) {
		$this->update_gateway_setting( $test ? 'test_disabled_networks' : 'disabled_networks', array_values( $codes ) );
	}


	/**
	 * Seed the WooCommerce provider order option with our gateways, in default order, when absent.
	 *
	 * @return void
	 */
	public function seed_default_gateway_order() {
		$order = (array) get_option( 'woocommerce_gateway_order', array() );
		$next  = $order ? max( $order ) + 1 : 0;

		$ids = array( WC_Payoneer_Gateway::ID );
		foreach ( Utils::NETWORK_ORDER as $code ) {
			$ids[] = 'payoneer-' . strtolower( $code );
		}

		foreach ( $ids as $id ) {
			if ( ! isset( $order[ $id ] ) ) {
				$order[ $id ] = $next++;
			}
		}

		update_option( 'woocommerce_gateway_order', $order );
	}


	// -------------------------------------------------------------------------
	// Per-network settings (title, description overrides)
	// -------------------------------------------------------------------------

	/**
	 * Get the per-network settings (title and description overrides).
	 *
	 * @param bool $test Whether to get test network settings.
	 *
	 * @return array
	 */
	public function get_network_settings( $test = false ) {
		$key = $test ? 'test_network_settings' : 'network_settings';

		$value = $this->get_gateway_setting( $key );

		return ! empty( $value ) ? $value : array();
	}


	/**
	 * Persist the per-network settings.
	 *
	 * @param array $settings Associative array keyed by network code.
	 * @param bool  $test     Whether to store as test network settings.
	 *
	 * @return void
	 */
	public function update_network_settings( $settings, $test = false ) {
		$key = $test ? 'test_network_settings' : 'network_settings';
		$this->update_gateway_setting( $key, $settings );
	}


	/**
	 * Get the applicable network codes from the current LIST session.
	 *
	 * @return array
	 */
	public function get_applicable_networks() {
		return (array) $this->get_stored_applicable_networks();
	}


	/**
	 * Whether the current LIST offers a card network.
	 *
	 * @return bool
	 */
	public function is_card_network_applicable() {
		$applicable = $this->get_stored_applicable_networks();

		return null === $applicable || ! empty( array_intersect( $applicable, Utils::CC_CODES ) );
	}


	/**
	 * Read the applicable network codes stored for the current LIST.
	 *
	 * @return array|null
	 */
	private function get_stored_applicable_networks() {
		$order = Utils::get_current_order();
		if ( $order instanceof \WC_Order ) {
			$stored = $order->get_meta( '_payoneer_applicable_networks', true );
			if ( is_array( $stored ) ) {
				return $stored;
			}
		}

		$applicable = Utils::get_session_key( 'list_applicable_networks' );

		return is_array( $applicable ) ? $applicable : null;
	}


	/**
	 * Mirror posted billing name/email/phone onto the session customer during order review.
	 *
	 * @param string $post_data Serialized checkout form data.
	 */
	public function sync_billing_customer_fields( $post_data ) {
		if ( empty( WC()->customer ) || ! is_string( $post_data ) || '' === $post_data ) {
			return;
		}

		parse_str( $post_data, $fields );

		$setters = array(
			'billing_first_name' => 'set_billing_first_name',
			'billing_last_name'  => 'set_billing_last_name',
			'billing_email'      => 'set_billing_email',
			'billing_phone'      => 'set_billing_phone',
		);

		foreach ( $setters as $key => $setter ) {
			if ( isset( $fields[ $key ] ) ) {
				WC()->customer->{$setter}( wc_clean( $fields[ $key ] ) );
			}
		}
	}


	/**
	 * Re-sync the LIST session when checkout fragments are refreshed.
	 *
	 * @param array $fragments Order review AJAX fragments.
	 *
	 * @return array
	 */
	public function update_checkout_data( $fragments ) {
		$this->maybe_update_list();

		return $fragments;
	}


	/**
	 * Update the LIST session if the checkout payload has changed.
	 */
	public function maybe_update_list() {
		if ( ! WC()->cart || empty( WC()->session ) ) {
			return;
		}

		if ( ! $this->is_embedded_integration() ) {
			return;
		}

		$long_id = Utils::get_session_key( 'list_long_id' );
		if ( empty( $long_id ) ) {
			if ( Utils::is_checkout_page() ) {
				WC_Payoneer_Gateway::create_list_session();
			}
			return;
		}

		$payload_hash = $this->compute_list_payload_hash();
		$stored_hash  = Utils::get_session_key( 'list_payload_hash' );

		if ( $payload_hash === $stored_hash ) {
			return;
		}

		$transaction_id = Utils::get_session_key( 'list_transaction_id' );
		$payload        = PayloadHelper::build_update_payload_from_cart( $transaction_id );
		$response       = $this->api()->update_list( $long_id, $payload );

		if ( ! $response['success'] ) {
			$interaction_code = $response['body']['interaction']['code'] ?? '';

			if ( 'ABORT' === $interaction_code ) {
				$this->reset_session();
				WC()->session->set( 'refresh_totals', true );
				return;
			}

			if ( 'RELOAD' === $interaction_code ) {
				$this->logger()->log( 'LIST update on checkout refresh skipped: RELOAD interaction (LIST is busy, likely mid-charge in another tab).', 'info' );
				return;
			}

			$this->logger()->log( 'LIST update (checkout refresh) failed: ' . ( $response['error'] ?? 'Unknown' ), 'error' );
			return;
		}

		Utils::set_session_key( 'list_payload_hash', $payload_hash );

		$applicable = self::extract_applicable_networks( $response['body'] );

		$this->logger()->log(
			sprintf(
				'LIST updated on checkout refresh (longId: %s, applicable: %s)',
				$long_id,
				implode( ', ', $applicable )
			)
		);
	}


	/**
	 * Prime the LIST session for the block checkout, before WC Blocks serializes its preloaded payment methods.
	 *
	 * @return void
	 */
	public function maybe_prime_checkout_list() {
		if ( ! $this->is_embedded_integration() || ! $this->is_enabled() || ! $this->get_validated_credentials() ) {
			return;
		}

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		if ( is_order_received_page() ) {
			return;
		}

		if ( has_block( 'woocommerce/checkout' ) ) {
			$this->recover_stuck_pending_order( false );
			WC_Payoneer_Gateway::get_list_id();
		}
	}


	/**
	 * Detach a foreign in-flight order at classic checkout render, where no per-tab token is available.
	 */
	public function recover_on_classic_render() {
		if ( $this->is_embedded_integration() ) {
			$this->recover_stuck_pending_order( false );
		}
	}


	/**
	 * Detach a foreign in-flight order at block checkout submit, before the Store API resolves the draft order.
	 */
	public function recover_on_blocks_submit() {
		if ( $this->is_embedded_integration() ) {
			$this->recover_stuck_pending_order();
		}
	}


	/**
	 * Detach the session order when a prior payment left the LIST in-flight, so checkout creates a new order.
	 *
	 * @param bool $token_verify Whether to check the tab token.
	 *
	 * @return bool
	 */
	public function recover_stuck_pending_order( $token_verify = true ) {
		$order = Utils::get_current_pending_order();
		if ( ! $order || $order->is_paid() ) {
			Utils::set_session_key( 'list_pending_order_id', '' );
			return false;
		}

		$order_long_id = (string) $order->get_meta( '_payoneer-long-id', true );
		if ( '' === $order_long_id ) {
			Utils::set_session_key( 'list_pending_order_id', '' );
			return false;
		}

		$pending_order_id = $order->get_id();

		$response = self::fetch_list_state( $order_long_id );
		if ( empty( $response ) || empty( $response['success'] ) ) {
			return false;
		}

		$status_code = self::derive_list_status_code( $response['body'] ?? array() );
		if ( 'pending:functioning' !== $status_code ) {
			return false;
		}

		if ( $token_verify ) {
			$tab_token   = Utils::request_tab_token();
			$owner_token = (string) $order->get_meta( '_payoneer_payment_tab_token', true );
			if ( empty( $tab_token ) || empty( $owner_token ) || $tab_token === $owner_token ) {
				return false;
			}
		}

		$this->logger()->log(
			sprintf(
				'Detected in-flight LIST %s for order #%d (state: %s). Detaching the session order markers so checkout creates a new order; the original order is left pending for the webhook.',
				$order_long_id,
				$pending_order_id,
				$status_code
			),
			'warning'
		);

		$this->add_parallel_recovery_note( $order );

		Utils::detach_pending_order();

		if ( $this->is_embedded_integration() ) {
			$this->reset_session();
		}

		return true;
	}


	/**
	 * Add a one-time note to an in-flight order that is being left pending while a new order is created.
	 *
	 * @param \WC_Order $order The abandoned in-flight order.
	 */
	private function add_parallel_recovery_note( $order ) {
		if ( 'yes' === $order->get_meta( '_payoneer_parallel_recovery_note', true ) ) {
			return;
		}

		$order->add_order_note(
			__( 'A parallel payment attempt was detected while this order had a payment already in progress. A new order is being created for the new attempt; this order is left pending and may still be completed by an incoming webhook.', 'payoneer-checkout' )
		);
		$order->update_meta_data( '_payoneer_parallel_recovery_note', 'yes' );
		$order->save();
	}


	/**
	 * Compute an MD5 hash of the current cart-based LIST payload fields that matter.
	 *
	 * @return string
	 */
	public function compute_list_payload_hash() {
		$cart     = WC()->cart;
		$customer = WC()->customer;

		$data = array(
			'total'    => $cart->get_total( 'edit' ),
			'currency' => get_woocommerce_currency(),
			'country'  => $customer ? $customer->get_billing_country() : '',
			'name'     => $customer ? $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() : '',
			'email'    => $customer ? $customer->get_billing_email() : '',
			'items'    => $cart->get_cart_hash(),
		);

		return md5( wp_json_encode( $data ) );
	}


	/**
	 * Record the picked network code on the order.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @param string    $code  Network code.
	 */
	public static function persist_network_meta( $order, $code ) {
		$code = sanitize_text_field( (string) $code );
		if ( '' === $code ) {
			return;
		}

		$order->update_meta_data( '_payoneer_network_code', $code );
	}


	/**
	 * Extract applicable network codes from a LIST API response and store them in session.
	 *
	 * @param array          $response_body The API response body.
	 * @param \WC_Order|null $order         Optional order to mirror the applicable set onto as meta.
	 *
	 * @return array
	 */
	public static function extract_applicable_networks( $response_body, $order = null ) {
		$applicable = $response_body['networks']['applicable'] ?? array();

		$codes = array_values(
			array_filter(
				array_map(
					function ( $network ) {
						return sanitize_text_field( $network['code'] ?? '' );
					},
					$applicable
				)
			)
		);

		Utils::set_session_key( 'list_applicable_networks', $codes );

		if ( $order instanceof \WC_Order ) {
			$order->update_meta_data( '_payoneer_applicable_networks', $codes );
			$order->save_meta_data();
		}

		return $codes;
	}


	/**
	 * Clear the cached LIST session and create a fresh one.
	 *
	 * @return string The new longId, or empty string on failure.
	 */
	public function reset_session() {
		Utils::cleanup_cached_list();

		return WC_Payoneer_Gateway::create_list_session();
	}

	/**
	 * AJAX action used by the reset-session nonce.
	 */
	const RESET_SESSION_NONCE_ACTION = 'payoneer_checkout_reset_session';


	/**
	 * AJAX: reset the LIST session and return the new longId.
	 *
	 * @return void
	 */
	public function ajax_reset_session() {
		if ( ! check_ajax_referer( self::RESET_SESSION_NONCE_ACTION, '_payoneer_reset_nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => 'invalid_nonce' ),
				403
			);
		}

		$long_id = $this->reset_session();

		wp_send_json_success(
			array(
				'long_id' => $long_id,
				'nonce'   => $this->get_reset_nonce(),
			)
		);
	}


	/**
	 * Generate a fresh WordPress nonce for the reset-session AJAX endpoint.
	 *
	 * @return string
	 */
	public function get_reset_nonce() {
		return wp_create_nonce( self::RESET_SESSION_NONCE_ACTION );
	}


	/**
	 * Proceed with current request using new login session (to ensure consistent nonce).
	 *
	 * @param string $cookie The cookie.
	 */
	public function inherit_logged_in_cookie_in_request( $cookie ) {
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
	}


	/**
	 * Handle return from Payoneer hosted page on the order-received and order-pay endpoints.
	 */
	public function handle_payment_return() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
			return;
		}

		$is_received = is_wc_endpoint_url( 'order-received' );
		$is_pay      = is_wc_endpoint_url( 'order-pay' );

		if ( ! $is_received && ! $is_pay ) {
			return;
		}

		global $wp;
		$query_var = $is_received ? 'order-received' : 'order-pay';
		$order_id  = absint( $wp->query_vars[ $query_var ] ?? 0 );

		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || ! WC_Payoneer_Gateway::is_payoneer_payment_method( $order->get_payment_method() ) ) {
			return;
		}

		$this->process_hosted_return_interaction( $order );

		if ( $is_received ) {
			Utils::cleanup_cached_list();
		}
	}


	/**
	 * Provision the LIST session for the order-pay endpoint before WC load the gateways.
	 *
	 * @return void
	 */
	public function maybe_create_order_list() {
		if ( ! $this->is_embedded_integration() || ! $this->is_enabled() || ! $this->get_validated_credentials() ) {
			return;
		}

		$order_id = (int) get_query_var( 'order-pay' );
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $order->needs_payment() ) {
			return;
		}

		$network_code = (string) $order->get_meta( '_payoneer_network_code', true );
		if ( '' !== $network_code && WC()->session ) {
			WC()->session->set( 'chosen_payment_method', 'payoneer-' . strtolower( $network_code ) );
		}

		$order_long_id = $order->get_meta( '_payoneer-long-id', true );

		if ( ! empty( $order_long_id ) ) {
			if ( OrderHelper::is_awaiting_webhook( $order ) ) {
				return;
			}

			// Order was previously charged.
			if ( ! empty( $order->get_meta( '_payoneer_payment_charge_id', true ) ) ) {
				OrderHelper::reset_payment_meta( $order );
				$order_long_id = '';
			}
		}

		if ( ! empty( $order_long_id ) ) {
			if ( OrderHelper::is_order_payment_locked( $order ) ) {
				$this->prime_order_list_session( $order, $order_long_id );
				return;
			}

			if ( WC_Payoneer_Gateway::is_list_valid() && $order_long_id === Utils::get_session_key( 'list_long_id' ) ) {
				return;
			}

			$state = self::fetch_list_state( $order_long_id );

			if ( ! empty( $state ) && ! empty( $state['success'] ) ) {
				$status_code = self::derive_list_status_code( $state['body'] ?? array() );
				if ( in_array( $status_code, array( 'listed', 'pending', 'pending:functioning' ), true ) ) {
					$this->prime_order_list_session( $order, $order_long_id );
					return;
				}
			} elseif ( 404 !== (int) ( $state['code'] ?? 0 ) ) {
				$this->prime_order_list_session( $order, $order_long_id );
				return;
			}
		}

		Utils::cleanup_cached_list();
		WC_Payoneer_Gateway::create_list_session( $order, true );
	}


	/**
	 * Prime the WC session from an order's stored LIST so a re-prime resumes it instead of minting a new one.
	 *
	 * @param \WC_Order $order   The order being paid.
	 * @param string    $long_id The order's stored LIST longId.
	 *
	 * @return void
	 */
	private function prime_order_list_session( $order, $long_id ) {
		Utils::set_session_key( 'list_long_id', $long_id );
		Utils::set_session_key( 'list_pending_order_id', $order->get_id() );

		$expires_at = (int) $order->get_meta( '_payoneer_list_expires_at', true );
		if ( $expires_at > 0 ) {
			Utils::set_session_key( 'list_expires_at', $expires_at );
		}

		$transaction_id = $order->get_transaction_id();
		if ( $transaction_id ) {
			Utils::set_session_key( 'list_transaction_id', $transaction_id );
		}

		$stored_networks = $order->get_meta( '_payoneer_applicable_networks', true );
		if ( is_array( $stored_networks ) && ! empty( $stored_networks ) ) {
			Utils::set_session_key( 'list_applicable_networks', $stored_networks );
		}
	}


	/**
	 * Sync order state when the customer returns from a hosted Payoneer page.
	 *
	 * @param \WC_Order $order The order being returned to.
	 */
	private function process_hosted_return_interaction( $order ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only customer-return handler; no state mutation depends on these query args.
		$interaction_code = isset( $_GET['interactionCode'] ) ? sanitize_text_field( wp_unslash( $_GET['interactionCode'] ) ) : '';

		if ( empty( $interaction_code ) ) {
			return;
		}

		if ( 'PROCEED' === $interaction_code ) {
			if ( $order->is_paid() ) {
				return;
			}

			// Put order back to awaiting payment if customer returned with PROCEED.
			if ( $order->has_status( 'failed' ) ) {
				OrderHelper::put_order_awaiting_payment(
					$order,
					__( 'Customer returned from Payoneer hosted page with interactionCode=PROCEED after a prior declined attempt; holding for webhook confirmation.', 'payoneer-checkout' )
				);
			}

			if ( ! OrderHelper::is_awaiting_webhook( $order ) ) {
				return;
			}

			$this->order_needs_payment( $order );
			return;
		}

		$interaction_reason = isset( $_GET['interactionReason'] ) ? sanitize_text_field( wp_unslash( $_GET['interactionReason'] ) ) : '';
		$long_id            = isset( $_GET['longId'] ) ? sanitize_text_field( wp_unslash( $_GET['longId'] ) ) : '';
		$transaction_id     = isset( $_GET['transactionId'] ) ? sanitize_text_field( wp_unslash( $_GET['transactionId'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$dedupe_key = md5( $long_id . '|' . $transaction_id . '|' . $interaction_code . '|' . $interaction_reason );

		if ( $order->get_meta( '_payoneer_last_return_interaction', true ) === $dedupe_key ) {
			return;
		}

		$order->update_meta_data( '_payoneer_last_return_interaction', $dedupe_key );
		$order->add_order_note(
			sprintf(
				/* translators: 1: interaction code, 2: interaction reason */
				__( 'Customer returned from Payoneer hosted page (interactionCode=%1$s, interactionReason=%2$s).', 'payoneer-checkout' ),
				$interaction_code,
				$interaction_reason
			)
		);
		$order->save();

		if ( $order->is_paid() ) {
			return;
		}

		if ( OrderHelper::is_awaiting_webhook( $order ) ) {
			OrderHelper::clear_awaiting_webhook_flag( $order );
			$order->save();
		}

		OrderHelper::unlock_order_payment( $order );

		if ( ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		if ( in_array( $interaction_code, array( 'RETRY', 'TRY_OTHER_NETWORK', 'RELOAD' ), true ) ) {
			wc_add_notice( __( 'Payment was not completed. Please try again.', 'payoneer-checkout' ), 'error' );
		} elseif ( 'CUSTOMER_ABORT' === $interaction_reason && 'TRY_OTHER_ACCOUNT' === $interaction_code ) {
			wc_add_notice( __( 'Payment was cancelled. Please try again or choose a different payment method.', 'payoneer-checkout' ), 'error' );
		} elseif ( 'ABORT' === $interaction_code ) {
			wc_add_notice( __( 'Payment was cancelled or could not be completed. Please choose a different payment method or contact support.', 'payoneer-checkout' ), 'error' );
		} else {
			wc_add_notice( __( 'Payment was not completed or failed. Please try again or choose a different payment method.', 'payoneer-checkout' ), 'error' );
		}
	}


	/**
	 * Prevent double-payment on orders awaiting async webhook confirmation.
	 *
	 * @param bool      $needs_payment Whether the order needs payment.
	 * @param \WC_Order $order         The WooCommerce order.
	 *
	 * @return bool
	 */
	public function maybe_suppress_payment_needed( $needs_payment, $order ) {
		if ( ! $needs_payment || ! WC_Payoneer_Gateway::is_payoneer_payment_method( $order->get_payment_method() ) ) {
			return $needs_payment;
		}

		if ( ! OrderHelper::is_awaiting_webhook( $order ) || $order->has_status( 'failed' ) ) {
			return $needs_payment;
		}

		return $this->order_needs_payment( $order );
	}


	/**
	 * Append a "payment in progress / retry in N minutes" sentence to the order-details status line.
	 *
	 * @param string    $status_text The default WC status sentence.
	 * @param \WC_Order $order       The order being rendered.
	 *
	 * @return string
	 */
	public function maybe_extend_order_status_text( $status_text, $order ) {
		if ( ! $order instanceof \WC_Order || ! WC_Payoneer_Gateway::is_payoneer_payment_method( $order->get_payment_method() ) ) {
			return $status_text;
		}

		if ( ! apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( OrderStatus::PENDING, OrderStatus::FAILED ), $order ) ) {
			return $status_text;
		}

		if ( ! OrderHelper::is_awaiting_webhook( $order ) ) {
			return $status_text;
		}

		$awaiting_at = (int) $order->get_meta( OrderHelper::META_AWAITING_WEBHOOK, true );
		$remaining   = max( 1, (int) ceil( ( $awaiting_at + 30 * MINUTE_IN_SECONDS - time() ) / MINUTE_IN_SECONDS ) );

		return $status_text . ' ' . esc_html(
			sprintf(
				/* translators: %d: minutes until the customer can retry the payment. */
				_n(
					'A payment process is in progress for this order, you can retry in %d minute.',
					'A payment process is in progress for this order, you can retry in %d minutes.',
					$remaining,
					'payoneer-checkout'
				),
				$remaining
			)
		);
	}


	/**
	 * Resolve whether the order still needs payment from live LIST state.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 *
	 * @return bool
	 */
	private function order_needs_payment( $order ) {
		static $cache = array();

		$order_id = $order->get_id();
		if ( array_key_exists( $order_id, $cache ) ) {
			return $cache[ $order_id ];
		}

		$cache[ $order_id ] = $this->resolve_order_needs_payment( $order );
		return $cache[ $order_id ];
	}


	/**
	 * Resolve whether the order still needs payment (uncached); clears the awaiting-webhook flag when the LIST shows the charge never started or is dead.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 *
	 * @return bool
	 */
	private function resolve_order_needs_payment( $order ) {
		// Refresh in case payment_complete or meta updates ran earlier in this request.
		$order = wc_get_order( $order->get_id() );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		if ( $order->is_paid() ) {
			return false;
		}

		$long_id = $order->get_meta( '_payoneer-long-id', true );
		if ( empty( $long_id ) ) {
			self::instance()->logger()->log(
				sprintf( 'Order #%d had _payoneer_awaiting_webhook set but no _payoneer-long-id; clearing flag for retry', $order->get_id() ),
				'error'
			);
			OrderHelper::clear_awaiting_webhook_flag( $order );
			$order->add_order_note(
				__( 'Cleared awaiting webhook flag: order was awaiting confirmation but no LIST longId was stored. Order is now retryable.', 'payoneer-checkout' )
			);
			$order->save();
			return true;
		}

		$debounce_key = 'payoneer_list_pulled_' . md5( $long_id );
		if ( get_transient( $debounce_key ) ) {
			return true;
		}
		set_transient( $debounce_key, 1, 30 );

		$response = self::fetch_list_state( $long_id );

		if ( empty( $response ) || empty( $response['success'] ) ) {
			$http_code = isset( $response['code'] ) ? (int) $response['code'] : 0;

			if ( 404 === $http_code ) {
				OrderHelper::clear_awaiting_webhook_flag( $order );
				$order->add_order_note(
					__( 'Cleared awaiting webhook flag: Payoneer reports the stored LIST does not exist. Order is now retryable.', 'payoneer-checkout' )
				);
				$order->save();
				return true;
			}

			return true;
		}

		$status_code = self::derive_list_status_code( $response['body'] ?? array() );

		if ( 'listed' === $status_code ) {
			if ( OrderHelper::is_order_payment_locked( $order ) ) {
				return true;
			}
			OrderHelper::clear_awaiting_webhook_flag( $order );
			$order->add_order_note(
				__( 'Cleared awaiting webhook flag: payment charge was never initiated (LIST still in "listed" status). Order is now retryable.', 'payoneer-checkout' )
			);
			$order->save();
			return true;
		}

		if ( in_array( $status_code, array( 'aborted', 'closed', 'expired' ), true ) ) {
			OrderHelper::clear_awaiting_webhook_flag( $order );
			$order->add_order_note(
				sprintf(
					/* translators: %s: terminal LIST status code reported by Payoneer. */
					__( 'Cleared awaiting webhook flag: Payoneer reports the LIST as "%s". Order is now retryable.', 'payoneer-checkout' ),
					$status_code
				)
			);
			$order->save();
			return true;
		}

		if ( in_array( $status_code, array( 'pending', 'pending:functioning', 'preauthorized', 'in_progress' ), true ) ) {
			return true;
		}

		return false;
	}


	/**
	 * Prevent WC's wc_cancel_unpaid_orders cron from cancelling orders awaiting a webhook.
	 *
	 * @param bool      $cancel_order Whether the order should be cancelled.
	 * @param \WC_Order $order        The WooCommerce order.
	 *
	 * @return bool
	 */
	public function maybe_skip_unpaid_cancel( $cancel_order, $order ) {
		if ( ! $cancel_order ) {
			return $cancel_order;
		}
		if ( ! WC_Payoneer_Gateway::is_payoneer_payment_method( $order->get_payment_method() ) ) {
			return $cancel_order;
		}
		if ( OrderHelper::is_awaiting_webhook( $order ) ) {
			return false;
		}
		return $cancel_order;
	}


	/**
	 * Remove Pay/Cancel actions while waiting for webhook confirmation.
	 *
	 * @param array     $actions Order actions.
	 * @param \WC_Order $order   The WooCommerce order.
	 *
	 * @return array
	 */
	public function maybe_suppress_order_actions( $actions, $order ) {
		if ( ! WC_Payoneer_Gateway::is_payoneer_payment_method( $order->get_payment_method() ) ) {
			return $actions;
		}

		if ( OrderHelper::is_awaiting_webhook( $order ) && ! $order->has_status( 'failed' ) ) {
			unset( $actions['pay'], $actions['cancel'] );
		}

		return $actions;
	}


	/**
	 * Append an order ID to a flagged-orders option. Idempotent.
	 *
	 * @param int    $order_id WC order ID.
	 * @param string $option   Option name holding the flagged IDs.
	 */
	public static function flag_order( $order_id, $option ) {
		$flagged   = self::get_flagged_order_ids( $option );
		$flagged[] = (int) $order_id;
		update_option( $option, array_values( array_unique( $flagged ) ), false );
	}


	/**
	 * Remove an order ID from a flagged-orders option.
	 *
	 * @param int    $order_id WC order ID.
	 * @param string $option   Option name holding the flagged IDs.
	 */
	public static function unflag_order( $order_id, $option ) {
		$flagged = array_values( array_diff( self::get_flagged_order_ids( $option ), array( (int) $order_id ) ) );

		if ( empty( $flagged ) ) {
			delete_option( $option );
			return;
		}

		update_option( $option, $flagged, false );
	}


	/**
	 * Read the persisted order IDs from a flagged-orders option.
	 *
	 * @param string $option Option name holding the flagged IDs.
	 *
	 * @return int[]
	 */
	public static function get_flagged_order_ids( $option ) {
		$flagged = get_option( $option, array() );
		return is_array( $flagged ) ? array_values( array_filter( array_map( 'absint', $flagged ) ) ) : array();
	}


	/**
	 * Get the LIST status.code from a LIST.
	 *
	 * @param array $body Decoded response body from GET /lists/{longId}.
	 *
	 * @return string
	 */
	public static function derive_list_status_code( $body ) {
		$code   = sanitize_text_field( $body['status']['code'] ?? '' );
		$reason = sanitize_text_field( $body['status']['reason'] ?? '' );

		if ( 'pending' === $code && 'functioning' === $reason ) {
			return 'pending:functioning';
		}

		return $code;
	}


	/**
	 * Fetch the current LIST state.
	 *
	 * @param string $long_id LIST longId.
	 *
	 * @return array
	 */
	public static function fetch_list_state( $long_id ) {
		static $cache = array();

		if ( empty( $long_id ) ) {
			return array();
		}

		if ( array_key_exists( $long_id, $cache ) ) {
			return $cache[ $long_id ];
		}

		$cache[ $long_id ] = self::instance()->api()->get_list( $long_id );
		return $cache[ $long_id ];
	}


	/**
	 * AJAX: validate posted credentials and return divisions + networks.
	 */
	public function ajax_validate_credentials() {
		check_ajax_referer( 'payoneer_checkout_validate_credentials', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'payoneer-checkout' ) ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$is_test_mode = 'yes' !== sanitize_text_field( wp_unslash( $_POST['live_mode'] ?? 'no' ) );
		$api_username = sanitize_text_field( wp_unslash( $_POST['merchant_code'] ?? '' ) );
		$api_token    = sanitize_text_field( wp_unslash( $_POST['merchant_token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $api_username ) || empty( $api_token ) ) {
			wp_send_json_error( array( 'message' => __( 'Merchant Code and Payment Code are required.', 'payoneer-checkout' ) ) );
		}

		$cache_key = self::validation_cache_key( $api_username, $api_token, $is_test_mode );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$this->logger()->log( 'AJAX credential validation served from cache' );
			wp_send_json_success( $cached );
		}

		$this->logger()->log( 'AJAX credential validation (test_mode: ' . ( $is_test_mode ? 'yes' : 'no' ) . ')' );

		$api = $this->api();

		$result = $this->with_credentials(
			$api_username,
			$api_token,
			$is_test_mode,
			function () use ( $api ) {
				$response = $api->request( 'meta/divisions' );

				if ( ! $response['success'] || empty( $response['body'] ) ) {
					return array( 'error' => $response['error'] ?? 'Unknown' );
				}

				$divisions = array();
				foreach ( (array) $response['body'] as $division ) {
					if ( ! is_array( $division ) ) {
						continue;
					}

					$name = sanitize_text_field( $division['name'] ?? '' );
					if ( empty( $name ) ) {
						continue;
					}

					$divisions[] = array(
						'name'        => $name,
						'displayName' => sanitize_text_field( $division['displayName'] ?? '' ),
					);
				}

				return array(
					'divisions'            => $divisions,
					'networks_by_division' => $this->build_networks_map_for_divisions( $divisions ),
				);
			}
		);

		if ( isset( $result['error'] ) ) {
			$this->logger()->log( 'AJAX credential validation failed: ' . $result['error'], 'error' );
			wp_send_json_error( array( 'message' => __( 'Invalid credentials. Please check your Merchant Code and Payment Code.', 'payoneer-checkout' ) ) );
		}

		$divisions            = $result['divisions'];
		$networks_by_division = $result['networks_by_division'];

		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
		set_transient( $cache_key . '_networks', $networks_by_division, 5 * MINUTE_IN_SECONDS );

		$this->logger()->log( 'Credentials validation succeeded (' . count( $divisions ) . ' divisions)' );

		wp_send_json_success( $result );
	}


	/**
	 * Validation cache key for a credential pair + mode. Networks map transient is keyed as `$cache_key . '_networks'`.
	 */
	private static function validation_cache_key( $username, $token, $is_test ) {
		return 'payoneer_checkout_validation_' . md5( $username . ':' . $token . ':' . ( $is_test ? 'test' : 'live' ) );
	}


	/**
	 * Option name for `division => networks[]` map for a mode.
	 */
	private static function networks_option_key( $is_test ) {
		return $is_test
			? 'woocommerce_payoneer_checkout_test_networks_by_division'
			: 'woocommerce_payoneer_checkout_networks_by_division';
	}


	/**
	 * Read the `division => networks[]` map, falling back to the validation transient.
	 *
	 * @param bool|null $is_test Mode to read; null returns the merged map for both modes.
	 *
	 * @return array<string,array>
	 */
	public function get_networks_by_division( $is_test = null ) {
		if ( null === $is_test ) {
			return $this->get_networks_by_division( true ) + $this->get_networks_by_division( false );
		}

		$stored = get_option( self::networks_option_key( $is_test ), array() );
		if ( ! empty( $stored ) && is_array( $stored ) ) {
			return $stored;
		}

		$prefix   = $is_test ? 'sandbox_' : '';
		$username = $this->get_gateway_setting( $prefix . 'merchant_code' );
		$token    = $this->get_gateway_setting( $prefix . 'merchant_token' );

		if ( empty( $username ) || empty( $token ) ) {
			return array();
		}

		$cache_key = self::validation_cache_key( $username, $token, $is_test );
		$cached    = get_transient( $cache_key . '_networks' );

		return is_array( $cached ) ? $cached : array();
	}


	/**
	 * Persist the networks-by-division map for a mode.
	 *
	 * @param array $map     Division-keyed networks map.
	 * @param bool  $is_test Whether the map applies to test mode.
	 *
	 * @return void
	 */
	public function update_networks_by_division( $map, $is_test = false ) {
		update_option( self::networks_option_key( $is_test ), $map );
	}


	/**
	 * Run a callback with the API auth header and base URL overridden for a credential pair.
	 *
	 * @param string   $username Merchant code.
	 * @param string   $token    Merchant token.
	 * @param bool     $is_test  Whether to target the test base URL.
	 * @param callable $callback Work to run while the overrides are active.
	 *
	 * @return mixed The callback's return value.
	 */
	public function with_credentials( $username, $token, $is_test, $callback ) {
		$auth = base64_encode( $username . ':' . $token ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$override_headers = function ( $headers ) use ( $auth ) {
			$headers['Authorization'] = 'Basic ' . $auth;
			return $headers;
		};

		$override_base_url = function () use ( $is_test ) {
			return $this->gateway_url( $is_test );
		};

		add_filter( 'payoneer_checkout_request_headers', $override_headers );
		add_filter( 'payoneer_checkout_request_base_url', $override_base_url );

		try {
			return $callback();
		} finally {
			remove_filter( 'payoneer_checkout_request_headers', $override_headers );
			remove_filter( 'payoneer_checkout_request_base_url', $override_base_url );
		}
	}


	/**
	 * Refresh the networks-by-division map for a mode if credentials are valid and map is stale or forced.
	 *
	 * @param bool $is_test Whether to refresh test or live mode.
	 * @param bool $force   Whether to force a refresh.
	 */
	public function maybe_refresh_networks( $is_test = false, $force = false ) {
		if ( ! $this->get_validated_credentials( $is_test ) ) {
			return;
		}

		$transient = 'payoneer_checkout_networks_refreshed_' . ( $is_test ? 'test' : 'live' );
		if ( ! $force && false !== get_transient( $transient ) ) {
			return;
		}

		$store_key = ( $is_test ? 'sandbox_' : '' ) . 'merchant_code';
		if ( $this->get_gateway_setting( $store_key ) ) {
			$this->settings()->fetch_networks( $is_test );
		}

		$this->refresh_networks_by_division( $is_test );

		set_transient( $transient, 1, HOUR_IN_SECONDS );
	}


	/**
	 * Refresh the networks-by-division map for a mode using the stored credentials and divisions.
	 *
	 * @param bool $is_test Whether to refresh test or live mode.
	 *
	 * @return array<string,array>
	 */
	public function refresh_networks_by_division( $is_test = false ) {
		$prefix    = $is_test ? 'sandbox_' : '';
		$username  = $this->get_gateway_setting( $prefix . 'merchant_code' );
		$token     = $this->get_gateway_setting( $prefix . 'merchant_token' );
		$divisions = $this->get_divisions( $is_test );

		if ( empty( $username ) || empty( $token ) || empty( $divisions ) ) {
			return array();
		}

		$map = $this->with_credentials(
			$username,
			$token,
			$is_test,
			function () use ( $divisions ) {
				return $this->build_networks_map_for_divisions( $divisions );
			}
		);

		$this->update_networks_by_division( $map, $is_test );

		return $map;
	}


	/**
	 * Build a `division => networks[]` map by hitting the per-division networks endpoint.
	 *
	 * @param array $divisions Division entries with `name`/`displayName` keys.
	 *
	 * @return array<string,array>
	 */
	private function build_networks_map_for_divisions( array $divisions ) {
		$map = array();

		foreach ( $divisions as $division ) {
			$name = is_array( $division )
				? sanitize_text_field( $division['name'] ?? '' )
				: sanitize_text_field( (string) $division );

			if ( empty( $name ) ) {
				continue;
			}

			$response = $this->api()->request( 'meta/divisions/' . $name . '/networks' );

			$networks = array();
			if ( $response['success'] && ! empty( $response['body'] ) && is_array( $response['body'] ) ) {
				foreach ( $response['body'] as $network ) {
					if ( ! is_array( $network ) || empty( $network['enabled'] ) ) {
						continue;
					}
					$code = sanitize_text_field( $network['code'] ?? '' );
					if ( empty( $code ) || $this->settings()->is_cc_network( $code ) ) {
						continue;
					}
					$networks[] = array(
						'code'  => $code,
						'label' => Utils::format_network_label( $code, $network['label'] ?? '' ),
						'logo'  => esc_url_raw( $network['links']['logo'] ?? '' ),
					);
				}
			}

			$map[ $name ] = $networks;
		}

		return $map;
	}
}
