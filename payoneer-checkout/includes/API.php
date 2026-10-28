<?php
/**
 * API client for Payoneer Checkout.
 */

namespace PayoneerCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all HTTP communication with the Payoneer API.
 */
final class API {

	const URL_TEMPLATE   = 'https://api.%s.oscato.com/api';
	const SANDBOX_URL    = 'https://api.sandbox.oscato.com/api';
	const PRODUCTION_URL = 'https://api.live.oscato.com/api';


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
	 * Get the API base URL based on the current mode.
	 */
	public function get_base_url() {
		/**
		 * Filters the API base URL before a request is sent.
		 *
		 * @param string $base_url Default base URL for the current mode.
		 */
		return apply_filters( 'payoneer_checkout_request_base_url', $this->plugin->gateway_url() );
	}


	/**
	 * Build the API base URL for a named Oscato environment.
	 *
	 * @param string $environment Environment token, e.g. sandbox, live, checkout.integration.
	 *
	 * @return string
	 */
	public static function get_environment_url( $environment ) {
		return sprintf( self::URL_TEMPLATE, trim( (string) $environment ) );
	}


	/**
	 * Send an HTTP request to the Payoneer API.
	 *
	 * @param string $endpoint Relative API endpoint path.
	 * @param string $method   HTTP method.
	 * @param array  $payload  Request body data.
	 *
	 * @return array{success: bool, body?: array, error?: string, http_response?: array}
	 */
	public function request( $endpoint, $method = 'GET', $payload = array() ) {
		$logger = $this->plugin->logger();
		$url    = trailingslashit( $this->get_base_url() ) . ltrim( $endpoint, '/' );
		$args   = array(
			'method'  => $method,
			'headers' => $this->get_headers(),
			/**
			 * Filters the API request body before it is sent.
			 *
			 * @param string $body     Encoded body.
			 * @param string $endpoint API endpoint.
			 */
			'body'    => apply_filters( 'payoneer_checkout_request_body', self::maybe_json_encode( $payload ), $endpoint ),
			'timeout' => 60,
		);

		$logger->log_request( $url, $args );

		$response = wp_safe_remote_request( $url, $args );

		$logger->log_response( $response );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		return $this->process_response( $response );
	}


	/**
	 * Retrieve the list of available divisions (also validates credentials).
	 *
	 * @return array{success: bool, body?: array, error?: string}
	 */
	public function get_divisions() {
		return $this->request( 'meta/divisions', 'GET' );
	}


	/**
	 * Retrieve the available payment networks for a division.
	 *
	 * @param string $division Division name.
	 *
	 * @return array{success: bool, body?: array, error?: string}
	 */
	public function get_networks( $division ) {
		return $this->request( 'meta/divisions/' . $division . '/networks', 'GET' );
	}


	/**
	 * Create a LIST payment session.
	 *
	 * @param array $payload Transaction data for the LIST request.
	 *
	 * @return array{success: bool, body?: array, error?: string}
	 */
	public function create_list( $payload ) {
		return $this->request( 'lists', 'POST', $payload );
	}


	/**
	 * Update an existing LIST payment session.
	 *
	 * @param string $long_id LIST session longId.
	 * @param array  $payload Updated transaction data.
	 *
	 * @return array{success: bool, body?: array, error?: string}
	 */
	public function update_list( $long_id, $payload ) {
		return $this->request( 'lists/' . $long_id, 'PUT', $payload );
	}


	/**
	 * Fetch the current state of a LIST payment session.
	 *
	 * @param string $long_id LIST session longId.
	 *
	 * @return array{success: bool, body?: array, error?: string}
	 */
	public function get_list( $long_id ) {
		return $this->request( 'lists/' . $long_id, 'GET' );
	}


	/**
	 * Initiate a refund/payout against a previously charged transaction.
	 *
	 * @param string $charge_long_id The charge longId.
	 * @param array  $payload        Payout request data.
	 *
	 * @return array{success: bool, body?: array, error?: string}
	 */
	public function payout( $charge_long_id, $payload ) {
		return $this->request( 'charges/' . $charge_long_id . '/payout', 'POST', $payload );
	}


	/**
	 * Build the Base64-encoded Basic Auth string.
	 */
	private function get_authorization() {
		return base64_encode( $this->plugin->api_username() . ':' . $this->plugin->api_token() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}


	/**
	 * Build the HTTP headers for an API request.
	 */
	private function get_headers( $content_type = 'application/json' ) {

		/**
		 * Filters the API request headers.
		 *
		 * @param array  $headers      Default headers.
		 * @param string $content_type Content type.
		 */
		return apply_filters(
			'payoneer_checkout_request_headers',
			array(
				'Authorization' => 'Basic ' . $this->get_authorization(),
				'Content-Type'  => $content_type,
				'Accept'        => $content_type,
			),
			$content_type
		);
	}


	/**
	 * Normalise the raw HTTP response into a result array.
	 */
	private function process_response( $response ) {
		$code = wp_remote_retrieve_response_code( $response );

		if ( empty( $code ) ) {
			return array(
				'success' => false,
				'code'    => 0,
				'error'   => __( 'Empty response code.', 'payoneer-checkout' ),
			);
		}

		$body = self::maybe_json_decode( wp_remote_retrieve_body( $response ) );

		if ( 200 !== $code ) {
			return array(
				'success' => false,
				'code'    => (int) $code,
				'error'   => sprintf(
					/* translators: %1$s: response code or cause, %2$s: explanation message. */
					__( 'Request failed with status code %1$s and message: %2$s', 'payoneer-checkout' ),
					$code,
					self::error_message( $body )
				),
				'body'    => $body,
			);
		}

		return array(
			'success'       => true,
			'code'          => (int) $code,
			'body'          => $body,
			'http_response' => $response,
		);
	}


	/**
	 * Extract a human-readable message from a Payoneer error response body.
	 *
	 * @param mixed $body Decoded response body.
	 *
	 * @return string
	 */
	private static function error_message( $body ) {
		if ( is_string( $body ) ) {
			return $body;
		}

		if ( isset( $body['interaction']['customerMessage'] ) ) {
			$title   = isset( $body['interaction']['customerMessage']['title'] ) ? sanitize_text_field( $body['interaction']['customerMessage']['title'] ) . ': ' : '';
			$message = isset( $body['interaction']['customerMessage']['text'] ) ? sanitize_text_field( $body['interaction']['customerMessage']['text'] ) : '';
			return $title . $message;
		}

		return $body['resultInfo'] ?? '';
	}


	/**
	 * Conditionally JSON-encode data when the payload is a non-empty array.
	 */
	private static function maybe_json_encode( $data ) {
		return ( is_array( $data ) && ! empty( $data ) ) ? wp_json_encode( $data ) : $data;
	}


	/**
	 * Conditionally JSON-decode a string response body.
	 */
	private static function maybe_json_decode( $data ) {
		if ( empty( $data ) ) {
			return null;
		}

		$body = json_decode( $data, true, 512, \JSON_INVALID_UTF8_IGNORE );

		return ( json_last_error() === JSON_ERROR_NONE ) ? $body : $data;
	}
}
