<?php
/**
 * Main class.
 */

namespace PayoneerCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PayoneerCheckout\Admin\Assets as AdminAssets;
use PayoneerCheckout\Front\Assets as FrontAssets;

/**
 * Base Plugin class holding generic functionality
 */
final class Main {

	/**
	 * Set the minimum required versions for the plugin.
	 */
	const PLUGIN_REQUIREMENTS = array(
		'php_version' => '7.4',
		'wp_version'  => '6.5',
		'wc_version'  => '8.6',
	);


	/**
	 * Constructor
	 */
	public static function bootstrap() {

		register_activation_hook( PLUGIN_FILE, array( Install::class, 'activate' ) );
		register_deactivation_hook( PLUGIN_FILE, array( Install::class, 'uninstall' ) );

		Install::init();

		Rewrites::bootstrap_hooks();

		add_action( 'plugins_loaded', array( self::class, 'load' ), 1 );

		add_action( 'init', array( self::class, 'init' ) );

		// Perform other actions when plugin file is parsed.
		do_action( 'payoneer_checkout_bootstrap' );

		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( self::class, 'plugin_action_links' ) );

		// Declare compatibility with WooCommerce Blocks and HPOS.
		add_action(
			'before_woocommerce_init',
			function () {
				if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PLUGIN_FILE, true );
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PLUGIN_FILE, true );
				}
			}
		);
	}


	/**
	 * Cloning is forbidden.
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'payoneer-checkout' ), '4.0.0' );
	}


	/**
	 * Unserializing instances of this class is forbidden.
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'payoneer-checkout' ), '4.0.0' );
	}


	/**
	 * Include plugins files and hook into actions and filters.
	 */
	public static function load() {

		if ( ! self::check_plugin_requirements() ) {
			return;
		}

		if ( Utils::is_request( 'admin' ) ) {
			AdminAssets::hooks();
		}

		if ( Utils::is_request( 'frontend' ) ) {
			FrontAssets::hooks();
		}

		// Common includes.
		Rewrites::hooks();
		Plugin::instance();

		// Register the Blocks checkout payment method integration.
		add_action(
			'woocommerce_blocks_loaded',
			function () {
				if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
					add_action(
						'woocommerce_blocks_payment_method_type_registration',
						function ( $registry ) {
							$registry->register( new \PayoneerCheckout\Blocks\PayoneerCheckoutBlocksSupport() );

							$plugin = Plugin::instance();
							if ( $plugin->is_enabled() && $plugin->get_validated_credentials() && $plugin->is_embedded_integration() ) {
								$is_test  = $plugin->is_test_mode();
								$networks = $plugin->get_networks( $is_test );
								$enabled  = $plugin->get_enabled_networks( $is_test );
								$settings = $plugin->settings();

								if ( is_array( $networks ) && is_array( $enabled ) ) {
									foreach ( $enabled as $code ) {
										if ( $settings->is_cc_network( $code ) || ! isset( $networks[ $code ] ) ) {
											continue;
										}
										$registry->register(
											new \PayoneerCheckout\Blocks\PayoneerNetworkBlocksSupport( $code, $networks[ $code ] )
										);
									}
								}
							}
						}
					);
				}
			}
		);

		// Loaded action.
		do_action( 'payoneer_checkout_loaded' );
	}


	/**
	 * Method called by init hook
	 *
	 * @return void
	 */
	public static function init() {

		// Before init action.
		do_action( 'before_payoneer_checkout_init' );

		// After init action.
		do_action( 'payoneer_checkout_init' );
	}


	/**
	 * Add Settings link to the plugin action links.
	 *
	 * @param array $links Plugin action links.
	 *
	 * @return array
	 */
	public static function plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( Utils::settings_url() ),
			esc_html__( 'Settings', 'payoneer-checkout' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}


	private static function check_plugin_requirements() {

		$errors = array();
		global $wp_version;

		if ( ! version_compare( PHP_VERSION, self::PLUGIN_REQUIREMENTS['php_version'], '>=' ) ) {
			/* Translators: The minimum PHP version */
			$errors[] = sprintf( esc_html__( 'Payoneer Checkout requires a minimum PHP version of %s.', 'payoneer-checkout' ), self::PLUGIN_REQUIREMENTS['php_version'] );
		}

		if ( ! version_compare( $wp_version, self::PLUGIN_REQUIREMENTS['wp_version'], '>=' ) ) {
			/* Translators: The minimum WP version */
			$errors[] = sprintf( esc_html__( 'Payoneer Checkout requires a minimum WordPress version of %s.', 'payoneer-checkout' ), self::PLUGIN_REQUIREMENTS['wp_version'] );
		}

		if ( isset( self::PLUGIN_REQUIREMENTS['wc_version'] ) && ( ! defined( 'WC_VERSION' ) || ! version_compare( WC_VERSION, self::PLUGIN_REQUIREMENTS['wc_version'], '>=' ) ) ) {
			/* Translators: The minimum WC version */
			$errors[] = sprintf( esc_html__( 'Payoneer Checkout requires a minimum WooCommerce version of %s.', 'payoneer-checkout' ), self::PLUGIN_REQUIREMENTS['wc_version'] );
		}

		if ( empty( $errors ) ) {
			return true;
		}

		if ( Utils::is_request( 'admin' ) ) {

			add_action(
				'admin_notices',
				function () use ( $errors ) {
					?>
					<div class="notice notice-error">
						<?php
						foreach ( $errors as $error ) {
							echo '<p>' . esc_html( $error ) . '</p>';
						}
						?>
					</div>
					<?php
				}
			);

			return;
		}

		return false;
	}
}
