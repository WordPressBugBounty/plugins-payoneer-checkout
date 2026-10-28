<?php
/**
 * WooCommerce Checkout Blocks payment method integration.
 */

namespace PayoneerCheckout\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use PayoneerCheckout\Gateways\WC_Payoneer_Gateway;
use PayoneerCheckout\Plugin;
use PayoneerCheckout\Utils;
use const PayoneerCheckout\VERSION;

/**
 * Blocks payment method type for Payoneer Checkout.
 */
final class PayoneerCheckoutBlocksSupport extends AbstractPaymentMethodType {

	/**
	 * Payment method name/id/slug.
	 *
	 * @var string
	 */
	protected $name = 'payoneer-checkout';


	/**
	 * Load gateway settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_payoneer_checkout_settings', array() );

		$this->register_cart_extension();
	}


	/**
	 * Register a Store API cart extension exposing the current LIST payload hash.
	 */
	private function register_cart_extension() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => 'cart',
				'namespace'       => $this->name,
				'data_callback'   => function () {
					return array(
						'list_hash' => strval( Utils::get_session_key( 'list_payload_hash' ) ),
					);
				},
				'schema_callback' => function () {
					return array(
						'list_hash' => array(
							'description' => __( 'Hash of the current Payoneer LIST payload; changes when the LIST is updated server-side.', 'payoneer-checkout' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
					);
				},
				'schema_type'     => ARRAY_A,
			)
		);
	}


	/**
	 * Whether the gateway is active and credentials are validated.
	 *
	 * @return bool
	 */
	public function is_active() {
		$plugin = Plugin::instance();

		return $plugin->is_enabled() && $plugin->get_validated_credentials();
	}


	/**
	 * Register and return the script handles for the payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$script_asset_path = Utils::plugin_path() . '/assets/js/payment-methods/index.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => VERSION,
			);

		$dependencies = array_merge(
			$script_asset['dependencies'],
			array( 'wc-blocks-registry', 'wc-settings' )
		);

		if ( Plugin::instance()->is_embedded_integration() ) {
			wp_register_script(
				'payoneer-checkout-web',
				Utils::plugin_url() . '/assets/js/frontend/checkout-web.min.js',
				array(),
				VERSION,
				true
			);
			$dependencies[] = 'payoneer-checkout-web';
		}

		wp_register_script(
			'payoneer-checkout-blocks',
			Utils::plugin_url() . '/assets/js/payment-methods/index.js',
			$dependencies,
			$script_asset['version'],
			true
		);

		wp_register_style(
			'payoneer-checkout-blocks-style',
			Utils::plugin_url() . '/assets/css/frontend/payoneer-checkout.css',
			array(),
			VERSION
		);
		wp_enqueue_style( 'payoneer-checkout-blocks-style' );

		return array( 'payoneer-checkout-blocks' );
	}


	/**
	 * Get the shared embedded checkout data used by all Payoneer payment methods.
	 *
	 * @return array
	 */
	public static function base_checkout_data() {
		$plugin = Plugin::instance();

		if ( Utils::is_blocks_checkout_page() ) {
			$plugin->recover_stuck_pending_order( false );
		}

		$data = array(
			'env'         => $plugin->get_checkout_environment(),
			'ajax_url'    => \WC_AJAX::get_endpoint( '%%endpoint%%' ),
			'reset_nonce' => $plugin->get_reset_nonce(),
			'tab_token'   => Utils::page_tab_token(),
		);

		if ( $plugin->is_embedded_integration() ) {
			$data['long_id'] = Utils::is_blocks_checkout_page() ? WC_Payoneer_Gateway::get_list_id() : Utils::get_session_key( 'list_long_id' );
		}

		return $data;
	}


	/**
	 * Payment method data passed to the blocks checkout JS.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$plugin  = Plugin::instance();
		$gateway = $plugin->gateway();

		return array_merge(
			self::base_checkout_data(),
			array(
				'title'            => $gateway->get_title(),
				'description'      => $gateway->get_description(),
				'supports'         => array( 'products' ),
				'payment_flow'     => $plugin->get_integration_type(),
				'icon'             => $gateway->resolve_icon(),
				'enabled_networks' => $this->get_enabled_network_data( $plugin ),
			)
		);
	}


	/**
	 * Build the enabled non-CC network data for blocks checkout.
	 *
	 * @param Plugin $plugin The plugin instance.
	 *
	 * @return array
	 */
	private function get_enabled_network_data( $plugin ) {
		$is_test  = $plugin->is_test_mode();
		$networks = $plugin->get_networks( $is_test );
		$enabled  = $plugin->get_enabled_networks( $is_test );
		$settings = $plugin->settings();

		if ( ! is_array( $networks ) || ! is_array( $enabled ) ) {
			return array();
		}

		$result = array();

		foreach ( $enabled as $code ) {
			if ( $settings->is_cc_network( $code ) ) {
				continue;
			}

			$network = $networks[ $code ] ?? null;

			if ( ! $network ) {
				continue;
			}

			$result[] = array(
				'code'  => $code,
				'label' => Utils::format_network_label( $code, $network['label'] ?? '' ),
				'logo'  => $network['logo'] ?? '',
			);
		}

		return $result;
	}
}
