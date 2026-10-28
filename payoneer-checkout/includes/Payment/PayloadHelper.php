<?php
/**
 * LIST payload builder for Payoneer Checkout.
 */

namespace PayoneerCheckout\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PayoneerCheckout\Plugin;
use PayoneerCheckout\Utils;
use const PayoneerCheckout\VERSION;

/**
 * Static helper that builds LIST API request payloads from WooCommerce cart/order data.
 */
final class PayloadHelper {

	/**
	 * Build a placeholder EMBEDDED LIST creation payload from WC cart data.
	 *
	 * @return array
	 */
	public static function build_list_payload() {
		if ( empty( WC()->cart ) || WC()->cart->is_empty() ) {
			return array();
		}

		return array_merge(
			self::build_create_fields(),
			self::build_fields_from_cart()
		);
	}


	/**
	 * Build a LIST creation payload from a WC order.
	 *
	 * @param \WC_Order $order          The WooCommerce order.
	 * @param string    $integration    Integration type: 'HOSTED' or 'EMBEDDED'.
	 * @param string    $security_token Optional security token for notification headers.
	 * @param array     $network_codes  Optional network code preselection.
	 *
	 * @return array
	 */
	public static function build_list_payload_from_order( $order, $integration = 'HOSTED', $security_token = '', $network_codes = array() ) {
		$payload = array_merge(
			self::build_create_fields( $integration ),
			self::build_fields_from_order( $order, $integration, $security_token )
		);

		if ( ! empty( $network_codes ) ) {
			$payload['preselection'] = array( 'networkCodes' => $network_codes );
		}

		return $payload;
	}


	/**
	 * Build an UPDATE payload from a WC order.
	 *
	 * @param \WC_Order $order          The WooCommerce order.
	 * @param string    $transaction_id Transaction ID from the original LIST creation.
	 * @param string    $security_token Optional security token for notification headers.
	 *
	 * @return array
	 */
	public static function build_update_payload_from_order( $order, $transaction_id = '', $security_token = '' ) {
		$payload = self::build_fields_from_order( $order, 'EMBEDDED', $security_token );

		if ( ! empty( $transaction_id ) ) {
			$payload['transactionId'] = $transaction_id;
		}

		return $payload;
	}


	/**
	 * Build an UPDATE payload from WC cart data (no order yet).
	 *
	 * @param string $transaction_id Transaction ID from the original LIST creation.
	 *
	 * @return array
	 */
	public static function build_update_payload_from_cart( $transaction_id = '' ) {
		$payload = self::build_fields_from_cart();

		if ( ! empty( $transaction_id ) ) {
			$payload['transactionId'] = $transaction_id;
		}

		return $payload;
	}


	/**
	 * Build the common payload fields from a WC order.
	 *
	 * @param \WC_Order $order          The WooCommerce order.
	 * @param string    $integration    Integration type: 'HOSTED' or 'EMBEDDED'.
	 * @param string    $security_token Optional security token for notification headers.
	 *
	 * @return array
	 */
	private static function build_fields_from_order( $order, $integration, $security_token = '' ) {
		$country = $order->get_billing_country();
		if ( empty( $country ) ) {
			$country = self::get_country();
		}

		return array(
			'country'  => $country,
			'customer' => self::build_customer_payload( $order ),
			'payment'  => self::build_payment_from_order( $order, $integration ),
			'products' => self::build_products_from_order( $order, $integration ),
			'callback' => self::build_callbacks_from_order( $order, $security_token ),
			'system'   => self::build_system(),
		);
	}


	/**
	 * Build the common payload fields from the WC cart.
	 *
	 * @return array
	 */
	private static function build_fields_from_cart() {
		return array(
			'country'  => self::get_country(),
			'customer' => self::build_customer_payload( WC()->customer ),
			'payment'  => self::build_payment_from_cart(),
			'products' => self::build_products_from_cart(),
			'callback' => self::build_base_callbacks(),
			'system'   => self::build_system(),
		);
	}


	/**
	 * Build the fields specific to LIST creation (not used in updates).
	 *
	 * @param string $integration Integration type: 'HOSTED' or 'EMBEDDED'.
	 *
	 * @return array
	 */
	private static function build_create_fields( $integration = 'EMBEDDED' ) {
		$fields = array(
			'transactionId' => wp_generate_uuid4(),
			'integration'   => $integration,
			'operationType' => 'CHARGE',
			'division'      => Plugin::instance()->get_selected_division(),
			'style'         => self::build_style(),
			'ttl'           => 240, // Default TTL to 4h
		);

		if ( 'EMBEDDED' === $integration ) {
			$preselection = self::build_preselection();
			if ( ! empty( $preselection ) ) {
				$fields['preselection'] = $preselection;
			}
		}

		return $fields;
	}


