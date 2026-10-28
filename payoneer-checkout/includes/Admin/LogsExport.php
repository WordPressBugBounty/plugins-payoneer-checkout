<?php
/**
 * Admin handler for exporting plugin logs and the WC status report.
 */

namespace PayoneerCheckout\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Internal\Admin\Logging\FileV2\FileController;
use Automattic\WooCommerce\Internal\Admin\Logging\LogHandlerFileV2;
use Automattic\WooCommerce\Utilities\LoggingUtil;
use DateTimeImmutable;
use DateTimeZone;
use PayoneerCheckout\Logger;
use PayoneerCheckout\Utils;
use RuntimeException;
use Throwable;
use WC_Admin_Status;
use WC_Log_Handler_DB;
use WC_Log_Handler_File;
use WC_Log_Levels;

/**
 * Renders the export controls and serves download responses.
 */
final class LogsExport {

	private const ACTION               = 'payoneer_export_logs';
	private const STATUS_REPORT_ACTION = 'payoneer_export_logs_status_html';
	private const NONCE                = 'payoneer-export-logs';
	private const FILE_CHUNK_SIZE      = 4 * KB_IN_BYTES;


	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_' . self::STATUS_REPORT_ACTION, array( $this, 'ajax_status_report_html' ) );
	}


	/**
	 * Render the export controls fragment inside the settings card.
	 */
	public function render() {
		$action_url           = admin_url( 'admin-post.php' );
		$action               = self::ACTION;
		$nonce                = self::NONCE;
		$ajax_url             = admin_url( 'admin-ajax.php' );
		$status_report_action = self::STATUS_REPORT_ACTION;

		$notice      = $this->consume_user_notice();
		$notice_code = is_array( $notice ) ? (string) ( $notice['code'] ?? '' ) : '';
		$notice_date = is_array( $notice ) ? (string) ( $notice['date'] ?? '' ) : '';

		include Utils::plugin_path() . '/includes/Admin/Views/logs-export.php';
	}


	/**
	 * Handle the export request (download response).
	 */
	public function handle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'payoneer-checkout' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		$type = isset( $_REQUEST['type'] ) ? sanitize_key( wp_unslash( $_REQUEST['type'] ) ) : '';
		$date = isset( $_REQUEST['date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		switch ( $type ) {
			case 'date':
				$this->send_date_logs( $date );
				return;

			default:
				wp_die( esc_html__( 'Invalid export type.', 'payoneer-checkout' ), '', array( 'response' => 400 ) );
		}
	}


	/**
	 * AJAX: return the WC system status view HTML.
	 */
	public function ajax_status_report_html() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to perform this action.', 'payoneer-checkout' ) ),
				403
			);
		}

		check_ajax_referer( self::NONCE );

		wc_set_time_limit();
		wp_raise_memory_limit( 'admin' );

		$html = $this->render_status_report_html();

		if ( null === $html ) {
			wp_send_json_error( array( 'message' => $this->generic_error_message() ), 500 );
		}

		wp_send_json_success( array( 'html' => $html ) );
	}


	/**
	 * Capture WC's status report view HTML.
	 *
	 * @return string|null
	 */
	private function render_status_report_html() {
		if ( ! class_exists( WC_Admin_Status::class ) ) {
			return null;
		}

		try {
			ob_start();
			WC_Admin_Status::status_report();
			$html = (string) ob_get_clean();
		} catch ( Throwable $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			$this->log_export_error( $e );
			return null;
		}

		return '' !== trim( $html ) ? $html : null;
	}


	/**
	 * Dispatch the date-based export to the right backend for the active log handler.
	 *
	 * @param string $date Date in Y-m-d format.
	 */
	private function send_date_logs( $date ) {
		$parsed = $this->parse_date( $date );

		if ( ! $parsed ) {
			wp_die( esc_html__( 'Invalid date.', 'payoneer-checkout' ), '', array( 'response' => 400 ) );
		}

		$handler = $this->get_handler_class();

		if ( is_a( $handler, LogHandlerFileV2::class, true ) ) {
			$this->emit_filev2( $parsed );
			return;
		}

		if ( is_a( $handler, WC_Log_Handler_DB::class, true ) ) {
			$this->emit_db_rows( $parsed );
			return;
		}

		if ( is_a( $handler, WC_Log_Handler_File::class, true ) ) {
			$this->emit_legacy_files( $parsed );
			return;
		}

		wp_die(
			esc_html__( 'A custom log handler is in use; export is not available.', 'payoneer-checkout' ),
			'',
			array( 'response' => 400 )
		);
	}


	/**
	 * Stream FileV2 log files for the date.
	 *
	 * @param DateTimeImmutable $date Validated UTC date.
	 */
	private function emit_filev2( DateTimeImmutable $date ) {
		$paths = $this->get_filev2_paths( $date );

		if ( null === $paths ) {
			wp_die( esc_html( $this->generic_error_message() ), '', array( 'response' => 500 ) );
		}

		$this->emit_files( $paths, $date );
	}


	/**
	 * Stream legacy file-handler logs for the date.
	 *
	 * @param DateTimeImmutable $date Validated UTC date.
	 */
	private function emit_legacy_files( DateTimeImmutable $date ) {
		$this->emit_files( $this->get_legacy_paths( $date->format( 'Y-m-d' ) ), $date );
	}


	/**
	 * Stream the given log files as a single download, in order.
	 *
	 * @param string[]          $paths Log file paths.
	 * @param DateTimeImmutable $date  Validated UTC date.
	 */
	private function emit_files( array $paths, DateTimeImmutable $date ) {
		if ( empty( $paths ) ) {
			$this->wp_die_no_logs( $date->format( 'Y-m-d' ) );
		}

		$this->send_streaming_log_headers( $this->build_filename( $date ) );

		try {
			foreach ( $paths as $path ) {
				$stream = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen

				if ( ! is_resource( $stream ) ) {
					continue;
				}

				while ( ! feof( $stream ) ) {
					$chunk = fread( $stream, self::FILE_CHUNK_SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					if ( is_string( $chunk ) ) {
						echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
				}

				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				flush();
			}
		} catch ( Throwable $e ) {
			$this->log_export_error( $e );
			// Headers already sent — emit an inline error and exit.
			echo "\n# " . esc_html( $this->generic_error_message() ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		exit;
	}


	/**
	 * Stream DB-handler rows for the date in a single query.
	 *
	 * @param DateTimeImmutable $date Validated UTC date.
	 */
	private function emit_db_rows( DateTimeImmutable $date ) {
		wp_raise_memory_limit( 'admin' );

		$rows = $this->fetch_db_rows( $date );

		if ( null === $rows ) {
			wp_die( esc_html( $this->generic_error_message() ), '', array( 'response' => 500 ) );
		}

		if ( empty( $rows ) ) {
			$this->wp_die_no_logs( $date->format( 'Y-m-d' ) );
		}

		$this->send_streaming_log_headers( $this->build_filename( $date ) );

		$utc = new DateTimeZone( 'UTC' );

		foreach ( $rows as $row ) {
			echo $this->format_db_row( $row, $utc ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		exit;
	}


	/**
	 * Resolve FileV2 log paths for the date. Returns null on container error.
	 *
	 * @param DateTimeImmutable $date
	 *
	 * @return array<int,string>|null
	 */
	private function get_filev2_paths( DateTimeImmutable $date ) {
		try {
			$controller = wc_get_container()->get( FileController::class );
		} catch ( Throwable $e ) {
			$this->log_export_error( $e );
			return null;
		}

		list( $start, $end ) = $this->day_bounds( $date );

		$files = $controller->get_files(
			array(
				'source'      => Logger::SOURCE,
				'date_filter' => 'created',
				'date_start'  => $start,
				'date_end'    => $end,
				'per_page'    => PHP_INT_MAX,
				'order'       => 'asc',
				'orderby'     => 'created',
			)
		);

		if ( is_wp_error( $files ) ) {
			$this->log_export_error( new RuntimeException( $files->get_error_message() ) );
			return null;
		}

		$paths = array();

		foreach ( (array) $files as $file ) {
			if ( 0 === strpos( $file->get_source(), Logger::SOURCE ) ) {
				$paths[] = $file->get_path();
			}
		}

		return $this->sort_paths_by_mtime( $paths );
	}


	/**
	 * Fetch DB-handler rows for the date. Returns null on query error.
	 *
	 * @param DateTimeImmutable $date
	 *
	 * @return array|null
	 */
	private function fetch_db_rows( DateTimeImmutable $date ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'woocommerce_log';
		$bounds = $this->day_bounds( $date );
		$from   = gmdate( 'Y-m-d H:i:s', $bounds[0] );
		$to     = gmdate( 'Y-m-d H:i:s', $bounds[1] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interpolated, params prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT timestamp, level, message, context FROM {$table} WHERE (source = %s OR source LIKE %s) AND timestamp BETWEEN %s AND %s ORDER BY log_id ASC",
				Logger::SOURCE,
				'Payoneer Checkout V%%',
				$from,
				$to
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( '' !== $wpdb->last_error ) {
			$this->log_export_error( new RuntimeException( $wpdb->last_error ) );
			return null;
		}

		return $rows;
	}


	/**
	 * Resolve the active WC log handler class (defaults to file handler).
	 *
	 * @return string
	 */
	private function get_handler_class() {
		if ( class_exists( LoggingUtil::class ) ) {
			$candidate = LoggingUtil::get_default_handler();

			if ( is_string( $candidate ) && class_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return WC_Log_Handler_File::class;
	}


	/**
	 * Validate a `Y-m-d` string and return the parsed UTC DateTimeImmutable, or null on failure.
	 *
	 * @param string $date
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parse_date( $date ) {
		try {
			$utc      = new DateTimeZone( 'UTC' );
			$date_obj = new DateTimeImmutable( $date, $utc );

			return $date_obj > new DateTimeImmutable( 'today', $utc ) ? null : $date_obj;
		} catch ( Throwable $e ) {
			return null;
		}
	}


	/**
	 * Standard download filename for a date-based export.
	 *
	 * @param DateTimeImmutable $date
	 *
	 * @return string
	 */
	private function build_filename( DateTimeImmutable $date ) {
		return Logger::SOURCE . '-' . $date->format( 'Y-m-d' ) . '.log';
	}


	/**
	 * UTC unix-timestamp bounds for the given calendar day.
	 *
	 * @param DateTimeImmutable $date
	 *
	 * @return array{0:int,1:int}
	 */
	private function day_bounds( DateTimeImmutable $date ) {
		return array(
			$date->getTimestamp(),
			$date->modify( '+1 day -1 second' )->getTimestamp(),
		);
	}


	/**
	 * Resolve all legacy log paths for the given date.
	 *
	 * @param string $date Date in Y-m-d format.
	 *
	 * @return string[]
	 */
	private function get_legacy_paths( $date ) {
		if ( ! class_exists( WC_Log_Handler_File::class ) ) {
			return array();
		}

		$dir    = trailingslashit( LoggingUtil::get_log_directory() );
		$needle = Logger::SOURCE . '-' . $date . '-';
		$paths  = array();

		foreach ( WC_Log_Handler_File::get_log_files() as $filename ) {
			if ( 0 === strpos( $filename, $needle ) ) {
				$paths[] = $dir . $filename;
			}
		}

		return $this->sort_paths_by_mtime( $paths );
	}


	/**
	 * Sort log paths chronologically by modification time.
	 *
	 * @param string[] $paths
	 *
	 * @return string[]
	 */
	private function sort_paths_by_mtime( array $paths ) {
		usort(
			$paths,
			static function ( $a, $b ) {
				return filemtime( $a ) <=> filemtime( $b );
			}
		);

		return $paths;
	}


	/**
	 * Configure PHP for a streaming download and emit common headers.
	 * Mirrors the WC `FileExporter::emit_file()` preamble.
	 *
	 * @param string $filename Download filename.
	 */
	private function send_streaming_log_headers( $filename ) {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		if ( function_exists( 'gc_enable' ) ) {
			gc_enable();
		}
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv
		}
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		@ini_set( 'output_buffering', 'Off' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		@ini_set( 'output_handler', '' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		ignore_user_abort( true );
		wc_set_time_limit();
		wc_nocache_headers();
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	}


	/**
	 * Format a DB log row to match the WC file-handler line shape, with an optional CONTEXT line.
	 *
	 * @param object       $row WC log row.
	 * @param DateTimeZone $utc UTC zone.
	 *
	 * @return string
	 */
	private function format_db_row( $row, $utc ) {
		$raw_ts    = (string) ( $row->timestamp ?? '' );
		$timestamp = null;

		if ( '' !== $raw_ts ) {
			try {
				$timestamp = new DateTimeImmutable( $raw_ts, $utc );
			} catch ( Throwable $e ) {
				$timestamp = null;
			}
		}

		$formatted = null !== $timestamp ? $timestamp->format( 'c' ) : '-';
		$level     = WC_Log_Levels::get_severity_level( (int) ( $row->level ?? 0 ) );
		$message   = (string) ( $row->message ?? '' );

		$filter_context = array(
			'level'   => $level,
			'message' => $message,
			'context' => array(),
		);

		if ( null !== $timestamp ) {
			$filter_context['timestamp'] = $timestamp->getTimestamp();
		}

		$entry = apply_filters(
			'woocommerce_format_log_entry',
			sprintf( '%s %s %s', $formatted, strtoupper( $level ), $message ),
			$filter_context
		);

		$context_data = $this->decode_log_context( (string) ( $row->context ?? '' ) );

		if ( ! empty( $context_data ) ) {
			$entry .= "\nCONTEXT: " . wp_json_encode( $context_data );
		}

		return $entry . "\n";
	}


	/**
	 * Decode a WC log context column. WC writes JSON since 3.0; non-JSON values are dropped.
	 *
	 * @param string $raw Raw value from `wp_woocommerce_log.context`.
	 *
	 * @return array
	 */
	private function decode_log_context( $raw ) {
		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}


	/**
	 * Generic translated message used at every export-error site.
	 *
	 * @return string
	 */
	private function generic_error_message() {
		return __( 'There was an error exporting the log file.', 'payoneer-checkout' );
	}


	/**
	 * Redirect back to the gateway settings page with an inline "no logs" notice.
	 *
	 * @param string $date Y-m-d.
	 *
	 * @return never
	 */
	private function wp_die_no_logs( $date ) {
		$this->set_user_notice( 'no_logs', $date );
		wp_safe_redirect( Utils::settings_url() );
		exit;
	}


	/**
	 * Stash a one-shot notice for the current admin user.
	 *
	 * @param string $code Notice code.
	 * @param string $date Y-m-d associated with the notice.
	 */
	private function set_user_notice( $code, $date ) {
		set_transient(
			$this->user_notice_key(),
			array(
				'code' => $code,
				'date' => $date,
			),
			MINUTE_IN_SECONDS
		);
	}


	/**
	 * Read and clear the current user's stashed notice, if any.
	 *
	 * @return array|null
	 */
	private function consume_user_notice() {
		$key    = $this->user_notice_key();
		$notice = get_transient( $key );

		if ( false === $notice ) {
			return null;
		}

		delete_transient( $key );

		return is_array( $notice ) ? $notice : null;
	}


	/**
	 * Per-user transient key for the export notice.
	 *
	 * @return string
	 */
	private function user_notice_key() {
		return 'payoneer_logs_export_notice_' . get_current_user_id();
	}


	/**
	 * Forward an export-side failure to the plugin logger.
	 *
	 * @param Throwable $e
	 */
	private function log_export_error( Throwable $e ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->error(
			$e->getMessage(),
			array(
				'source'    => Logger::SOURCE,
				'exception' => get_class( $e ),
				'file'      => $e->getFile(),
				'line'      => $e->getLine(),
			)
		);
	}
}
