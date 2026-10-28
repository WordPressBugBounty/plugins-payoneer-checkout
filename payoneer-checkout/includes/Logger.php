<?php
/**
 * Logger service for payment event and API interaction logging.
 */

namespace PayoneerCheckout;

use WC_Logger;
use WP_HTTP_Requests_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the WooCommerce logger with a kill-switch setting.
 */
final class Logger {

	// Log source ID.
	public const SOURCE = 'payoneer-checkout';

	/** @var WC_Logger|null */
	private ?WC_Logger $wc_logger = null;

	/** @var Plugin */
	private Plugin $plugin;

	/** @var bool */
	private bool $force_disabled = false;


	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}


	private const REDACTED = '*****';


	/**
	 * Always log errors, other levels only when enabled in settings.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level.
	 * @param array  $context Additional context data (shown under "Additional context" in WC log viewer).
	 *
	 * @return void
	 */
	public function log( $message, $level = 'debug', $context = array() ) {

		if ( $this->force_disabled || ( 'error' !== $level && ! $this->is_enabled() ) ) {
			return;
		}

		if ( ! $this->wc_logger ) {
			$this->wc_logger = wc_get_logger();
		}

		$context['source'] = self::SOURCE;

		$this->wc_logger->log( $level, $message, $context );
	}


	/**
	 * Log an outgoing HTTP request.
	 *
	 * @param string $url   Request URL.
	 * @param array  $args  Request arguments.
	 * @param string $level Log level.
	 *
	 * @return void
	 */
	public function log_request( $url, $args, $level = 'debug' ) {
		unset( $args['headers'] );
		$method  = $args['method'] ?? 'POST';
		$context = array();

		if ( ! empty( $args['body'] ) ) {
			$context['body'] = self::redact( self::maybe_decode_json( $args['body'] ) );
		}

		$this->log( $method . ' ' . $url, $level, $context );
	}


	/**
	 * Log an HTTP response.
	 *
	 * @param \WP_Error|array $response Response.
	 * @param string          $level    Log level.
	 *
	 * @return void
	 */
	public function log_response( $response, $level = 'debug' ) {

		if ( is_wp_error( $response ) ) {
			$this->log(
				'Response [Error]: ' . $response->get_error_code() . ' — ' . $response->get_error_message(),
				'error'
			);
			return;
		}

		if ( is_array( $response ) && isset( $response['http_response'] ) && is_a( $response['http_response'], WP_HTTP_Requests_Response::class ) ) {
			$code    = $response['http_response']->get_status();
			$body    = $response['http_response']->get_data();
			$context = array();

			if ( ! empty( $body ) ) {
				$context['body'] = self::redact( self::maybe_decode_json( $body ) );
			}

			$this->log( 'Response [' . $code . ']', $level, $context );
			return;
		}

		$this->log( 'Response: empty', $level );
	}


	/**
	 * Decode a JSON string into an array, or return the original value if not valid JSON.
	 *
	 * @param mixed $data Value to attempt JSON decoding on.
	 *
	 * @return mixed
	 */
	private static function maybe_decode_json( $data ) {
		if ( ! is_string( $data ) ) {
			return $data;
		}

		$decoded = json_decode( $data, true );

		return ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : $data;
	}


	/**
	 * Mask the customer-PII fields.
	 *
	 * @param mixed $payload Decoded request/response body.
	 *
	 * @return mixed
	 */
	public static function redact( $payload ) {
		if ( ! is_array( $payload ) ) {
			return $payload;
		}

		if ( Plugin::instance()->is_test_mode() && defined( 'PAYONEER_TEST_MODE_LOG' ) && PAYONEER_TEST_MODE_LOG ) {
			return $payload;
		}

		if ( isset( $payload['customer'] ) && is_array( $payload['customer'] ) ) {
			$payload['customer'] = self::redact_customer( $payload['customer'] );
		}

		if ( isset( $payload['callback']['notificationHeaders'] ) && is_array( $payload['callback']['notificationHeaders'] ) ) {
			foreach ( $payload['callback']['notificationHeaders'] as $i => $header ) {
				if ( is_array( $header ) && 'List-Security-Token' === ( $header['name'] ?? '' ) ) {
					$payload['callback']['notificationHeaders'][ $i ]['value'] = self::REDACTED;
				}
			}
		}

		return $payload;
	}


	/**
	 * Apply the customer-PII path mask in-place.
	 *
	 * @param array $customer The decoded customer subtree.
	 *
	 * @return array
	 */
	private static function redact_customer( array $customer ) {
		if ( ! empty( $customer['email'] ) ) {
			$customer['email'] = self::REDACTED;
		}

		if ( ! empty( $customer['phones'] ) ) {
			$customer['phones'] = self::REDACTED;
		}

		if ( isset( $customer['name']['firstName'] ) ) {
			$customer['name']['firstName'] = self::REDACTED;
		}
		if ( isset( $customer['name']['lastName'] ) ) {
			$customer['name']['lastName'] = self::REDACTED;
		}

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( ! isset( $customer['addresses'][ $type ] ) || ! is_array( $customer['addresses'][ $type ] ) ) {
				continue;
			}

			if ( isset( $customer['addresses'][ $type ]['street'] ) ) {
				$customer['addresses'][ $type ]['street'] = self::REDACTED;
			}
			if ( isset( $customer['addresses'][ $type ]['name']['firstName'] ) ) {
				$customer['addresses'][ $type ]['name']['firstName'] = self::REDACTED;
			}
			if ( isset( $customer['addresses'][ $type ]['name']['lastName'] ) ) {
				$customer['addresses'][ $type ]['name']['lastName'] = self::REDACTED;
			}
		}

		return $customer;
	}


	/**
	 * Whether logging is enabled in the gateway settings.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return $this->plugin->is_logging_enabled();
	}


	/**
	 * Force disable the logger.
	 *
	 * @return void
	 */
	public function force_disable() {
		$this->force_disabled = true;
	}


	/**
	 * Restore the logger after force disabling.
	 *
	 * @return void
	 */
	public function restore_force_disable() {
		$this->force_disabled = false;
	}
}
