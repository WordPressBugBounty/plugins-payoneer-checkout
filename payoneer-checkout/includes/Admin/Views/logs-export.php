<?php
/**
 * Logs export controls fragment for the gateway settings card.
 *
 * @var string $action_url           admin-post.php URL.
 * @var string $action               admin_post action name.
 * @var string $nonce                Nonce action key.
 * @var string $ajax_url             admin-ajax.php URL.
 * @var string $status_report_action AJAX action that returns the WC status view HTML.
 * @var string $notice_code          Optional notice code from a prior export attempt.
 * @var string $notice_date          Optional notice date associated with $notice_code.
 */

defined( 'ABSPATH' ) || exit;
?>
<table
	class="form-table payoneer-form-table wc-settings-prevent-change-event"
	data-payoneer-logs-export
	data-notice-code="<?php echo esc_attr( $notice_code ); ?>"
	data-notice-date="<?php echo esc_attr( $notice_date ); ?>"
>
	<tr>
		<th><label for="payoneer-logs-export-type"><?php esc_html_e( 'Report type', 'payoneer-checkout' ); ?></label></th>
		<td>
			<select id="payoneer-logs-export-type" class="payoneer-logs-export__type">
				<option value=""><?php esc_html_e( 'Choose System Report', 'payoneer-checkout' ); ?></option>
				<option value="status-report"><?php esc_html_e( 'WooCommerce System Status Report', 'payoneer-checkout' ); ?></option>
				<option value="date"><?php esc_html_e( 'Logs for a Specific Date', 'payoneer-checkout' ); ?></option>
			</select>
		</td>
	</tr>

	<tr class="payoneer-logs-export__panel" data-panel="status-report" style="display:none;">
		<th><label><?php esc_html_e( 'Status report', 'payoneer-checkout' ); ?></label></th>
		<td>
			<div
				class="payoneer-logs-export__form"
				id="payoneer-logs-export-status-form"
				data-ajax-url="<?php echo esc_attr( $ajax_url ); ?>"
				data-ajax-action="<?php echo esc_attr( $status_report_action ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( $nonce ) ); ?>"
			>
				<button type="button" id="payoneer-logs-export-status-btn" class="button button-primary">
					<?php esc_html_e( 'Download status report', 'payoneer-checkout' ); ?>
				</button>
			</div>
			<div id="payoneer-logs-export-status-host" hidden></div>
		</td>
	</tr>

	<tr class="payoneer-logs-export__panel" data-panel="date" style="display:none;">
		<th><label for="payoneer-logs-export-date"><?php esc_html_e( 'Date', 'payoneer-checkout' ); ?></label></th>
		<td>
			<div
				class="payoneer-logs-export__form"
				id="payoneer-logs-export-date-form"
				data-action-url="<?php echo esc_attr( $action_url ); ?>"
				data-action="<?php echo esc_attr( $action ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( $nonce ) ); ?>"
			>
				<input type="text" id="payoneer-logs-export-date" name="date" class="payoneer-logs-export__datepicker" placeholder="yyyy-mm-dd" pattern="\d{4}-\d{2}-\d{2}" size="11" autocomplete="off">
				<button type="button" id="payoneer-logs-export-date-btn" class="button button-primary">
					<?php esc_html_e( 'Download', 'payoneer-checkout' ); ?>
				</button>
			</div>
		</td>
	</tr>

	<tr class="payoneer-logs-export__notice-row" style="display:none;">
		<td colspan="2">
			<div class="payoneer-logs-export__notice notice inline" role="status" aria-live="polite"></div>
		</td>
	</tr>
</table>
