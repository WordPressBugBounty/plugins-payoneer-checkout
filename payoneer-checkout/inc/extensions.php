<?php

declare (strict_types=1);
namespace Syde\Vendor;

use Syde\Vendor\Inpsyde\Modularity\Package;
use Syde\Vendor\Inpsyde\Modularity\Properties\PluginProperties;
use Syde\Vendor\Psr\Container\ContainerInterface;
use Syde\Vendor\Psr\Log\LoggerInterface;
use Syde\Vendor\Psr\Log\LogLevel;
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
            /** @var string $cardsGatewayId */
            $cardsGatewayId = $container->get('payment_methods.payoneer-checkout.id');
            $gatewayIds = $container->get('payment_gateways');
            \assert(\is_array($gatewayIds));
            $gatewaysProcessingSuccess = \array_map(static function (string $gatewayId): array {
                return ['name' => $gatewayId . '_payment_processing_success', 'log_level' => LogLevel::INFO, 'message' => 'Successfully completed payment, CHARGE longId is {chargeId}.'];
            }, $gatewayIds);
            $logEventsToAdd = [['name' => (string) $container->get('core.event_name_environment_validation_failed'), 'log_level' => LogLevel::ERROR, 'message' => 'Environment validation failed: {reason}. {details}'], ['name' => 'payoneer-checkout.update_list_session_failed', 'log_level' => LogLevel::ERROR, 'message' => static function (array $args) {
                $exception = $args['exception'];
                \assert($exception instanceof \Throwable);
                $message = $exception->getMessage();
                return \sprintf('Failed to update LIST session: %1$s.', $message);
            }], ['name' => 'payoneer-checkout.create_list_session_failed', 'log_level' => LogLevel::ERROR, 'message' => 'Failed to create LIST session. Exception caught: {exception}'], ['name' => 'payoneer-checkout.list_session_created', 'log_level' => LogLevel::INFO, 'message' => 'LIST session {longId} was successfully created.'], ['name' => 'payoneer-checkout.payment_processing_failure', 'log_level' => LogLevel::WARNING, 'message' => 'Failed to process checkout payment. Exception caught: {errorMessage}'], ['name' => $cardsGatewayId . '_payment_fields_failure', 'log_level' => LogLevel::ERROR, 'message' => 'Failed to render payment fields. Exception caught: {exception}'], ['name' => 'payoneer-checkout.before_create_list', 'log_level' => LogLevel::INFO, 'message' => 'Started creating list session.'], ['name' => 'payoneer-checkout.log_incoming_notification', 'log_level' => LogLevel::INFO, 'message' => 'Incoming webhook with HTTP method {method}.' . \PHP_EOL . 'Query params are {queryParams}.' . \PHP_EOL . 'Body content is {bodyContents}.' . \PHP_EOL . 'Headers are {headers}.'], ['name' => 'payoneer-checkout.webhook_request.order_not_found', 'log_level' => LogLevel::ERROR, 'message' => 'Order not found by transaction ID {transactionId}, longId is {longId}.'], ['name' => 'payoneer-checkout.webhook_request.order_auth_header_is_incorrect', 'log_level' => LogLevel::ERROR, 'message' => 'Order authorization header is incorrect. Order id is {orderId}, longId is {longId}.'], ['name' => 'payoneer-checkout.webhook_request.webhook_already_processed', 'log_level' => LogLevel::WARNING, 'message' => 'Incoming webhook was processed already, skipping. Order id is {orderId}, longId is {longId}.'], ['name' => 'woocommerce_create_order', 'log_level' => LogLevel::INFO, 'message' => 'Started creating order on checkout.'], ['name' => 'woocommerce_checkout_order_created', 'log_level' => LogLevel::INFO, 'message' => 'Order creating finished.'], ['name' => 'payoneer-checkout.before_update_order_metadata', 'log_level' => LogLevel::INFO, 'message' => 'Order meta update started.'], ['name' => 'payoneer-checkout.after_update_order_metadata', 'log_level' => LogLevel::INFO, 'message' => 'Order meta update finished.'], ['name' => 'payoneer-checkout.before_update_list', 'log_level' => LogLevel::INFO, 'message' => 'Started updating list session {longId}'], ['name' => 'payoneer-checkout.list_session_updated', 'log_level' => LogLevel::INFO, 'message' => 'List session {longId} was successfully updated']];
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
