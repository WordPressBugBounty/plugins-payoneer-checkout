<?php
/**
 * Register frontend assets.
 */

namespace PayoneerCheckout\Front;

use PayoneerCheckout\Assets as AssetsMain;
use PayoneerCheckout\Plugin;
use PayoneerCheckout\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend assets class
 */
final class Assets {

	/**
	 * Hook in methods.
	 */
	public static function hooks() {
		add_filter( 'payoneer_checkout_enqueue_styles', array( self::class, 'add_styles' ), 9 );
		add_filter( 'payoneer_checkout_enqueue_scripts', array( self::class, 'add_scripts' ), 9 );
		add_action( 'wp_enqueue_scripts', array( AssetsMain::class, 'load_scripts' ) );
		add_action( 'wp_print_scripts', array( AssetsMain::class, 'localize_printed_scripts' ), 5 );
		add_action( 'wp_print_footer_scripts', array( AssetsMain::class, 'localize_printed_scripts' ), 5 );
	}


	/**
	 * Add styles for the frontend.
	 *
	 * @param array $styles Frontend styles.
	 *
	 * @return array<string,array>
	 */
	public static function add_styles( $styles ) {

		$styles['payoneer-checkout-general'] = array(
			'src' => AssetsMain::localize_asset( 'css/frontend/payoneer-checkout.css' ),
		);

		return $styles;
	}


	/**
	 * Add scripts for the frontend.
	 *
	 * @param  array $scripts Frontend scripts.
	 *
	 * @return array<string,array>
	 */
	public static function add_scripts( $scripts ) {

		$deps = array( 'jquery', 'wp-i18n' );

		if ( Plugin::instance()->is_embedded_integration() ) {
			$scripts['payoneer-checkout-web'] = array(
				'src'     => AssetsMain::localize_asset( 'js/frontend/checkout-web.min.js' ),
				'deps'    => array(),
				'enqueue' => false,
			);

			$deps[] = 'payoneer-checkout-web';
		}

		$scripts['payoneer-checkout-general'] = array(
			'src'  => AssetsMain::localize_asset( 'js/frontend/payoneer-checkout.js' ),
			'deps' => $deps,
			'data' => array(
				'payment_flow'     => Plugin::instance()->get_integration_type(),
				'env'              => Plugin::instance()->get_checkout_environment(),
				'ajax_url'         => \WC_AJAX::get_endpoint( '%%endpoint%%' ),
				'enabled_networks' => self::get_enabled_network_data(),
				'reset_nonce'      => Plugin::instance()->get_reset_nonce(),
				'tab_token'        => Utils::page_tab_token(),
			),
		);

		return $scripts;
	}


	/**
	 * Build the enabled non-CC network data for the frontend.
	 *
	 * @return array
	 */
	private static function get_enabled_network_data() {
		$plugin   = Plugin::instance();
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
				'label' => $network['label'] ?? ucfirst( strtolower( $code ) ),
				'logo'  => $network['logo'] ?? '',
			);
		}

		return $result;
	}
}
