<?php
/**
 * Gateway settings definition.
 */

namespace PayoneerCheckout\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PayoneerCheckout\Gateways\WC_Payoneer_Gateway;
use PayoneerCheckout\Plugin;
use PayoneerCheckout\Utils;

/**
 * Builds and exposes the WooCommerce gateway settings fields.
 */
final class Settings {

	/** @var array */
	private array $settings = array();

	/** @var array|null */
	private ?array $sections = null;

	/** @var Plugin */
	private Plugin $plugin;

	/** @var LogsExport */
	private LogsExport $logs_export;


	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin      = $plugin;
		$this->logs_export = new LogsExport();
		$this->logs_export->register();
	}


	/**
	 * Get the gateway settings.
	 *
	 * @param bool $force Force refresh the settings.
	 *
	 * @return array
	 */
	public function get_settings( $force = false ) {

		if ( empty( $this->settings ) || $force ) {
			$this->init_settings();
		}

		return $this->settings;
	}


	/**
	 * Get a default setting value.
	 *
	 * @param string $key Setting key.
	 *
	 * @return mixed
	 */
	public function get_default_setting( $key ) {
		$settings = $this->get_settings();

		if ( empty( $settings[ $key ] ) ) {
			return '';
		}

		return $settings[ $key ]['default'] ?? '';
	}


	/**
	 * Return the active credentials based on the current mode.
	 *
	 * @return array{api_username: string, api_token: string}
	 */
	public function get_active_credentials() {
		$is_test   = $this->plugin->is_test_mode();
		$code_key  = $is_test ? 'sandbox_merchant_code' : 'merchant_code';
		$token_key = $is_test ? 'sandbox_merchant_token' : 'merchant_token';

		return array(
			'api_username' => $this->plugin->get_gateway_setting( $code_key ),
			'api_token'    => $this->plugin->get_gateway_setting( $token_key ),
		);
	}


	/**
	 * Get the section definitions for the two-column settings layout.
	 *
	 * @return array
	 */
	public function get_sections() {
		if ( $this->sections !== null ) {
			return $this->sections;
		}

		$this->sections = array(
			'general'         => array(
				'title'       => __( 'General', 'payoneer-checkout' ),
				'description' => __( 'Enable or disable Payoneer Checkout on your store.', 'payoneer-checkout' ),
				'fields'      => array( 'enabled' ),
			),
			'credentials'     => array(
				'title'       => __( 'Credentials', 'payoneer-checkout' ),
				'description' => __( 'Configure the API credentials from your Payoneer merchant account.', 'payoneer-checkout' ),
				'fields'      => array( 'live_mode', 'merchant_code', 'merchant_token', 'sandbox_merchant_code', 'sandbox_merchant_token' ),
			),
			'payment-config'  => array(
				'title'               => __( 'Payment configuration', 'payoneer-checkout' ),
				'description'         => __( 'Configure how the payment form is displayed to customers.', 'payoneer-checkout' ),
				'fields'              => array( 'store_code', 'sandbox_store_code', 'payment_flow' ),
				'requires_validation' => true,
			),
			'payment-methods' => array(
				'title'               => __( 'Payment methods', 'payoneer-checkout' ),
				'description'         => __( 'Enable or disable the payment methods available to your customers.', 'payoneer-checkout' ),
				'fields'              => array(),
				'requires_validation' => true,
				'requires_division'   => true,
			),
			'advanced'        => array(
				'title'       => __( 'Advanced settings', 'payoneer-checkout' ),
				'description' => __( 'Configure logging and diagnostic options.', 'payoneer-checkout' ),
				'fields'      => array( 'logging' ),
			),
			'logs-export'     => array(
				'title'               => __( 'Logs export', 'payoneer-checkout' ),
				'description'         => __( 'Download the WooCommerce status report or plugin log entries for support.', 'payoneer-checkout' ),
				'fields'              => array(),
				'requires_validation' => true,
				'requires_logging'    => true,
			),
		);

		return $this->sections;
	}


	/**
	 * Initialize the settings.
	 *
	 * @return void
	 */
	private function init_settings() {
		$this->sections = null;

		$this->settings = array(
			'enabled'                => array(
				'title'       => __( 'Enable/Disable', 'payoneer-checkout' ),
				'label'       => __( 'Enable', 'payoneer-checkout' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'                  => array(
				'title'       => __( 'Title', 'payoneer-checkout' ),
				'type'        => 'text',
				'description' => __( 'The payment method title displayed during checkout.', 'payoneer-checkout' ),
				'default'     => __( 'Credit / Debit cards', 'payoneer-checkout' ),
				'desc_tip'    => true,
			),
			'description'            => array(
				'title'       => __( 'Description', 'payoneer-checkout' ),
				'type'        => 'text',
				'description' => __( 'The description displayed when this payment method is selected.', 'payoneer-checkout' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'test_title'             => array(
				'type'    => 'text',
				'default' => __( 'Credit / Debit cards', 'payoneer-checkout' ),
			),
			'test_description'       => array(
				'type'    => 'text',
				'default' => '',
			),
			'hosted_title'           => array(
				'title'   => __( 'Hosted Title', 'payoneer-checkout' ),
				'type'    => 'text',
				'default' => __( 'Go to Payment Page', 'payoneer-checkout' ),
			),
			'hosted_description'     => array(
				'title'   => __( 'Hosted Description', 'payoneer-checkout' ),
				'type'    => 'text',
				'default' => '',
			),
			'live_mode'              => array(
				'title'       => __( 'Live Mode', 'payoneer-checkout' ),
				'label'       => __( 'Enable live mode', 'payoneer-checkout' ),
				'type'        => 'checkbox',
				'description' => __( 'Disable to keep the gateway in test/sandbox mode (real payments will not be taken).', 'payoneer-checkout' ),
				'default'     => 'no',
			),
			'merchant_code'          => array(
				'title'             => __( 'Merchant Code', 'payoneer-checkout' ),
				'type'              => 'text',
				'description'       => __( 'Your live Payoneer merchant code (API username).', 'payoneer-checkout' ),
				'default'           => '',
				'desc_tip'          => true,
				'class'             => 'conditional-hide',
				'custom_attributes' => array(
					'data-show-rel' => 'live_mode',
					'data-show-if'  => 'yes',
				),
			),
			'merchant_token'         => array(
				'title'             => __( 'Merchant Token', 'payoneer-checkout' ),
				'type'              => 'password',
				'description'       => __( 'Your live API payment token.', 'payoneer-checkout' ),
				'default'           => '',
				'desc_tip'          => true,
				'class'             => 'conditional-hide',
				'custom_attributes' => array(
					'data-show-rel' => 'live_mode',
					'data-show-if'  => 'yes',
				),
			),
			'sandbox_merchant_code'  => array(
				'title'             => __( 'Sandbox Merchant Code', 'payoneer-checkout' ),
				'type'              => 'text',
				'description'       => __( 'Your test / sandbox Payoneer merchant code (API username).', 'payoneer-checkout' ),
				'default'           => '',
				'desc_tip'          => true,
				'class'             => 'conditional-hide',
				'custom_attributes' => array(
					'data-show-rel' => 'live_mode',
					'data-show-if'  => 'no',
				),
			),
			'sandbox_merchant_token' => array(
				'title'             => __( 'Sandbox Merchant Token', 'payoneer-checkout' ),
				'type'              => 'password',
				'description'       => __( 'Your test / sandbox API payment token.', 'payoneer-checkout' ),
				'default'           => '',
				'desc_tip'          => true,
				'class'             => 'conditional-hide',
				'custom_attributes' => array(
					'data-show-rel' => 'live_mode',
					'data-show-if'  => 'no',
				),
			),
		);

		$this->add_division_settings();
		$this->add_advanced_settings();

		/**
		 * Filters the gateway settings before they are displayed.
		 *
		 * @param array $settings Gateway settings.
		 */
		$this->settings = apply_filters( 'payoneer_checkout_gateway_settings', $this->settings );
	}


	/**
	 * Fetch networks from the API and cache them in the option.
	 *
	 * @return array
	 */
	public function fetch_networks( $is_test = null ) {
		if ( null === $is_test ) {
			$is_test = $this->plugin->is_test_mode();
		}

		$store_key = $is_test ? 'sandbox_store_code' : 'store_code';
		$division  = $this->plugin->get_gateway_setting( $store_key );

		if ( empty( $division ) ) {
			return array();
		}

		$response = $this->plugin->api()->get_networks( $division );

		if ( ! $response['success'] || empty( $response['body'] ) ) {
			return array();
		}

		$raw_networks = $response['body'];
		if ( ! is_array( $raw_networks ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $raw_networks as $network ) {
			if ( ! is_array( $network ) ) {
				continue;
			}

			if ( empty( $network['enabled'] ) ) {
				continue;
			}

			$code = sanitize_text_field( $network['code'] ?? '' );
			if ( empty( $code ) ) {
				continue;
			}

			// Skip unsupported networks that might be returned by the API.
			if ( ! $this->is_supported_network( $code ) ) {
				continue;
			}

			// CC method is attached to the gateway directly, so we skip it in the networks list.
			if ( $this->is_cc_network( $code ) ) {
				continue;
			}

			$sanitized[ $code ] = array(
				'code'    => $code,
				'label'   => Utils::format_network_label( $code, $network['label'] ?? '' ),
				'enabled' => true,
				'logo'    => esc_url_raw( $network['links']['logo'] ?? '' ),
			);
		}

		$this->plugin->update_networks( $sanitized, $is_test );
		$this->plugin->seed_default_gateway_order();

		return $sanitized;
	}


	/**
	 * Check if a network code is in the supported networks list.
	 *
	 * @param string $code Network code.
	 *
	 * @return bool
	 */
	public function is_supported_network( $code ) {
		return in_array( $code, Utils::NETWORK_ORDER, true );
	}


	/**
	 * Is a CC network.
	 *
	 * @param string $code Network code.
	 *
	 * @return bool
	 */
	public function is_cc_network( $code ) {
		return in_array( $code, Utils::CC_CODES, true );
	}


	/**
	 * Save enabled networks from the posted form data for both modes.
	 *
	 * @return void
	 */
	public function save_enabled_networks() {
		$this->save_mode_enabled_networks( true );
		$this->save_mode_enabled_networks( false );
	}


	/**
	 * Save enabled networks for a single mode.
	 *
	 * @param bool $is_test Whether saving test mode networks.
	 *
	 * @return void
	 */
	private function save_mode_enabled_networks( $is_test ) {
		$marker     = $is_test ? 'payoneer_test_networks_rendered' : 'payoneer_networks_rendered';
		$input_name = $is_test ? 'payoneer_test_enabled_networks' : 'payoneer_enabled_networks';

		// Without the marker the list was not on this submission, so a blank post must not disable everything.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the nonce before calling process_admin_options().
		if ( empty( $_POST[ $marker ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the nonce before calling process_admin_options().
		$posted = isset( $_POST[ $input_name ] ) ? array_filter( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST[ $input_name ] ) ) ) : array();

		$known_codes = array_column( $this->plugin->get_networks( $is_test ), 'code' );

		if ( ! empty( $posted ) && ! empty( $known_codes ) && empty( array_intersect( $posted, $known_codes ) ) ) {
			return;
		}

		$this->plugin->update_disabled_networks( array_diff( $known_codes, $posted ), $is_test );
	}


	/**
	 * Save per-network settings (title and description) for both modes.
	 *
	 * @return void
	 */
	public function save_network_settings() {
		$this->save_mode_network_settings( true );
		$this->save_mode_network_settings( false );
	}


	/**
	 * Save per-network settings for a single mode.
	 *
	 * @param bool $is_test Whether saving test mode network settings.
	 *
	 * @return void
	 */
	private function save_mode_network_settings( $is_test ) {
		$input_name = $is_test ? 'payoneer_test_network_settings' : 'payoneer_network_settings';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the nonce before calling process_admin_options().
		if ( ! isset( $_POST[ $input_name ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below per field.
		$raw = (array) wp_unslash( $_POST[ $input_name ] );

		$known_codes = array_column( $this->plugin->get_networks( $is_test ), 'code' );

		if ( ! empty( $raw ) && ! empty( $known_codes ) && empty( array_intersect( array_keys( $raw ), $known_codes ) ) ) {
			return;
		}

		$sanitized = array();

		foreach ( $raw as $code => $fields ) {
			$code = sanitize_text_field( $code );

			if ( ! in_array( $code, $known_codes, true ) || ! is_array( $fields ) ) {
				continue;
			}

			$sanitized[ $code ] = array(
				'title'       => sanitize_text_field( $fields['title'] ?? '' ),
				'description' => sanitize_text_field( $fields['description'] ?? '' ),
			);
		}

		$this->plugin->update_network_settings( $sanitized, $is_test );
	}


	/**
	 * Render the full two-column settings page.
	 *
	 * @param WC_Payoneer_Gateway $gateway Gateway instance for field rendering.
	 *
	 * @return void
	 */
	public function render_settings_page( $gateway ) {
		$sections     = $this->get_sections();
		$back_url     = admin_url( 'admin.php?page=wc-settings&tab=checkout' );
		$is_validated = $this->plugin->get_validated_credentials();
		$has_division = ! empty( $this->plugin->get_selected_division() );
		?>
		<h2 class="wc-admin-header">
			<small>
				<a href="<?php echo esc_url( $back_url ); ?>" aria-label="<?php esc_attr_e( 'Return to payments', 'payoneer-checkout' ); ?>">
					<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
				</a>
			</small>
			<?php echo esc_html( $gateway->get_method_title() ); ?>
		</h2>

		<div class="payoneer-settings-layout">
			<?php foreach ( $sections as $section_id => $section ) : ?>
				<?php
				$needs_validation = ! empty( $section['requires_validation'] );
				$needs_division   = ! empty( $section['requires_division'] );
				$needs_logging    = ! empty( $section['requires_logging'] );
				$is_hidden        = ( $needs_validation && ! $is_validated )
					|| ( $needs_division && ! $has_division )
					|| ( $needs_logging && ! $this->plugin->is_logging_enabled() );
				?>
				<div
					class="payoneer-settings-section <?php echo $needs_validation ? 'payoneer-settings-section--requires-validation' : ''; ?> <?php echo $needs_logging ? 'payoneer-settings-section--requires-logging' : ''; ?>"
					id="<?php echo esc_attr( $section_id ); ?>"
					<?php
					if ( $is_hidden ) :
						?>
						style="display: none;"<?php endif; ?>
				>
					<div class="payoneer-settings-section__details">
						<h2><?php echo esc_html( $section['title'] ); ?></h2>
						<p><?php echo esc_html( $section['description'] ); ?></p>
					</div>

					<div class="payoneer-settings-section__controls">
						<div class="payoneer-settings-card">
							<?php if ( 'payment-methods' === $section_id ) : ?>
								<?php $this->render_payment_methods(); ?>
							<?php elseif ( 'logs-export' === $section_id ) : ?>
								<?php $this->logs_export->render(); ?>
							<?php elseif ( ! empty( $section['fields'] ) ) : ?>
								<?php $section_fields = array_intersect_key( $gateway->get_form_fields(), array_flip( $section['fields'] ) ); ?>
								<table class="form-table payoneer-form-table">
									<?php echo $gateway->generate_settings_html( $section_fields, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce generates safe HTML. ?>
								</table>
							<?php endif; ?>

							<?php if ( 'credentials' === $section_id ) : ?>
								<?php $this->render_validate_button(); ?>
							<?php endif; ?>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}


	/**
	 * Render the credential validation button inside the credentials card.
	 */
	private function render_validate_button() {
		$is_validated = $this->plugin->get_validated_credentials();
		?>
		<div class="payoneer-validate-credentials"<?php echo $is_validated ? ' style="display:none"' : ''; ?>>
			<button type="button" class="button button-secondary" id="payoneer-validate-credentials-btn">
				<?php esc_html_e( 'Validate Credentials', 'payoneer-checkout' ); ?>
			</button>
			<span class="spinner" id="payoneer-validate-spinner"></span>
			<span class="payoneer-validate-credentials__message" id="payoneer-validate-message"></span>
		</div>
		<?php
	}


	/**
	 * Build the WC settings input id/name for a gateway field key.
	 *
	 * @param string $key Field key (e.g. 'hosted_title', 'test_description').
	 *
	 * @return string
	 */
	public static function field_id( $key ) {
		return 'woocommerce_' . WC_Payoneer_Gateway::ID . '_' . $key;
	}


	/**
	 * Render the payment methods list inside the card container.
	 *
	 * @return void
	 */
	public function render_payment_methods() {
		$this->plugin->seed_default_gateway_order();

		$test_networks         = $this->order_networks( $this->plugin->get_networks( true ) );
		$test_enabled_networks = $this->plugin->get_enabled_networks( true );
		$test_network_settings = $this->plugin->get_network_settings( true );
		$live_networks         = $this->order_networks( $this->plugin->get_networks( false ) );
		$live_enabled_networks = $this->plugin->get_enabled_networks( false );
		$live_network_settings = $this->plugin->get_network_settings( false );

		$integration_type = $this->plugin->get_integration_type();
		$is_hosted        = 'hosted' === $integration_type;

		$cc_logo                    = Utils::plugin_url() . '/assets/images/cc-logo.svg';
		$payoneer_logo              = Utils::plugin_url() . '/assets/images/payoneer.svg';
		$default_title              = $this->get_default_setting( 'title' );
		$default_test_title         = $this->get_default_setting( 'test_title' );
		$default_hosted_title       = $this->get_default_setting( 'hosted_title' );
		$default_hosted_description = $this->get_default_setting( 'hosted_description' );

		$test_cc_title = $this->plugin->get_gateway_setting( 'test_title' );
		$test_cc_desc  = $this->plugin->get_gateway_setting( 'test_description' );
		$live_cc_title = $this->plugin->get_gateway_setting( 'title' );
		$live_cc_desc  = $this->plugin->get_gateway_setting( 'description' );
		$hosted_title  = $this->plugin->get_gateway_setting( 'hosted_title' );
		$hosted_desc   = $this->plugin->get_gateway_setting( 'hosted_description' );
		?>
		<input type="hidden" name="payoneer_test_enabled_networks" value="">
		<input type="hidden" name="payoneer_enabled_networks" value="">
		<input type="hidden" name="payoneer_test_networks_rendered" value="<?php echo empty( $test_networks ) ? '' : '1'; ?>">
		<input type="hidden" name="payoneer_networks_rendered" value="<?php echo empty( $live_networks ) ? '' : '1'; ?>">

		<?php // === Embedded mode block === ?>
		<div class="payoneer-payment-methods--embedded" 
		<?php
		if ( $is_hosted ) :
			?>
			style="display: none;"<?php endif; ?>>
			<table class="payoneer-payment-methods__table">
				<?php // Test mode CC card. ?>
				<tbody class="conditional-hide" data-show-rel="live_mode" data-show-if="no">
					<?php $this->render_cc_card_rows( $cc_logo, $test_cc_title, $test_cc_desc, $default_test_title, 'test_' ); ?>
				</tbody>
				<?php // Live mode CC card. ?>
				<tbody class="conditional-hide" data-show-rel="live_mode" data-show-if="yes">
					<?php $this->render_cc_card_rows( $cc_logo, $live_cc_title, $live_cc_desc, $default_title ); ?>
				</tbody>
				<?php // Test mode networks. ?>
				<tbody class="payoneer-network-list conditional-hide" data-show-rel="live_mode" data-show-if="no">
					<?php $this->render_network_rows( $test_networks, $test_enabled_networks, $test_network_settings, 'test_' ); ?>
				</tbody>
				<?php // Live mode networks. ?>
				<tbody class="payoneer-network-list conditional-hide" data-show-rel="live_mode" data-show-if="yes">
					<?php $this->render_network_rows( $live_networks, $live_enabled_networks, $live_network_settings ); ?>
				</tbody>
			</table>
		</div>

		<?php // === Network row template for JS cloning === ?>
		<template id="payoneer-network-row-template">
			<tr class="payoneer-payment-method payoneer-payment-method--network" data-network-code="">
				<td class="payoneer-payment-method__chevron">
					<button type="button" class="payoneer-payment-method__toggle-details" aria-expanded="false">
						<span class="dashicons dashicons-arrow-right-alt2"></span>
						<span class="screen-reader-text"></span>
					</button>
				</td>
				<td class="payoneer-payment-method__info">
					<img class="payoneer-payment-method__logo" src="" alt="" width="40" height="26">
					<span class="payoneer-payment-method__label"></span>
				</td>
				<td class="payoneer-payment-method__toggle">
					<label class="payoneer-toggle">
						<input type="checkbox" class="payoneer-toggle__input" name="payoneer_enabled_networks[]" value="" checked>
						<span class="payoneer-toggle__slider"></span>
						<span class="screen-reader-text"></span>
					</label>
				</td>
			</tr>
			<tr class="payoneer-payment-method__details payoneer-payment-method--network" data-network-code="" style="display: none;">
				<td colspan="3">
					<div class="payoneer-payment-method__details-inner">
						<p>
							<label>
								<?php esc_html_e( 'Title', 'payoneer-checkout' ); ?>
								<?php echo wc_help_tip( __( 'The payment method title displayed to customers during checkout.', 'payoneer-checkout' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</label>
							<input type="text" name="" value="" class="regular-text" data-field="title">
						</p>
						<p>
							<label>
								<?php esc_html_e( 'Description', 'payoneer-checkout' ); ?>
								<?php echo wc_help_tip( __( 'The description shown when this payment method is selected.', 'payoneer-checkout' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</label>
							<input type="text" name="" value="" class="regular-text" data-field="description">
						</p>
					</div>
				</td>
			</tr>
		</template>

		<?php // === Hosted mode block === ?>
		<div class="payoneer-payment-methods--hosted" 
		<?php
		if ( ! $is_hosted ) :
			?>
			style="display: none;"<?php endif; ?>>
			<table class="payoneer-payment-methods__table">
				<tbody>
					<tr class="payoneer-payment-method is-expanded">
						<td class="payoneer-payment-method__chevron">
							<button type="button" class="payoneer-payment-method__toggle-details" aria-expanded="true">
								<span class="dashicons dashicons-arrow-right-alt2"></span>
								<span class="screen-reader-text">
									<?php esc_html_e( 'Toggle details for Hosted Payment Page', 'payoneer-checkout' ); ?>
								</span>
							</button>
						</td>
						<td class="payoneer-payment-method__info">
							<img class="payoneer-payment-method__logo" src="<?php echo esc_url( $payoneer_logo ); ?>" alt="<?php esc_attr_e( 'Payoneer - Hosted Payment Page', 'payoneer-checkout' ); ?>" width="40" height="30">
							<span class="payoneer-payment-method__label"><?php esc_html_e( 'Payoneer - Hosted Payment Page', 'payoneer-checkout' ); ?></span>
						</td>
						<td class="payoneer-payment-method__toggle"></td>
					</tr>
					<tr class="payoneer-payment-method__details">
						<td colspan="3">
							<?php $hosted_title_id = self::field_id( 'hosted_title' ); ?>
							<?php $hosted_desc_id = self::field_id( 'hosted_description' ); ?>
							<div class="payoneer-payment-method__details-inner">
								<p>
									<label for="<?php echo esc_attr( $hosted_title_id ); ?>">
										<?php esc_html_e( 'Title', 'payoneer-checkout' ); ?>
										<?php echo wc_help_tip( __( 'The payment method title displayed to customers during checkout in hosted mode.', 'payoneer-checkout' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</label>
									<input type="text" id="<?php echo esc_attr( $hosted_title_id ); ?>" name="<?php echo esc_attr( $hosted_title_id ); ?>" value="<?php echo esc_attr( ! empty( $hosted_title ) ? $hosted_title : $default_hosted_title ); ?>" class="regular-text">
								</p>
								<p>
									<label for="<?php echo esc_attr( $hosted_desc_id ); ?>">
										<?php esc_html_e( 'Description', 'payoneer-checkout' ); ?>
										<?php echo wc_help_tip( __( 'The description shown when this payment method is selected in hosted mode.', 'payoneer-checkout' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</label>
									<?php $hosted_desc_value = $this->plugin->gateway_setting_exists( 'hosted_description' ) ? $hosted_desc : $default_hosted_description; ?>
									<input type="text" id="<?php echo esc_attr( $hosted_desc_id ); ?>" name="<?php echo esc_attr( $hosted_desc_id ); ?>" value="<?php echo esc_attr( $hosted_desc_value ); ?>" class="regular-text">
								</p>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}


	/**
	 * Render network rows for one mode (test or live).
	 *
	 * @param array  $networks         Networks data.
	 * @param array  $enabled_networks Enabled network codes.
	 * @param array  $network_settings Per-network title/description overrides.
	 * @param string $prefix           Form name prefix ('test_' or '').
	 *
	 * @return void
	 */
	private function render_cc_card_rows( $cc_logo, $cc_title, $cc_desc, $default_title, $prefix = '' ) {
		?>
		<tr class="payoneer-payment-method is-expanded">
			<td class="payoneer-payment-method__chevron">
				<button type="button" class="payoneer-payment-method__toggle-details" aria-expanded="true">
					<span class="dashicons dashicons-arrow-right-alt2"></span>
					<span class="screen-reader-text">
						<?php esc_html_e( 'Toggle details for Credit / Debit cards', 'payoneer-checkout' ); ?>
					</span>
				</button>
			</td>
			<td class="payoneer-payment-method__info">
				<img class="payoneer-payment-method__logo" src="<?php echo esc_url( $cc_logo ); ?>" alt="<?php esc_attr_e( 'Credit / Debit cards', 'payoneer-checkout' ); ?>" width="40" height="30">
				<span class="payoneer-payment-method__label"><?php esc_html_e( 'Credit / Debit cards', 'payoneer-checkout' ); ?></span>
			</td>
			<td class="payoneer-payment-method__toggle"></td>
		</tr>
		<tr class="payoneer-payment-method__details">
			<td colspan="3">
				<?php $cc_title_id = self::field_id( $prefix . 'title' ); ?>
				<?php $cc_desc_id = self::field_id( $prefix . 'description' ); ?>
				<div class="payoneer-payment-method__details-inner">
					<p>
						<label for="<?php echo esc_attr( $cc_title_id ); ?>">
							<?php esc_html_e( 'Title', 'payoneer-checkout' ); ?>
							<?php echo wc_help_tip( __( 'The payment method title displayed to customers during checkout.', 'payoneer-checkout' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</label>
						<input type="text" id="<?php echo esc_attr( $cc_title_id ); ?>" name="<?php echo esc_attr( $cc_title_id ); ?>" value="<?php echo esc_attr( ! empty( $cc_title ) ? $cc_title : $default_title ); ?>" class="regular-text">
					</p>
					<p>
						<label for="<?php echo esc_attr( $cc_desc_id ); ?>">
							<?php esc_html_e( 'Description', 'payoneer-checkout' ); ?>
							<?php echo wc_help_tip( __( 'The description shown when this payment method is selected.', 'payoneer-checkout' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</label>
						<input type="text" id="<?php echo esc_attr( $cc_desc_id ); ?>" name="<?php echo esc_attr( $cc_desc_id ); ?>" value="<?php echo esc_attr( $cc_desc ); ?>" class="regular-text">
					</p>
				</div>
			</td>
		</tr>
		<?php
	}


	/**
	 * Render network toggle rows for a single mode.
	 *
	 * @param array  $networks         Available networks.
	 * @param array  $enabled_networks Enabled network codes.
	 * @param array  $network_settings Per-network title/description overrides.
	 * @param string $prefix           Form name prefix ('test_' or '').
	 *
	 * @return void
	 */
	private function render_network_rows( $networks, $enabled_networks, $network_settings, $prefix = '' ) {
		foreach ( $networks as $network ) :
			$code       = $network['code'] ?? '';
			$label      = Utils::format_network_label( $code, $network['label'] ?? '' );
			$logo       = $network['logo'] ?? '';
			$is_enabled = in_array( $code, $enabled_networks, true );

			$saved_title       = $network_settings[ $code ]['title'] ?? $label;
			$saved_description = $network_settings[ $code ]['description'] ?? '';
			?>
			<tr class="payoneer-payment-method payoneer-payment-method--network" data-network-code="<?php echo esc_attr( $code ); ?>">
				<td class="payoneer-payment-method__chevron">
					<button type="button" class="payoneer-payment-method__toggle-details" aria-expanded="false">
						<span class="dashicons dashicons-arrow-right-alt2"></span>
						<span class="screen-reader-text">
							<?php
							/* translators: %s: payment method name */
							printf( esc_html__( 'Toggle details for %s', 'payoneer-checkout' ), esc_html( $label ) );
							?>
						</span>
					</button>
				</td>
				<td class="payoneer-payment-method__info">
					<?php if ( $logo ) : ?>
						<img class="payoneer-payment-method__logo" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $label ); ?>" width="40" height="26">
					<?php endif; ?>
					<span class="payoneer-payment-method__label"><?php echo esc_html( $label ); ?></span>
				</td>
				<td class="payoneer-payment-method__toggle">
					<label class="payoneer-toggle">
						<input type="checkbox" class="payoneer-toggle__input" name="payoneer_<?php echo esc_attr( $prefix ); ?>enabled_networks[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( $is_enabled ); ?>>
						<span class="payoneer-toggle__slider"></span>
						<span class="screen-reader-text">
							<?php
							/* translators: %s: payment method name */
							printf( esc_html__( 'Enable %s', 'payoneer-checkout' ), esc_html( $label ) );
							?>
						</span>
					</label>
				</td>
			</tr>
			<tr class="payoneer-payment-method__details payoneer-payment-method--network" data-network-code="<?php echo esc_attr( $code ); ?>" style="display: none;">
				<td colspan="3">
					<div class="payoneer-payment-method__details-inner">
						<p>
							<label for="payoneer_<?php echo esc_attr( $prefix ); ?>network_title_<?php echo esc_attr( $code ); ?>">
								<?php esc_html_e( 'Title', 'payoneer-checkout' ); ?>
								<?php echo wc_help_tip( __( 'The payment method title displayed to customers during checkout.', 'payoneer-checkout' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</label>
							<input type="text" id="payoneer_<?php echo esc_attr( $prefix ); ?>network_title_<?php echo esc_attr( $code ); ?>" name="payoneer_<?php echo esc_attr( $prefix ); ?>network_settings[<?php echo esc_attr( $code ); ?>][title]" value="<?php echo esc_attr( $saved_title ); ?>" class="regular-text">
						</p>
						<p>
							<label for="payoneer_<?php echo esc_attr( $prefix ); ?>network_desc_<?php echo esc_attr( $code ); ?>">
								<?php esc_html_e( 'Description', 'payoneer-checkout' ); ?>
								<?php echo wc_help_tip( __( 'The description shown when this payment method is selected.', 'payoneer-checkout' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</label>
							<input type="text" id="payoneer_<?php echo esc_attr( $prefix ); ?>network_desc_<?php echo esc_attr( $code ); ?>" name="payoneer_<?php echo esc_attr( $prefix ); ?>network_settings[<?php echo esc_attr( $code ); ?>][description]" value="<?php echo esc_attr( $saved_description ); ?>" class="regular-text">
						</p>
					</div>
				</td>
			</tr>
			<?php
		endforeach;
	}


	/**
	 * Order a networks map by the default display order.
	 *
	 * @param array $networks Code-keyed networks map.
	 *
	 * @return array
	 */
	private function order_networks( $networks ) {
		$pos = static function ( $network ) {
			$index = array_search( strtoupper( $network['code'] ?? '' ), Utils::NETWORK_ORDER, true );
			return false === $index ? PHP_INT_MAX : $index;
		};

		uasort( $networks, static fn( $a, $b ) => $pos( $a ) <=> $pos( $b ) );

		return $networks;
	}


	/**
	 * Append division selector and integration type fields.
	 *
	 * @return void
	 */
	private function add_division_settings() {
		$division_options      = $this->build_mode_division_options( false );
		$test_division_options = $this->build_mode_division_options( true );

		$this->settings = array_merge(
			$this->settings,
			array(
				'store_code'         => array(
					'title'             => __( 'Store Code', 'payoneer-checkout' ),
					'type'              => 'select',
					'options'           => $division_options,
					'default'           => array_key_first( $division_options ),
					'description'       => __( 'Select the merchant store to use for transactions.', 'payoneer-checkout' ),
					'desc_tip'          => true,
					'class'             => 'conditional-hide',
					'custom_attributes' => array(
						'data-show-rel' => 'live_mode',
						'data-show-if'  => 'yes',
					),
				),
				'sandbox_store_code' => array(
					'title'             => __( 'Sandbox Store Code', 'payoneer-checkout' ),
					'type'              => 'select',
					'options'           => $test_division_options,
					'default'           => array_key_first( $test_division_options ),
					'description'       => __( 'Select the sandbox store to use for transactions.', 'payoneer-checkout' ),
					'desc_tip'          => true,
					'class'             => 'conditional-hide',
					'custom_attributes' => array(
						'data-show-rel' => 'live_mode',
						'data-show-if'  => 'no',
					),
				),
				'payment_flow'       => array(
					'title'       => __( 'Integration Type', 'payoneer-checkout' ),
					'type'        => 'select',
					'options'     => array(
						'embedded' => __( 'Embedded Payment Page', 'payoneer-checkout' ),
						'hosted'   => __( 'Hosted Payment Page', 'payoneer-checkout' ),
					),
					'default'     => 'embedded',
					'description' => __( 'Embedded renders the payment form directly on your checkout page for a seamless experience. Hosted redirects customers to a Payoneer-managed payment page, minimizing PCI compliance requirements.', 'payoneer-checkout' ),
					'desc_tip'    => true,
				),
			)
		);
	}


	/**
	 * Build the division select options for a mode, keeping the stored selection selectable.
	 *
	 * @param bool $is_test Whether to build the test mode options.
	 *
	 * @return array
	 */
	private function build_mode_division_options( $is_test ) {
		$divisions = $this->plugin->get_divisions( $is_test );
		$stored    = (string) $this->plugin->get_gateway_setting( $is_test ? 'sandbox_store_code' : 'store_code' );
		$options   = empty( $divisions ) ? array() : $this->build_division_options( $divisions );

		if ( '' !== $stored && ! isset( $options[ $stored ] ) ) {
			$options[ $stored ] = $stored;
		}

		if ( empty( $options ) ) {
			$options[''] = __( 'Validate credentials first', 'payoneer-checkout' );
		}

		return $options;
	}


	/**
	 * Build select options from a divisions array.
	 *
	 * @param array $divisions Divisions data.
	 *
	 * @return array
	 */
	private function build_division_options( $divisions ) {
		$options = array();

		foreach ( $divisions as $division ) {
			$code = $division['name'] ?? '';
			$name = ! empty( $division['displayName'] ) ? $division['displayName'] . ' (' . $code . ')' : $code;
			if ( $code ) {
				$options[ $code ] = $name;
			}
		}

		if ( empty( $options ) ) {
			$options[''] = __( 'No divisions available', 'payoneer-checkout' );
		} elseif ( count( $options ) > 1 ) {
			$options = array( '' => __( 'Select Store Code', 'payoneer-checkout' ) ) + $options;
		}

		return $options;
	}


	/**
	 * Add advanced settings fields.
	 *
	 * @return void
	 */
	private function add_advanced_settings() {
		$this->settings = array_merge(
			$this->settings,
			array(
				'logging' => array(
					'title'       => __( 'Logging', 'payoneer-checkout' ),
					'label'       => __( 'Enable logging', 'payoneer-checkout' ),
					'type'        => 'checkbox',
					'description' => __( 'Log payment events and API interactions to WooCommerce &rarr; Status &rarr; Logs.', 'payoneer-checkout' ),
					'default'     => 'yes',
				),
			)
		);
	}


	/**
	 * Keep the legacy v3 settings option in sync so a downgrade to v3 retains credentials.
	 *
	 * @param array $settings Gateway settings.
	 *
	 * @return void
	 */
	public function mirror_to_legacy_option( $settings ) {
		if ( ! is_array( $settings ) || empty( $settings ) ) {
			return;
		}

		$legacy = (array) get_option( 'woocommerce_payoneer-checkout_settings', array() );

		foreach ( array( 'enabled', 'live_mode', 'merchant_code', 'merchant_token', 'sandbox_merchant_code', 'sandbox_merchant_token', 'store_code', 'sandbox_store_code', 'payment_flow', 'logging' ) as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$legacy[ $key ] = $settings[ $key ];
			}
		}

		if ( '' === ( $legacy['merchant_code'] ?? '' ) ) {
			$legacy['merchant_code'] = $settings['sandbox_merchant_code'] ?? '';
		}

		$legacy['title-payoneer-checkout']       = $settings['title'] ?? '';
		$legacy['description-payoneer-checkout'] = $settings['description'] ?? '';
		$legacy['title-payoneer-hosted']         = $settings['hosted_title'] ?? '';
		$legacy['description-payoneer-hosted']   = $settings['hosted_description'] ?? '';

		foreach ( (array) ( $settings['network_settings'] ?? array() ) as $code => $cfg ) {
			$id                             = 'payoneer-' . strtolower( $code );
			$legacy[ 'title-' . $id ]       = $cfg['title'] ?? '';
			$legacy[ 'description-' . $id ] = $cfg['description'] ?? '';
		}

		update_option( 'woocommerce_payoneer-checkout_settings', $legacy );
		update_option( \PayoneerCheckout\Install::LEGACY_SIGNATURE_OPTION, \PayoneerCheckout\Install::legacy_signature( $legacy ), false );

		$this->mirror_merchants_option( $settings );
	}


	/**
	 * Mirror the merchants option to keep it in sync with the new settings structure.
	 *
	 * @param array $settings Gateway settings.
	 */
	private function mirror_merchants_option( $settings ) {
		$merchants = (array) get_option( 'payoneer-checkout_merchants', array() );

		$merchants[2] = array(
			'label'       => 'Test',
			'environment' => 'sandbox',
			'code'        => $settings['sandbox_merchant_code'] ?? '',
			'division'    => $settings['sandbox_store_code'] ?? '',
			'token'       => $settings['sandbox_merchant_token'] ?? '',
			'base_url'    => \PayoneerCheckout\API::SANDBOX_URL,
			'id'          => 2,
		);

		$merchants[1] = array(
			'label'       => 'Live',
			'environment' => 'live',
			'code'        => $settings['merchant_code'] ?? '',
			'division'    => $settings['store_code'] ?? '',
			'token'       => $settings['merchant_token'] ?? '',
			'base_url'    => \PayoneerCheckout\API::PRODUCTION_URL,
			'id'          => 1,
		);

		update_option( 'payoneer-checkout_merchants', $merchants );
	}
}