	/**
	 * Build the LIST customer payload from a WC_Customer or WC_Order source.
	 *
	 * @param \WC_Customer|\WC_Order|null $source Customer or order; both expose the same billing/shipping getters.
	 *
	 * @return array
	 */
	private static function build_customer_payload( $source ) {
		if ( ! $source instanceof \WC_Order && ! $source instanceof \WC_Customer ) {
			return array();
		}

		$customer = array();

		$email = $source->get_billing_email();
		if ( ! empty( $email ) ) {
			$customer['email'] = $email;
		}

		$customer_id = $source instanceof \WC_Order ? (int) $source->get_customer_id() : (int) $source->get_id();
		if ( $customer_id > 0 || empty( $customer['email'] ) ) {
			$customer['number'] = (string) $customer_id;
		}

		$first_name = $source->get_billing_first_name();
		$last_name  = $source->get_billing_last_name();

		if ( ! empty( $first_name ) || ! empty( $last_name ) ) {
			$customer['name'] = array(
				'firstName' => $first_name,
				'lastName'  => $last_name,
			);
		}

		$phone = $source->get_billing_phone();
		if ( ! empty( $phone ) ) {
			$customer['phones'] = array(
				'mobile' => array( 'unstructuredNumber' => $phone ),
			);
		}

		$billing = self::build_address( $source, 'billing' );

		if ( empty( $billing ) ) {
			$fallback_country = self::get_country();
			if ( empty( $fallback_country ) ) {
				return $customer;
			}

			$billing = array(
				'country' => $fallback_country,
				'zip'     => '00000',
			);

			if ( ! empty( $customer['name'] ) ) {
				$billing['name'] = $customer['name'];
			}
		}

		$customer['addresses'] = array( 'billing' => $billing );

		$shipping = self::build_address( $source, 'shipping' );
		if ( ! empty( $shipping ) ) {
			$customer['addresses']['shipping'] = $shipping;
		}

		return $customer;
	}


	/**
	 * Read a billing- or shipping-address field via WC's dynamic getter, returning '' if unavailable.
	 *
	 * @param \WC_Customer|\WC_Order $source The source.
	 * @param string                 $field  Field suffix (e.g. 'country', 'address_1', 'postcode').
	 * @param string                 $type   Address type: 'billing' or 'shipping'.
	 *
	 * @return string
	 */
	private static function get_address_field( $source, $field, $type ) {
		$method = 'get_' . $type . '_' . $field;

		if ( ! is_callable( array( $source, $method ) ) ) {
			return '';
		}

		return (string) $source->{$method}();
	}


	/**
	 * Build a billing- or shipping-address subtree using WC's get_{billing|shipping}_* getters.
	 *
	 * @param \WC_Customer|\WC_Order $source The source.
	 * @param string                 $type   Address type: 'billing' or 'shipping'.
	 *
	 * @return array
	 */
	private static function build_address( $source, $type ) {
		$country = self::get_address_field( $source, 'country', $type );
		if ( empty( $country ) ) {
			return array();
		}

		$zip = self::get_address_field( $source, 'postcode', $type );
		if ( empty( $zip ) ) {
			$zip = '00000';
		}

		$address_1 = self::get_address_field( $source, 'address_1', $type );
		$address_2 = self::get_address_field( $source, 'address_2', $type );
		$street    = mb_substr( trim( $address_1 . ' ' . $address_2 ), 0, 80 );

		$address = array(
			'street'  => $street,
			'city'    => self::get_address_field( $source, 'city', $type ),
			'zip'     => $zip,
			'country' => $country,
		);

		$state = self::get_address_field( $source, 'state', $type );
		if ( ! self::is_state_hidden( $country ) && ! empty( $state ) ) {
			$address['state'] = $state;
		}

		$first_name = self::get_address_field( $source, 'first_name', $type );
		$last_name  = self::get_address_field( $source, 'last_name', $type );

		if ( ! empty( $first_name ) || ! empty( $last_name ) ) {
			$address['name'] = array(
				'firstName' => $first_name,
				'lastName'  => $last_name,
			);
		}

		return $address;
	}


	/**
	 * Is state hidden.
	 *
	 * @param string $country Country code.
	 *
	 * @return bool
	 */
	private static function is_state_hidden( $country ) {
		if ( empty( $country ) || empty( WC()->countries ) ) {
			return false;
		}

		$country_locale = WC()->countries->get_country_locale();
		if ( empty( $country_locale ) || ! is_array( $country_locale ) ) {
			return false;
		}

		return ! empty( $country_locale[ $country ]['state']['hidden'] );
	}


