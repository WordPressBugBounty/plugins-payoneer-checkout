<?php
/**
 * Network gateway class.
 */

namespace PayoneerCheckout\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PayoneerCheckout\Plugin;
use PayoneerCheckout\Utils;
use WC_HTTPS;
use WC_Payment_Gateway;

/**
 * Checkout gateway for an individual Payoneer payment network.
 */
class WC_Payoneer_Network_Gateway extends WC_Payment_Gateway {

	/** @var string */
	private string $network_code;


	/**
	 * Constructor.
	 *
	 * @param string    $network_code     Network code (e.g. 'Afterpay').
	 * @param array     $network_data     Network data with 'label' and 'logo' keys.
	 * @param array     $network_settings Per-network title and description overrides.
	 * @param bool|null $is_test          Sandbox mode; null resolves from the plugin's current mode.
	 */
	public function __construct( string $network_code, array $network_data, array $network_settings = array(), ?bool $is_test = null ) {
		$this->network_code = $network_code;

		$is_test = $is_test ?? Plugin::instance()->is_test_mode();
		$label   = Utils::format_network_label( $network_code, $network_data['label'] ?? '' );

		$this->id                 = 'payoneer-' . strtolower( $network_code );
		$this->method_title       = 'Payoneer - ' . $label;
		$this->method_description = __( 'Payoneer Checkout payment method managed through Payoneer Checkout settings.', 'payoneer-checkout' );
		$this->has_fields         = true;
		$this->enabled            = 'yes';
		$this->supports           = array( 'products', 'refunds' );

		$this->title       = Utils::apply_test_prefix( $network_settings['title'] ?? $label, $is_test );
		$this->description = $network_settings['description'] ?? '';
		$this->icon        = $network_data['logo'] ?? '';
	}


	/**
	 * Get the network code.
	 *
	 * @return string
	 */
	public function get_network_code() {
		return $this->network_code;
	}


	/**
	 * Render the gateway icon with lazy-load attributes (WC core renders without them).
	 *
	 * @return string
	 */
	public function get_icon() {
		if ( empty( $this->icon ) ) {
			return '';
		}

		return apply_filters(
			'woocommerce_gateway_icon',
			sprintf(
				'<img src="%s" alt="%s" loading="lazy" decoding="async" />',
				esc_url( WC_HTTPS::force_https_url( $this->icon ) ),
				esc_attr( $this->get_title() )
			),
			$this->id
		);
	}


	/**
	 * Render admin options with a link to the main settings page.
	 *
	 * @return void
	 */
	public function admin_options() {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . WC_Payoneer_Gateway::ID );
		$link         = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Payoneer Checkout settings page', 'payoneer-checkout' ) . '</a>';
		?>
		<div class="notice notice-info inline">
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: link to the main Payoneer Checkout settings page */
						__( 'This payment method is managed through the %s.', 'payoneer-checkout' ),
						$link
					)
				);
				?>
			</p>
		</div>
		<?php
	}


	/**
	 * Settings URL pointing to the main Payoneer Checkout settings page.
	 *
	 * @return string
	 */
	public function get_settings_url() {
		return Utils::settings_url();
	}


	/**
	 * Render payment fields on the checkout page.
	 *
	 * @return void
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( '<p>' . $this->description . '</p>' );
		}

		printf(
			'<div class="payoneer-dropin-mount" data-dropin-name="%s" data-long-id="%s"></div>',
			esc_attr( strtolower( $this->network_code ) ),
			esc_attr( WC_Payoneer_Gateway::get_list_id() )
		);
	}


	/**
	 * Available at checkout when the main gateway is active, connected, and this network is applicable.
	 *
	 * @return bool
	 */
	public function is_available() {
		$plugin = Plugin::instance();

		if ( ! $plugin->is_enabled() || ! $plugin->get_validated_credentials() || $plugin->is_hosted_integration() ) {
			return false;
		}

		return in_array( $this->network_code, $plugin->get_applicable_networks(), true );
	}


	/**
	 * No settings form for network gateways.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array();
	}


	/**
	 * Skip parent's option load so no per-network settings row is created.
	 *
	 * @return void
	 */
	public function init_settings() {
		$this->settings = array();
	}


	/**
	 * Proxy option reads to the main gateway's settings.
	 *
	 * @param string $key         Setting key.
	 * @param mixed  $empty_value Default when the option is missing.
	 *
	 * @return mixed
	 */
	public function get_option( $key, $empty_value = null ) {
		$plugin = Plugin::instance();

		if ( 'merchant_code' === $key && $plugin->is_test_mode() ) {
			$sandbox = $plugin->get_gateway_setting( 'sandbox_merchant_code' );
			if ( ! empty( $sandbox ) ) {
				return $sandbox;
			}
		}

		$value = $plugin->get_gateway_setting( $key );

		if ( '' === $value && null !== $empty_value ) {
			return $empty_value;
		}

		return $value;
	}


	/**
	 * Whether the gateway needs setup before it can be used.
	 *
	 * @return bool
	 */
	public function needs_setup() {
		return ! Plugin::instance()->get_validated_credentials();
	}


	/**
	 * Process the payment by delegating to the main gateway.
	 *
	 * @param int $order_id WooCommerce order ID.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order   = wc_get_order( $order_id );
		$gateway = Plugin::instance()->gateway();

		if ( ! $gateway ) {
			wc_add_notice( __( 'Payment processing failed. Please try again.', 'payoneer-checkout' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->set_payment_method( 'payoneer-' . strtolower( $this->network_code ) );
		$order->save();

		return $gateway->process_network_embedded_payment( $order, $this->network_code );
	}


	/**
	 * Delegate refunds to the main gateway, which holds the shared payout logic.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Refund amount.
	 * @param string     $reason   Refund reason.
	 *
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$gateway = Plugin::instance()->gateway();

		if ( ! $gateway ) {
			return new \WP_Error( 'payoneer_refund_error', __( 'Payoneer gateway unavailable.', 'payoneer-checkout' ) );
		}

		return $gateway->process_refund( $order_id, $amount, $reason );
	}
}
