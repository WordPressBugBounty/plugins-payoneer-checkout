<?php
/**
 * Handle admin notices for Payoneer Checkout.
 */

namespace PayoneerCheckout\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;
use PayoneerCheckout\Gateways\WC_Payoneer_Gateway;
use PayoneerCheckout\Plugin;
use PayoneerCheckout\Utils;

/**
 * Displays contextual admin notices based on gateway state.
 */
final class Notices {

	/** @var Plugin */
	private Plugin $plugin;

	/** @var array */
	private array $messages = array();


	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_notices', array( $this, 'maybe_add_setup_notice' ), 1 );
		add_action( 'admin_notices', array( $this, 'maybe_add_not_connected_notice' ), 1 );
		add_action( 'admin_notices', array( $this, 'maybe_add_no_store_selected_notice' ), 1 );
		add_action( 'admin_notices', array( $this, 'maybe_add_https_required_notice' ), 1 );
		add_action( 'admin_notices', array( $this, 'maybe_add_failed_refund_notice' ), 1 );
		add_action( 'admin_notices', array( $this, 'maybe_add_chargeback_notice' ), 1 );
		add_action( 'admin_notices', array( $this, 'maybe_render_messages' ), 50 );
		add_action( 'current_screen', array( $this, 'maybe_clear_failed_refund_flag_for_current_order' ) );
	}


	/**
	 * Queue a message for rendering.
	 *
	 * @param string $message HTML message content.
	 * @param string $type    Notice type (error, warning, success, info).
	 */
	public function add_message( $message, $type = 'error' ) {
		$this->messages[] = array(
			'message' => $message,
			'type'    => $type,
		);
	}


	/**
	 * Show a setup CTA when the gateway has been installed but is still disabled.
	 */
	public function maybe_add_setup_notice() {
		if ( $this->plugin->is_enabled() ) {
			return;
		}

		if ( Utils::is_settings_page() ) {
			return;
		}

		if ( did_action( 'woocommerce_update_options_payment_gateways_' . WC_Payoneer_Gateway::ID ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: plugin title wrapped in <strong> tags. */
			__( '%s is installed but not yet configured.', 'payoneer-checkout' ),
			'<strong>' . __( 'Payoneer Checkout', 'payoneer-checkout' ) . '</strong>',
		);

		$message .= ' ' . sprintf(
			/* translators: %1$s: opening anchor tag, %2$s: closing anchor tag. */
			__( 'Configure it %1$shere%2$s to start accepting payments.', 'payoneer-checkout' ),
			'<a href="' . esc_url( Utils::settings_url() ) . '">',
			'</a>',
		);

		$this->add_message( $message, 'info' );
	}


	/**
	 * Show a warning when the gateway is enabled but credentials are missing or invalid.
	 */
	public function maybe_add_not_connected_notice() {
		if ( ! $this->plugin->is_enabled() ) {
			return;
		}

		if ( $this->plugin->is_merchant_connected() ) {
			return;
		}

		if ( did_action( 'woocommerce_update_options_payment_gateways_' . WC_Payoneer_Gateway::ID ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: plugin title wrapped in <strong> tags. */
			__( '%s - The credentials are either empty or not valid.', 'payoneer-checkout' ),
			'<strong>' . __( 'Payoneer Checkout', 'payoneer-checkout' ) . '</strong>',
		);

		if ( ! Utils::is_settings_page() ) {
			$message .= ' ' . sprintf(
				/* translators: %1$s: opening anchor tag, %2$s: closing anchor tag. */
				__( 'Verify your connection %1$shere%2$s', 'payoneer-checkout' ),
				'<a href="' . esc_url( Utils::settings_url() ) . '">',
				'</a>',
			);
		}

		$this->add_message( $message, 'warning' );
	}


	/**
	 * Warn when credentials are validated but no store is selected.
	 */
	public function maybe_add_no_store_selected_notice() {
		if ( ! $this->plugin->is_merchant_connected() ) {
			return;
		}

		if ( ! empty( $this->plugin->get_selected_division() ) ) {
			return;
		}

		if ( did_action( 'woocommerce_update_options_payment_gateways_' . WC_Payoneer_Gateway::ID ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: plugin title wrapped in <strong> tags. */
			__( '%s - Credentials are validated but no store is selected.', 'payoneer-checkout' ),
			'<strong>' . __( 'Payoneer Checkout', 'payoneer-checkout' ) . '</strong>',
		);

		if ( Utils::is_settings_page() ) {
			$message .= ' ' . __( 'Select a Store to start accepting payments.', 'payoneer-checkout' );
		} else {
			$message .= ' ' . sprintf(
				/* translators: %1$s: opening anchor tag, %2$s: closing anchor tag. */
				__( 'Select a Store %1$shere%2$s to start accepting payments.', 'payoneer-checkout' ),
				'<a href="' . esc_url( Utils::settings_url() ) . '">',
				'</a>',
			);
		}

		$this->add_message( $message, 'warning' );
	}


	/**
	 * Warn the merchant when the site lacks HTTPS — webhook callbacks are dropped without it.
	 */
	public function maybe_add_https_required_notice() {
		if ( ! $this->plugin->is_enabled() || is_ssl() ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: plugin title wrapped in <strong> tags. */
			__( '%s is enabled but a SSL certificate is not detected. Please ensure your server has a valid SSL certificate to get the plugin working properly.', 'payoneer-checkout' ),
			'<strong>' . __( 'Payoneer Checkout', 'payoneer-checkout' ) . '</strong>'
		);

		$this->add_message( $message, 'warning' );
	}


	/**
	 * Surface flagged failed refunds so the merchant sees them on their next admin visit.
	 */
	public function maybe_add_failed_refund_notice() {
		$flagged = Plugin::get_flagged_order_ids( Plugin::FAILED_REFUNDS_OPTION );

		if ( empty( $flagged ) ) {
			return;
		}

		$links = array();

		foreach ( $flagged as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				Plugin::unflag_order( $order_id, Plugin::FAILED_REFUNDS_OPTION );
				continue;
			}
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">#%s</a>',
				esc_url( $order->get_edit_order_url() ),
				esc_html( $order->get_order_number() )
			);
		}

		if ( empty( $links ) ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: plugin title, 2: comma-separated list of order links. */
			_n(
				'%1$s: a refund failed and was removed. Review order: %2$s',
				'%1$s: refunds failed and have been removed. Review orders: %2$s',
				count( $links ),
				'payoneer-checkout'
			),
			'<strong>' . __( 'Payoneer Checkout', 'payoneer-checkout' ) . '</strong>',
			implode( ', ', $links )
		);

		$this->add_message( $message, 'error' );
	}


	/**
	 * Surface flagged chargebacks so the merchant sees them on their next admin visit.
	 */
	public function maybe_add_chargeback_notice() {
		$flagged = Plugin::get_flagged_order_ids( Plugin::CHARGEBACK_ORDERS_OPTION );

		if ( empty( $flagged ) ) {
			return;
		}

		$links = array();

		foreach ( $flagged as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				Plugin::unflag_order( $order_id, Plugin::CHARGEBACK_ORDERS_OPTION );
				continue;
			}
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">#%s</a>',
				esc_url( $order->get_edit_order_url() ),
				esc_html( $order->get_order_number() )
			);
		}

		if ( empty( $links ) ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: plugin title, 2: comma-separated list of order links. */
			_n(
				'%1$s: a payment was charged back. Review order: %2$s',
				'%1$s: payments were charged back. Review orders: %2$s',
				count( $links ),
				'payoneer-checkout'
			),
			'<strong>' . __( 'Payoneer Checkout', 'payoneer-checkout' ) . '</strong>',
			implode( ', ', $links )
		);

		$this->add_message( $message, 'error' );
	}


	/**
	 * Clear the flag for the order currently being viewed in the admin.
	 */
	public function maybe_clear_failed_refund_flag_for_current_order() {
		if ( ! is_admin() || ! class_exists( OrderUtil::class ) || ! OrderUtil::is_order_edit_screen() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : null;
		if ( empty( $order_id ) ) {
			$order_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $order_id > 0 ) {
			Plugin::unflag_order( $order_id, Plugin::FAILED_REFUNDS_OPTION );
			Plugin::unflag_order( $order_id, Plugin::CHARGEBACK_ORDERS_OPTION );
		}
	}


	/**
	 * Render all queued messages.
	 */
	public function maybe_render_messages() {
		if ( empty( $this->messages ) ) {
			return;
		}

		foreach ( $this->messages as $message ) {
			self::render_admin_notice( $message['message'], $message['type'] );
		}
	}


	/**
	 * Output a single admin notice.
	 *
	 * @param string $message HTML message content.
	 * @param string $type    Notice type.
	 */
	public static function render_admin_notice( $message, $type = 'error' ) {
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?>">
			<p><?php echo wp_kses_post( $message ); ?></p>
		</div>
		<?php
	}
}
