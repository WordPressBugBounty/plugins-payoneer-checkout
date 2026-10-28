<?php
/**
 * Install / upgrade routines.
 */

namespace PayoneerCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Install class. Handles activation/deactivation and version-keyed update callbacks.
 */
final class Install {

	const VERSION_OPTION = 'payoneer_plugin_version';

	const LEGACY_SIGNATURE_OPTION = 'payoneer_checkout_legacy_signature';

	/**
	 * Version-keyed update callbacks.
	 *
	 * @var array<string,array<int,string>>
	 */
	private static array $updates = array(
		'4.0.0' => array( 'update_400_migrate_legacy_settings' ),
	);

	/**
	 * Network gateway suffixes.
	 *
	 * @var array<int,string>
	 */
	private static array $network_ids = array( 'afterpay', 'klarna', 'affirm', 'bancontact', 'eps', 'ideal', 'multibanco', 'p24' );

	/**
	 * Hook the version check on every load.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'check_version' ), 5 );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_reconcile_legacy' ), 6 );
	}


	/**
	 * Compare the stored plugin version against the code version and run install when behind.
	 */
	public static function check_version() {
		$current = (string) get_option( self::VERSION_OPTION, '' );
		$code    = (string) VERSION;

		if ( '' === $current || version_compare( $current, $code, '<' ) ) {
			self::install( $current );
		}
	}


	/**
	 * Activation hook entry point.
	 *
	 * @param bool $sitewide Whether the activation is network-wide.
	 */
	public static function activate( $sitewide = false ) {
		self::install( (string) get_option( self::VERSION_OPTION, '' ) );

		do_action( 'payoneer_checkout_installed', $sitewide );
	}


	/**
	 * Deactivation hook entry point.
	 *
	 * @param bool $sitewide Whether the deactivation is network-wide.
	 */
	public static function uninstall( $sitewide = false ) {
		do_action( 'payoneer_checkout_uninstalled', $sitewide );
	}


	/**
	 * Run version-keyed update callbacks then stamp the current version.
	 *
	 * @param string $current_version Previously stored version, or empty on fresh install.
	 */
	private static function install( $current_version ) {
		self::update( $current_version );

		update_option( self::VERSION_OPTION, VERSION, false );
	}


	/**
	 * Run every update callback whose version is newer than $current_version.
	 *
	 * @param string $current_version Previously stored version, or empty on fresh install.
	 */
	private static function update( $current_version ) {
		foreach ( self::$updates as $version => $callbacks ) {
			if ( '' !== $current_version && ! version_compare( $current_version, $version, '<' ) ) {
				continue;
			}

			foreach ( $callbacks as $callback ) {
				if ( is_callable( array( __CLASS__, $callback ) ) ) {
					call_user_func( array( __CLASS__, $callback ) );
				}
			}
		}
	}


	/**
	 * Consolidate legacy gateway options into the current settings array.
	 */
	private static function update_400_migrate_legacy_settings() {
		$legacy_primary = (array) get_option( 'woocommerce_payoneer-checkout_settings', array() );

		if ( empty( $legacy_primary ) ) {
			return;
		}

		$existing = (array) get_option( 'woocommerce_payoneer_checkout_settings', array() );

		$merchant_code  = $legacy_primary['merchant_code'] ?? '';
		$merchant_token = $legacy_primary['merchant_token'] ?? '';

		$sandbox_code  = $legacy_primary['sandbox_merchant_code'] ?? '';
		$sandbox_code  = '' !== $sandbox_code ? $sandbox_code : $merchant_code;
		$sandbox_token = $legacy_primary['sandbox_merchant_token'] ?? '';
		$sandbox_token = '' !== $sandbox_token ? $sandbox_token : $merchant_token;

		$title       = $legacy_primary['title-payoneer-checkout'] ?? '';
		$description = $legacy_primary['description-payoneer-checkout'] ?? '';

		$network_settings = array();
		foreach ( self::$network_ids as $id ) {
			$network_title = $legacy_primary[ 'title-payoneer-' . $id ] ?? '';
			if ( '' === $network_title ) {
				continue;
			}

			$network_settings[ strtoupper( $id ) ] = array(
				'title'       => $network_title,
				'description' => $legacy_primary[ 'description-payoneer-' . $id ] ?? '',
			);
		}

		$legacy_owned = array(
			'enabled'                => $legacy_primary['enabled'] ?? 'no',
			'live_mode'              => $legacy_primary['live_mode'] ?? 'no',
			'merchant_code'          => $merchant_code,
			'merchant_token'         => $merchant_token,
			'sandbox_merchant_code'  => $sandbox_code,
			'sandbox_merchant_token' => $sandbox_token,
			'store_code'             => $legacy_primary['store_code'] ?? '',
			'sandbox_store_code'     => $legacy_primary['sandbox_store_code'] ?? '',
			'payment_flow'           => $legacy_primary['payment_flow'] ?? '',
			'title'                  => $title,
			'description'            => $description,
			'test_title'             => $legacy_primary['test_title'] ?? $title,
			'test_description'       => $legacy_primary['test_description'] ?? '',
			'hosted_title'           => $legacy_primary['title-payoneer-hosted'] ?? '',
			'hosted_description'     => $legacy_primary['description-payoneer-hosted'] ?? '',
			'network_settings'       => $network_settings,
			'test_network_settings'  => $network_settings,
		);

		$merged = array_merge(
			array(
				'enabled_networks'       => array(),
				'test_enabled_networks'  => array(),
				'disabled_networks'      => array(),
				'test_disabled_networks' => array(),
			),
			$existing,
			$legacy_owned
		);

		// Do not assume that legacy credentials are valid.
		$merged['validated_credentials']      = '';
		$merged['test_validated_credentials'] = '';

		update_option( 'woocommerce_payoneer_checkout_settings', $merged );
		update_option( self::LEGACY_SIGNATURE_OPTION, self::legacy_signature( $legacy_primary ), false );

		// Validate the migrated credentials and pull divisions/networks.
		$plugin = Plugin::instance();
		self::fetch_mode_data( $plugin, true );
		self::fetch_mode_data( $plugin, false );
	}


