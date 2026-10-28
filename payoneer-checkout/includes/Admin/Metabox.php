<?php
/**
 * Metabox for refreshing Payoneer payment status on orders.
 */

namespace PayoneerCheckout\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PayoneerCheckout\Gateways\WC_Payoneer_Gateway;
use PayoneerCheckout\Plugin;

/**
 * Registers a side metabox on orders awaiting webhook confirmation.
 */
final class Metabox {

	/** @var Plugin */
	private Plugin $plugin;


	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}


	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'maybe_add_metabox' ), 10, 2 );
		add_action( 'wp_ajax_payoneer_refresh_order_status', array( $this, 'handle_refresh_action' ) );
	}


	/**
	 * Conditionally add the metabox if the order is awaiting webhook confirmation.
	 *
	 * @param string             $screen_id     Screen ID or post type.
	 * @param \WC_Order|\WP_Post $post_or_order Order or post object.
	 */
	public function maybe_add_metabox( $screen_id, $post_or_order ) {
		if ( ! in_array( $screen_id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		$order = wc_get_order( $post_or_order );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! WC_Payoneer_Gateway::is_payoneer_payment_method( $order->get_payment_method() ) ) {
			return;
		}

		if ( ! $order->get_meta( '_payoneer_awaiting_webhook', true ) ) {
			return;
		}

		$order_date = $order->get_date_created();

		if ( ! $order_date || ( time() - $order_date->getTimestamp() ) < DAY_IN_SECONDS ) {
			return;
		}

		add_meta_box(
			'payoneer_refresh_status',
			__( 'Payoneer Payment Status', 'payoneer-checkout' ),
			array( $this, 'render' ),
			$screen_id,
			'side',
			'high'
		);
	}


	/**
	 * Render the metabox content.
	 *
	 * @param \WC_Order|\WP_Post $post_or_order Order or post object.
	 */
	public function render( $post_or_order ) {
		$order = wc_get_order( $post_or_order );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$url = wp_nonce_url( admin_url( 'admin-ajax.php?action=payoneer_refresh_order_status&order_id=' . $order->get_id() ), 'payoneer-refresh-order-status' );
		?>
		<p><?php esc_html_e( 'The payment webhook has not been received yet. Click below to manually check the payment status.', 'payoneer-checkout' ); ?></p>
		<a href="<?php echo esc_url( $url ); ?>" class="button button-primary">
			<?php esc_html_e( 'Refresh Payment Status', 'payoneer-checkout' ); ?>
		</a>
		<?php
	}


	/**
	 * Handle the refresh action form submission.
	 */
	public function handle_refresh_action() {
		$order_id = absint( $_GET['order_id'] ?? 0 );

		if ( ! $order_id ) {
			wp_die( esc_html__( 'Invalid order.', 'payoneer-checkout' ) );
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'payoneer-checkout' ) );
		}

		if ( ! check_admin_referer( 'payoneer-refresh-order-status' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'payoneer-checkout' ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Invalid order.', 'payoneer-checkout' ) );
		}

		$this->pull_list_status( $order );

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}


	/**
	 * Fetch the LIST status from Payoneer and apply it to the order.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 */
	private function pull_list_status( $order ) {
		$long_id = $order->get_meta( '_payoneer-long-id', true );

		if ( empty( $long_id ) ) {
			return;
		}

		$response = $this->plugin->api()->get_list( $long_id );

		if ( ! $response['success'] || empty( $response['body'] ) ) {
			$this->plugin->logger()->log(
				sprintf(
					'LIST status pull failed for order #%s [longId=%s]: %s',
					$order->get_order_number(),
					$long_id,
					$response['error'] ?? 'Unknown'
				),
				'error'
			);
			return;
		}

		$body        = $response['body'];
		$status_code = Plugin::derive_list_status_code( $body );

		if ( empty( $status_code ) || in_array( $status_code, array( 'listed', 'pending' ), true ) ) {
			$this->plugin->logger()->log(
				sprintf(
					'LIST status pull for order #%s: status is "%s" (no terminal status yet)',
					$order->get_order_number(),
					$status_code
				)
			);
			return;
		}

		$data = array(
			'longId'        => $long_id,
			'transactionId' => sanitize_text_field( $body['identification']['transactionId'] ?? '' ),
			'statusCode'    => $status_code,
			'reason'        => sanitize_text_field( $body['status']['reason'] ?? '' ),
			'network'       => sanitize_text_field( $body['network'] ?? '' ),
			'shortId'       => sanitize_text_field( $body['identification']['shortId'] ?? '' ),
			'resultInfo'    => sanitize_text_field( $body['resultInfo'] ?? '' ),
		);

		$gateway = $this->plugin->gateway();

		if ( ! $gateway ) {
			$this->plugin->logger()->log(
				sprintf( 'Gateway unavailable; cannot apply LIST status for order #%d', $order->get_id() ),
				'error'
			);
			return;
		}

		$fresh_order = wc_get_order( $order->get_id() );

		if ( ! $fresh_order instanceof \WC_Order ) {
			return;
		}

		$gateway->apply_payment_to_order( $fresh_order, $data );
	}
}
