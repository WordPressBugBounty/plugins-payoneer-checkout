<?php
/**
 * WooCommerce Checkout Blocks payment method integration for extra network gateways.
 */

namespace PayoneerCheckout\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use PayoneerCheckout\Plugin;
use PayoneerCheckout\Utils;

/**
 * Blocks payment method type for a single extra Payoneer network (e.g. PayPal, Klarna).
 */
final class PayoneerNetworkBlocksSupport extends AbstractPaymentMethodType {

	/**
	 * Payment method name/id/slug.
	 *
	 * @var string
	 */
	protected $name;

	/** @var string */
	private string $network_code;

	/** @var array */
	private array $network_data;


	public function __construct( string $network_code, array $network_data ) {
		$this->network_code = $network_code;
		$this->network_data = $network_data;
		$this->name         = 'payoneer-' . strtolower( $network_code );
	}


	/**
	 * Load gateway settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_payoneer_checkout_settings', array() );
	}


	/**
	 * Whether the gateway is active and credentials are validated.
	 *
	 * @return bool
	 */
	public function is_active() {
		$plugin = Plugin::instance();

		return $plugin->is_enabled()
			&& $plugin->get_validated_credentials()
			&& $plugin->is_embedded_integration();
	}


	/**
	 * Share the same JS bundle as the main Blocks payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		return array( 'payoneer-checkout-blocks' );
	}


	/**
	 * Payment method data passed to the blocks checkout JS.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$plugin       = Plugin::instance();
		$is_test      = $plugin->is_test_mode();
		$net_settings = $plugin->get_network_settings( $is_test );
		$overrides    = $net_settings[ $this->network_code ] ?? array();

		return array_merge(
			PayoneerCheckoutBlocksSupport::base_checkout_data(),
			array(
				'title'       => Utils::apply_test_prefix( $overrides['title'] ?? Utils::format_network_label( $this->network_code, $this->network_data['label'] ?? '' ), $is_test ),
				'description' => $overrides['description'] ?? '',
				'supports'    => array( 'products' ),
				'icon'        => $this->network_data['logo'] ?? '',
				'dropin_name' => strtolower( $this->network_code ),
			)
		);
	}
}