	/**
	 * Re-import the legacy option when v3 changed it while v4 was inactive (downgrade then re-upgrade).
	 */
	public static function maybe_reconcile_legacy() {
		$legacy = (array) get_option( 'woocommerce_payoneer-checkout_settings', array() );
		if ( empty( $legacy ) ) {
			return;
		}

		$current = self::legacy_signature( $legacy );
		$stored  = (string) get_option( self::LEGACY_SIGNATURE_OPTION, '' );

		if ( '' === $stored ) {
			update_option( self::LEGACY_SIGNATURE_OPTION, $current, false );
			return;
		}

		if ( $stored === $current ) {
			return;
		}

		self::update_400_migrate_legacy_settings();
	}


	/**
	 * Signature of the legacy-owned fields, used to detect external (v3) edits to the legacy option.
	 *
	 * @param array $legacy Legacy gateway settings option.
	 */
	public static function legacy_signature( array $legacy ) {
		$keys = array(
			'enabled',
			'live_mode',
			'merchant_code',
			'merchant_token',
			'sandbox_merchant_code',
			'sandbox_merchant_token',
			'store_code',
			'sandbox_store_code',
			'payment_flow',
			'logging',
			'title-payoneer-checkout',
			'description-payoneer-checkout',
			'title-payoneer-hosted',
			'description-payoneer-hosted',
		);
		foreach ( self::$network_ids as $id ) {
			$keys[] = 'title-payoneer-' . $id;
			$keys[] = 'description-payoneer-' . $id;
		}

		$subset = array();
		foreach ( $keys as $key ) {
			$subset[ $key ] = $legacy[ $key ] ?? '';
		}

		return md5( (string) wp_json_encode( $subset ) );
	}


	/**
	 * Pull divisions/networks.
	 *
	 * @param Plugin $plugin  Plugin singleton.
	 * @param bool   $is_test Whether to fetch test or live mode data.
	 */
	private static function fetch_mode_data( $plugin, $is_test ) {
		$prefix   = $is_test ? 'sandbox_' : '';
		$username = (string) $plugin->get_gateway_setting( $prefix . 'merchant_code' );
		$token    = (string) $plugin->get_gateway_setting( $prefix . 'merchant_token' );

		if ( '' === $username || '' === $token ) {
			return;
		}

		$plugin->with_credentials(
			$username,
			$token,
			$is_test,
			function () use ( $plugin, $is_test ) {
				$response = $plugin->api()->get_divisions();

				if ( ! $response['success'] || empty( $response['body'] ) ) {
					$plugin->update_validated_credentials( false, $is_test );
					return;
				}

				$plugin->update_validated_credentials( true, $is_test );

				$raw_divisions = $response['body']['divisions'] ?? $response['body'] ?? array();
				$divisions     = array();
				foreach ( (array) $raw_divisions as $division ) {
					if ( ! is_array( $division ) || empty( $division['name'] ) ) {
						continue;
					}
					$divisions[] = array(
						'name'        => sanitize_text_field( $division['name'] ),
						'displayName' => sanitize_text_field( $division['displayName'] ?? '' ),
					);
				}
				$plugin->update_divisions( $divisions, $is_test );

				$store_key = $is_test ? 'sandbox_store_code' : 'store_code';
				$selected  = (string) $plugin->get_gateway_setting( $store_key );
				$names     = array_column( $divisions, 'name' );

				if ( ! in_array( $selected, $names, true ) ) {
					$selected = ( count( $divisions ) === 1 ) ? (string) $names[0] : '';
					$plugin->update_gateway_setting( $store_key, $selected );
				}

				if ( '' !== $selected ) {
					$plugin->settings()->fetch_networks( $is_test );
					$plugin->refresh_networks_by_division( $is_test );
				}
			}
		);
	}
}
