<?php
/**
 * Register admin assets.
 */

namespace PayoneerCheckout\Admin;

use PayoneerCheckout\Assets as AssetsMain;
use PayoneerCheckout\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin assets class
 */
final class Assets {

	/**
	 * Hook in methods.
	 */
	public static function hooks() {
		add_filter( 'payoneer_checkout_enqueue_styles', array( self::class, 'add_styles' ), 9 );
		add_filter( 'payoneer_checkout_enqueue_scripts', array( self::class, 'add_scripts' ), 9 );
		add_action( 'admin_enqueue_scripts', array( AssetsMain::class, 'load_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'set_script_translations' ), 20 );
		add_action( 'admin_print_scripts', array( AssetsMain::class, 'localize_printed_scripts' ), 5 );
		add_action( 'admin_print_footer_scripts', array( AssetsMain::class, 'localize_printed_scripts' ), 5 );
	}


	/**
	 * Set script translations for admin scripts.
	 */
	public static function set_script_translations() {
		wp_set_script_translations( 'payoneer-checkout-admin', 'payoneer-checkout' );
	}


	/**
	 * Add styles for the admin.
	 *
	 * @param array $styles Admin styles.
	 *
	 * @return array<string,array>
	 */
	public static function add_styles( $styles ) {

		$styles['payoneer-checkout-admin'] = array(
			'src' => AssetsMain::localize_asset( 'css/admin/payoneer-checkout-admin.css' ),
		);

		return $styles;
	}


	/**
	 * Add scripts for the admin.
	 *
	 * @param  array $scripts Admin scripts.
	 *
	 * @return array<string,array>
	 */
	public static function add_scripts( $scripts ) {

		$deps = array( 'jquery', 'wp-i18n' );

		if ( Utils::is_settings_page() ) {
			$deps[] = 'jquery-ui-datepicker';
			$deps[] = 'wc-admin-system-status';

			self::register_wc_system_status_script();
		}

		$scripts['payoneer-checkout-admin'] = array(
			'src'  => AssetsMain::localize_asset( 'js/admin/payoneer-checkout-admin.js' ),
			'deps' => $deps,
			'data' => function () {
				$plugin = \PayoneerCheckout\Plugin::instance();
				return array(
					'ajax_url'             => admin_url( 'admin-ajax.php' ),
					'validate_nonce'       => wp_create_nonce( 'payoneer_checkout_validate_credentials' ),
					'is_test_validated'    => $plugin->get_validated_credentials( true ),
					'is_live_validated'    => $plugin->get_validated_credentials( false ),
					'networks_by_division' => (object) $plugin->get_networks_by_division(),
					'network_order'        => Utils::NETWORK_ORDER,
					'disabled_networks'    => array(
						'test' => array_values( $plugin->get_disabled_networks( true ) ),
						'live' => array_values( $plugin->get_disabled_networks( false ) ),
					),
				);
			},
		);

		return $scripts;
	}


	/**
	 * Register WC's `wc-admin-system-status` handle on the gateway settings page.
	 */
	private static function register_wc_system_status_script() {
		if ( wp_script_is( 'wc-admin-system-status', 'registered' ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$suffix  = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$version = defined( 'WC_VERSION' ) ? WC_VERSION : false;
		$url     = WC()->plugin_url() . '/assets/js/admin/system-status' . $suffix . '.js';

		wp_register_script( 'wc-admin-system-status', $url, array( 'jquery', 'wc-clipboard' ), $version, true );
	}
}
