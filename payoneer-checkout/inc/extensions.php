<?php

declare (strict_types=1);
namespace Syde\Vendor;

// phpcs:disable Inpsyde.CodeQuality.LineLength.TooLong -- this file intentionally contains longer lines.
use Syde\Vendor\Inpsyde\Modularity\Package;
use Syde\Vendor\Inpsyde\Modularity\Properties\PluginProperties;
use Syde\Vendor\Psr\Container\ContainerInterface;
use Syde\Vendor\Psr\Log\LoggerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\ResponseValidator\ValidationFailureInterface;
use Syde\Vendor\Psr\Log\LogLevel;
/**
 * Walk the exception chain to extract API response details from a ValidationFailure.
 *
 * @param Throwable $exception
 * @return string Additional detail string, empty if no response info found.
 */
function payoneer_extract_response_detail(\Throwable $exception): string
{
    $inner = $exception;
    while ($inner) {
        if ($inner instanceof ValidationFailureInterface) {
            try {
                $response = $inner->getSubject();
                $status = $response->getStatusCode();
                $body = $response->getBody();
                $body->rewind();
                $bodyText = (string) $body;
                // Truncate long bodies
                if (\strlen($bodyText) > 500) {
                    $bodyText = \substr($bodyText, 0, 500) . '...(truncated)';
                }
                return \sprintf(' [HTTP %1$d, body=%2$s]', $status, $bodyText ?: '(empty)');
            } catch (\Throwable $e) {
                return ' [could not read response: ' . $e->getMessage() . ']';
            }
        }
        $inner = $inner->getPrevious();
    }
    // No ValidationFailure found — include the full exception chain messages
    $messages = [];
    $inner = $exception->getPrevious();
    while ($inner) {
        $messages[] = $inner->getMessage();
        $inner = $inner->getPrevious();
    }
    return $messages ? ' [caused by: ' . \implode(' → ', $messages) . ']' : '';
}
return static function (): array {
    return [
        /**
         * Underscore in the $_previousLogger variable name used to suppress psalm
         * error {@link https://psalm.dev/docs/running_psalm/issues/UnusedClosureParam/}
         *
         * @phpcs:disable Inpsyde.CodeQuality.VariablesName.SnakeCaseVar
         */
        'inpsyde_logger.logger' => static function (LoggerInterface $_previousLogger, ContainerInterface $container): LoggerInterface {
            /** @var LoggerInterface */
            return $container->get('inpsyde_logger.wc_logger');
        },
        'inpsyde_logger.logging_source' => static function (string $_previous, ContainerInterface $container): string {
            /** @var PluginProperties $pluginProperties */
            $pluginProperties = $container->get(Package::PROPERTIES);
            return $pluginProperties->name();
        },
        'inpsyde_logger.log_events' => static function (array $previous, ContainerInterface $container): array {
            /**
             * TODO: We probably need to register one log event per payment method.
             * Or refactor to use a generic event name
             */
            $cardsGatewayId = 'payoneer-checkout';
            $gatewayIds = $container->get('payment_gateways');
            \assert(\is_array($gatewayIds));
            $gatewaysProcessingSuccess = \array_map(static function (string $gatewayId): array {
                return ['name' => $gatewayId . '_payment_processing_success', 'log_level' => LogLevel::INFO, 'message' => static function (array $args) {
                    $chargeId = $args['chargeId'] ?? '?';
                    $orderId = $args['orderId'] ?? 'n/a';
                    return \sprintf('Successfully completed payment, CHARGE longId is %1$s. [orderId=%2$s]', $chargeId, $orderId);
                }];
            }, $gatewayIds);
            $logEventsToAdd = [['name' => (string) $container->get('core.event_name_environment_validation_failed'), 'log_level' => LogLevel::ERROR, 'message' => 'Environment validation failed: {reason}. {details}'], ['name' => 'payoneer-checkout.update_list_session_failed', 'log_level' => LogLevel::ERROR, 'message' => static function (array $args) {
                $exception = $args['exception'] ?? null;
                $message = $exception instanceof \Throwable ? $exception->getMessage() : '?';
                $detail = $exception instanceof \Throwable ? payoneer_extract_response_detail($exception) : '';
                $orderId = 'n/a';
                if (isset($args['order']) && $args['order'] instanceof \WC_Order) {
                    $orderId = (string) $args['order']->get_id();
                }
                $longId = $args['longId'] ?? 'n/a';
                return \sprintf('Failed to update LIST session: %1$s%2$s [orderId=%3$s, longId=%4$s]', $message, $detail, $orderId, $longId);
            }], ['name' => 'payoneer-checkout.create_list_session_failed', 'log_level' => LogLevel::ERROR, 'message' => static function (array $args) {
                $exception = $args['exception'] ?? null;
                $message = $exception instanceof \Throwable ? $exception->getMessage() : '?';
                $detail = $exception instanceof \Throwable ? payoneer_extract_response_detail($exception) : '';
                return \sprintf('Failed to create LIST session: %1$s%2$s', $message, $detail);
            }], ['name' => 'payoneer-checkout.list_session_created', 'log_level' => LogLevel::INFO, 'message' => static function (array $args) {
                $longId = $args['longId'] ?? '?';
                $txId = '';
                if (isset($args['list']) && \is_object($args['list'])) {
                    try {
                        $txId = $args['list']->getIdentification()->getTransactionId();
                    } catch (\Throwable $e) {
                        $txId = '';
                    }
                }
                return \sprintf('LIST session %1$s was successfully created.%2$s', $longId, $txId ? ' [transactionId=' . $txId . ']' : '');
            }], ['name' => 'payoneer-checkout.payment_processing_failure', 'log_level' => LogLevel::WARNING, 'message' => static function (array $args) {
                $msg = $args['errorMessage'] ?? '?';
                $orderId = $args['orderId'] ?? 'n/a';
                $longId = $args['longId'] ?? 'n/a';
                $network = $args['network'] ?? '';
                return \sprintf('Failed to process checkout payment. %1$s [orderId=%2$s, longId=%3$s%4$s]', $msg, $orderId, $longId, $network ? ', network=' . $network : '');
            }], ['name' => $cardsGatewayId . '_payment_fields_failure', 'log_level' => LogLevel::ERROR, 'message' => static function (array $args) {
                $exception = $args['exception'] ?? null;
                $message = $exception instanceof \Throwable ? $exception->getMessage() : '?';
                return \sprintf('Failed to render payment fields: %1$s', $message);
            }], ['name' => 'payoneer-checkout.before_create_list', 'log_level' => LogLevel::INFO, 'message' => 'Started creating list session.'], ['name' => 'payoneer-checkout.log_incoming_notification', 'log_level' => LogLevel::INFO, 'message' => 'Incoming webhook with HTTP method {method}.' . \PHP_EOL . 'Query params are {queryParams}.' . \PHP_EOL . 'Body content is {bodyContents}.' . \PHP_EOL . 'Headers are {headers}.'], ['name' => 'payoneer-checkout.webhook_request.order_not_found', 'log_level' => LogLevel::ERROR, 'message' => static function (array $args) {
                $txId = $args['transactionId'] ?? '?';
                $longId = $args['longId'] ?? '?';
                $ref = $args['reference'] ?? '?';
                $status = $args['statusCode'] ?? '?';
                $entity = $args['entity'] ?? '?';
                $network = $args['network'] ?? '';
                return \sprintf('Order not found by transaction ID %1$s, longId %2$s [entity=%3$s, statusCode=%4$s, reference="%5$s"%6$s]', $txId, $longId, $entity, $status, $ref, $network ? ', network=' . $network : '');
            }], ['name' => 'payoneer-checkout.webhook_request.order_auth_header_is_incorrect', 'log_level' => LogLevel::ERROR, 'message' => static function (array $args) {
                $orderId = $args['orderId'] ?? '?';
                $longId = $args['longId'] ?? '?';
                $tokenPresent = isset($args['tokenPresent']) ? $args['tokenPresent'] ? 'present' : 'MISSING' : '?';
                $ref = $args['reference'] ?? '?';
                $status = $args['statusCode'] ?? '?';
                $entity = $args['entity'] ?? '?';
                $network = $args['network'] ?? '';
                return \sprintf('Order authorization header is incorrect. Order #%1$s, longId %2$s [token=%3$s, entity=%4$s, statusCode=%5$s, reference="%6$s"%7$s]', $orderId, $longId, $tokenPresent, $entity, $status, $ref, $network ? ', network=' . $network : '');
            }], ['name' => 'payoneer-checkout.webhook_request.webhook_already_processed', 'log_level' => LogLevel::WARNING, 'message' => static function (array $args) {
                $orderId = $args['orderId'] ?? '?';
                $longId = $args['longId'] ?? '?';
                $status = $args['statusCode'] ?? '?';
                $entity = $args['entity'] ?? '?';
                return \sprintf('Incoming webhook already processed, skipping. Order #%1$s, longId %2$s [entity=%3$s, statusCode=%4$s]', $orderId, $longId, $entity, $status);
            }], ['name' => 'payoneer-checkout.webhook_request.multiple_orders_found_for_transaction_id', 'log_level' => LogLevel::WARNING, 'message' => static function (array $args) {
                $txId = $args['transactionId'] ?? '?';
                $orders = $args['orders'] ?? [];
                $status = $args['statusCode'] ?? '?';
                $entity = $args['entity'] ?? '?';
                return \sprintf('Multiple orders found for transactionId %1$s: [%2$s] [entity=%3$s, statusCode=%4$s]', $txId, \implode(', ', \array_map('strval', $orders)), $entity, $status);
            }], ['name' => 'woocommerce_create_order', 'log_level' => LogLevel::INFO, 'message' => 'Started creating order on checkout.'], ['name' => 'woocommerce_checkout_order_created', 'log_level' => LogLevel::INFO, 'message' => 'Order creating finished.'], ['name' => 'payoneer-checkout.before_update_order_metadata', 'log_level' => LogLevel::INFO, 'message' => 'Order meta update started.'], ['name' => 'payoneer-checkout.after_update_order_metadata', 'log_level' => LogLevel::INFO, 'message' => 'Order meta update finished.'], ['name' => 'payoneer-checkout.before_update_list', 'log_level' => LogLevel::INFO, 'message' => static function (array $args) {
                $longId = $args['longId'] ?? '?';
                $source = $args['source'] ?? 'unknown';
                $ref = $args['reference'] ?? '?';
                $hasToken = isset($args['hasSecurityToken']) ? $args['hasSecurityToken'] ? 'yes' : 'no' : '?';
                $orderId = $args['orderId'] ?? 'n/a';
                return \sprintf('Started updating list session %1$s [source=%2$s, reference="%3$s", securityToken=%4$s, orderId=%5$s]', $longId, $source, $ref, $hasToken, $orderId);
            }], ['name' => 'payoneer-checkout.list_session_updated', 'log_level' => LogLevel::INFO, 'message' => static function (array $args) {
                $longId = $args['longId'] ?? '?';
                $source = $args['source'] ?? 'unknown';
                $orderId = $args['orderId'] ?? 'n/a';
                return \sprintf('List session %1$s was successfully updated [source=%2$s, orderId=%3$s]', $longId, $source, $orderId);
            }], ['name' => 'payoneer-checkout.list_update_skipped', 'log_level' => LogLevel::INFO, 'message' => static function (array $args) {
                $longId = $args['longId'] ?? '?';
                $reason = $args['reason'] ?? 'unknown';
                $reasons = ['list_claimed_by_order' => 'LIST already claimed by an order (transient set by process_payment)', 'concurrent_lock_held' => 'concurrent LIST UPDATE in progress (advisory lock held by process_payment)'];
                $detail = $reasons[$reason] ?? $reason;
                return \sprintf('Skipped session-based LIST UPDATE for %1$s — %2$s', $longId, $detail);
            }], ['name' => 'payoneer_checkout.invalid_country_after_final_update', 'log_level' => LogLevel::ERROR, 'message' => static function (array $args) {
                $country = $args['country'] ?? '?';
                $longId = $args['longId'] ?? '?';
                return \sprintf('Final update without List.country set or List.country is different from billing country [listCountry=%1$s, longId=%2$s]', $country ?: '(not set)', $longId);
            }], ['name' => 'payoneer-checkout.missing-headers-for-validation', 'log_level' => LogLevel::ERROR, 'message' => static function (array $args) {
                /**
                 * @var array $headers
                 */
                $headers = $args['headers'];
                return \sprintf('Missing required HTTP header for checkout validation. Headers received: %1$s.', (string) \wc_print_r(\array_keys($headers), \true));
            }], ['name' => 'payoneer-checkout.status-report.email-sent', 'log_level' => LogLevel::INFO, 'message' => 'System report - Successfully sent the email.'], ['name' => 'payoneer-checkout.status-report.email-failed', 'log_level' => LogLevel::ERROR, 'message' => 'System report - Failed to send the email.'], ['name' => 'payoneer-checkout.status-report.cannot-add-attachments', 'log_level' => LogLevel::ERROR, 'message' => 'System report - The PHPMailer instance cannot add string attachments.'], ['name' => 'payoneer-checkout.status-report.attachment-failed', 'log_level' => LogLevel::ERROR, 'message' => 'System report - Failed to attach "{filename}" to the email.'], ['name' => 'payoneer-checkout.refund.status_changed', 'log_level' => LogLevel::INFO, 'message' => 'Refund status - Order {orderId} changed from "{fromStatus}" to "{toStatus}".'], ['name' => 'payoneer-checkout.refund.invalid_status_transition', 'log_level' => LogLevel::ERROR, 'message' => 'Refund status - Order {orderId} failed to change from "{fromStatus}" to "{toStatus}".'], ['name' => 'payoneer-checkout.refund.deserialization_failed', 'log_level' => LogLevel::ERROR, 'message' => 'Refund status - Could not restore refund intention for order {orderId}: {error}'], ['name' => 'payoneer-checkout.refund.map_refund_to_payout', 'log_level' => LogLevel::INFO, 'message' => 'Map WC refund {refundId} to payout {payoutId}.'], ['name' => 'payoneer-checkout.refund-handler.amount_mismatch', 'log_level' => LogLevel::WARNING, 'message' => 'Refund - Amount mismatch between webhook ({webhookAmount}) and intention ({intentionAmount}) for refund {refundId} (order {orderId}). Using webhook amount.'], ['name' => 'payoneer-checkout.refund-handler.api_result', 'log_level' => LogLevel::INFO, 'message' => static function (array $args) {
                $msg = $args['message'] ?? '?';
                $handled = isset($args['handled']) ? $args['handled'] ? 'yes' : 'no' : '?';
                $success = isset($args['success']) ? $args['success'] ? 'yes' : 'no' : '?';
                $async = isset($args['async']) ? $args['async'] ? 'yes' : 'no' : '?';
                return \sprintf('Payout API request result: %1$s [handled=%2$s, success=%3$s, async=%4$s]', $msg, $handled, $success, $async);
            }], ['name' => 'payoneer-checkout.refund-handler.webhook_result', 'log_level' => LogLevel::INFO, 'message' => static function (array $args) {
                $msg = $args['message'] ?? '?';
                $handled = isset($args['handled']) ? $args['handled'] ? 'yes' : 'no' : '?';
                $success = isset($args['success']) ? $args['success'] ? 'yes' : 'no' : '?';
                return \sprintf('Payout notification result: %1$s [handled=%2$s, success=%3$s]', $msg, $handled, $success);
            }], ['name' => 'payoneer-checkout.refund.failure-email-sent', 'log_level' => LogLevel::INFO, 'message' => 'Refund email - Successfully sent the email to {recipient}.'], ['name' => 'payoneer-checkout.refund.failure-email-error', 'log_level' => LogLevel::ERROR, 'message' => 'Refund email - Failed to send the email to {recipient}.'], ['name' => 'payoneer-checkout.admin-notice.dismiss', 'log_level' => LogLevel::INFO, 'message' => 'Admin Notice - Dismissed the admin notice for "{type} {id}".'], ['name' => 'payoneer-checkout.payment_request_validator.validation_success', 'log_level' => LogLevel::INFO, 'message' => 'Payment request validated successfully. {longId}'], ['name' => 'payment_request_validator.validation_failure', 'log_level' => LogLevel::WARNING, 'message' => static function (array $args) {
                $headerValue = $args['headerValue'] ?? '?';
                $currentLongId = $args['currentLongId'] ?? '?';
                return \sprintf('Payment request validation failed [frontendLongId=%1$s, backendLongId=%2$s]', $headerValue, $currentLongId);
            }], ['name' => 'payoneer-checkout.embedded-payment.list-mismatch', 'log_level' => LogLevel::WARNING, 'message' => 'Payment prevented because of backend and frontend LIST mismatch (onBeforeCharge longId check failed).']];
            return \array_merge($previous, $logEventsToAdd, $gatewaysProcessingSuccess);
        },
        'payoneer_sdk.remote_api_url.base_string' => static function (string $_prev, ContainerInterface $container): string {
            $url = $container->get('payoneer_settings.merchant.base_url');
            return (string) $url;
        },
        'payoneer_sdk.command.error_messages' => static function (array $previous): array {
            $localizedMessages = [
                /* translators: Used when encountering the ABORT interaction code */
                'ABORT' => \__('The payment has been aborted', 'payoneer-checkout'),
                /* translators: Used when encountering the TRY_OTHER_NETWORK interaction code */
                'TRY_OTHER_NETWORK' => \__('Please try another network', 'payoneer-checkout'),
                /* translators: Used when encountering the TRY_OTHER_ACCOUNT interaction code */
                'TRY_OTHER_ACCOUNT' => \__('Please try another account', 'payoneer-checkout'),
                /* translators: Used when encountering the RETRY interaction code */
                'RETRY' => \__('Please attempt the payment again', 'payoneer-checkout'),
                /* translators: Used when encountering the VERIFY interaction code */
                'VERIFY' => \__('Payment requires verification', 'payoneer-checkout'),
            ];
            return \array_merge($previous, $localizedMessages);
        },
    ];
};