	/**
	 * Coerce a numeric amount to the active currency's decimal precision.
	 *
	 * @param float|int|string $amount Raw amount.
	 *
	 * @return float
	 */
	private static function normalize_amount( $amount ) {
		return (float) wc_format_decimal( $amount, wc_get_price_decimals() );
	}


	/**
	 * Build payment data from WooCommerce cart totals.
	 *
	 * @return array
	 */
	private static function build_payment_from_cart() {
		$cart = WC()->cart;

		return array(
			'reference' => wp_generate_uuid4(),
			'amount'    => self::normalize_amount( $cart->get_total( false ) ),
			'currency'  => get_woocommerce_currency(),
		);
	}


	/**
	 * Build payment data from a WC order.
	 *
	 * @param \WC_Order $order       The order.
	 * @param string    $integration Integration type.
	 *
	 * @return array
	 */
	private static function build_payment_from_order( $order, $integration = 'HOSTED' ) {
		$total = self::normalize_amount( $order->get_total() );

		$payment = array(
			'invoiceId' => $order->get_id(),
			'reference' => $order->get_order_number(),
			'amount'    => $total,
			'currency'  => $order->get_currency(),
		);

		if ( 'HOSTED' === $integration ) {
			$tax                  = self::normalize_amount( $order->get_total_tax() );
			$payment['taxAmount'] = $tax;
			$payment['netAmount'] = self::normalize_amount( $total - $tax );
		}

		return $payment;
	}


	/**
	 * Build the products array from WooCommerce cart items.
	 *
	 * @return array
	 */
	private static function build_products_from_cart() {
		$cart     = WC()->cart;
		$currency = get_woocommerce_currency();
		$products = array();

		foreach ( $cart->get_cart() as $item ) {
			$product    = $item['data'];
			$net        = (float) $item['line_subtotal'];
			$tax        = (float) $item['line_subtotal_tax'];
			$products[] = array(
				'code'     => (string) $product->get_id(),
				'name'     => $product->get_name(),
				'amount'   => self::normalize_amount( $net + $tax ),
				'currency' => $currency,
				'quantity' => $item['quantity'],
				'type'     => $product->is_virtual() ? 'DIGITAL' : 'PHYSICAL',
			);
		}

		if ( $cart->get_shipping_total() > 0 ) {
			$products[] = array(
				'code'     => 'SHIPPING',
				'name'     => __( 'Shipping', 'payoneer-checkout' ),
				'amount'   => self::normalize_amount( (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax() ),
				'currency' => $currency,
				'quantity' => 1,
				'type'     => 'SERVICE',
			);
		}

		foreach ( (array) $cart->get_fees() as $fee ) {
			$products[] = array(
				'code'     => 'FEE',
				'name'     => $fee->name,
				'amount'   => self::normalize_amount( (float) $fee->total + (float) $fee->tax ),
				'currency' => $currency,
				'quantity' => 1,
				'type'     => 'OTHER',
			);
		}

		$discount_sum = (float) $cart->get_discount_total() + (float) $cart->get_discount_tax();

		if ( $discount_sum > 0 ) {
			$products[] = array(
				'code'     => 'DISCOUNT',
				'name'     => __( 'Discount', 'payoneer-checkout' ),
				'amount'   => self::normalize_amount( -1 * $discount_sum ),
				'currency' => $currency,
				'quantity' => 1,
				'type'     => 'DISCOUNT',
			);
		}

		return $products;
	}


	/**
	 * Build products array from WC order items.
	 *
	 * @param \WC_Order $order       The order.
	 * @param string    $integration Integration type.
	 *
	 * @return array
	 */
	private static function build_products_from_order( $order, $integration = 'HOSTED' ) {
		$products  = array();
		$is_hosted = 'HOSTED' === $integration;
		$currency  = $order->get_currency();

		foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee', 'coupon' ) ) as $item ) {
			$product = null;

			switch ( $item->get_type() ) {
				case 'line_item':
					$product = $item->get_product();
					if ( ! $product ) {
						continue 2;
					}
					$net  = (float) $item->get_subtotal();
					$tax  = (float) $item->get_subtotal_tax();
					$code = (string) $product->get_id();
					$type = $product->is_virtual() ? 'DIGITAL' : 'PHYSICAL';
					$qty  = $item->get_quantity();
					break;

				case 'shipping':
					$net = (float) $item->get_total();
					$tax = (float) $item->get_total_tax();
					if ( 0.0 === $net + $tax ) {
						continue 2;
					}
					$code = 'SHIPPING';
					$type = 'SERVICE';
					$qty  = 1;
					break;

				case 'fee':
					$net  = (float) $item->get_total();
					$tax  = (float) $item->get_total_tax();
					$code = 'FEE';
					$type = 'OTHER';
					$qty  = 1;
					break;

				case 'coupon':
					$net = ( -1 ) * (float) $item->get_discount();
					$tax = ( -1 ) * (float) $item->get_discount_tax();
					if ( 0.0 === $net + $tax ) {
						continue 2;
					}
					$code = 'DISCOUNT';
					$type = 'DISCOUNT';
					$qty  = 1;
					break;

				default:
					continue 2;
			}

			$entry = array(
				'code'     => $code,
				'name'     => $item->get_name(),
				'amount'   => self::normalize_amount( $net + $tax ),
				'currency' => $currency,
				'quantity' => $qty,
				'type'     => $type,
			);

			if ( $is_hosted ) {
				$entry['taxAmount'] = self::normalize_amount( $tax );
				$entry['netAmount'] = self::normalize_amount( $net );

				if ( $product ) {
					$image_id                       = $product->get_image_id();
					$short                          = trim( wp_strip_all_tags( (string) $product->get_short_description() ) );
					$entry['productDescriptionUrl'] = $product->get_permalink();
					$entry['productImageUrl']       = $image_id ? wp_get_attachment_url( $image_id ) : null;
					$entry['description']           = '' === $short ? null : $short;
				}
			}

			$products[] = $entry;
		}

		return $products;
	}


	/**
	 * Build the base callback URLs without order-specific return URL.
	 *
	 * @param string $security_token Optional security token for notification headers.
	 *
	 * @return array
	 */
	private static function build_base_callbacks( $security_token = '' ) {
		$callbacks = array(
			'returnUrl' => wc_get_checkout_url(),
			'cancelUrl' => wc_get_checkout_url(),
		);

		$notification_url = self::get_notification_url();
		if ( ! empty( $notification_url ) ) {
			$callbacks['notificationUrl'] = $notification_url;
		}

		if ( ! empty( $notification_url ) && ! empty( $security_token ) ) {
			$callbacks['notificationHeaders'] = array(
				array(
					'name'  => 'List-Security-Token',
					'value' => $security_token,
				),
			);
		}

		return $callbacks;
	}


	/**
	 * Build callbacks with the order's return URL.
	 *
	 * @param \WC_Order $order          The order.
	 * @param string    $security_token Optional security token for notification headers.
	 *
	 * @return array
	 */
	private static function build_callbacks_from_order( $order, $security_token = '' ) {
		$callbacks = self::build_base_callbacks( $security_token );

		$gateway = Plugin::instance()->gateway();

		$callbacks['returnUrl'] = $gateway ? $gateway->get_return_url( $order ) : $order->get_checkout_order_received_url();
		$callbacks['cancelUrl'] = $order->get_checkout_payment_url();

		return $callbacks;
	}


	/**
	 * Return the webhook notification URL when the environment supports HTTPS callbacks.
	 *
	 * @return string
	 */
	private static function get_notification_url() {
		$notification_url = rest_url( 'inpsyde/payoneer-checkout/listener/notifications' );
		$scheme           = wp_parse_url( $notification_url, PHP_URL_SCHEME );

		return 'https' === strtolower( (string) $scheme ) ? $notification_url : '';
	}


	/**
	 * Build the style object.
	 *
	 * @return array
	 */
	private static function build_style() {
		return array(
			'language' => substr( get_locale(), 0, 2 ),
		);
	}


	/**
	 * Build the system identification object.
	 *
	 * @return array
	 */
	private static function build_system() {
		$versions = array_filter(
			array(
				'payoneer-checkout-plugin' => VERSION,
				'woocommerce-plugin'       => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'wordpress'                => get_bloginfo( 'version' ),
				'php'                      => PHP_VERSION,
			)
		);

		$parts = array();
		foreach ( $versions as $slug => $version ) {
			$parts[] = $slug . ';version=' . $version;
		}

		return array(
			'type'    => 'SHOP_PLATFORM',
			'code'    => 'WOOCOMMERCE',
			'version' => implode( ',', $parts ),
		);
	}


	/**
	 * Build the preselection object with enabled network codes.
	 *
	 * @return array
	 */
	private static function build_preselection() {
		$plugin  = Plugin::instance();
		$is_test = $plugin->is_test_mode();

		$enabled = $plugin->get_enabled_networks( $is_test );

		$network_codes = array_values( array_unique( array_merge( Utils::CC_CODES, $enabled ) ) );

		if ( empty( $network_codes ) ) {
			return array();
		}

		return array(
			'networkCodes' => $network_codes,
		);
	}


	/**
	 * Resolve the country code from customer or store base.
	 *
	 * @return string
	 */
	private static function get_country() {
		$customer_country = WC()->customer ? WC()->customer->get_billing_country() : '';

		if ( ! empty( $customer_country ) ) {
			return $customer_country;
		}

		$base = wc_get_base_location();

		return $base['country'] ?? '';
	}
}
