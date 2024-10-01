<?php

declare (strict_types=1);
namespace Syde\Vendor;

use Syde\Vendor\Inpsyde\PaymentGateway\PaymentRequestValidatorInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\AjaxOrderPay\OrderPayload;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\ListUrlPaymentRequestValidator;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRendererFactory;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionManager;
use Syde\Vendor\Psr\Container\ContainerInterface;
return static function (): array {
    return [
        'payoneer_settings.settings_fields' => static function (array $previous, ContainerInterface $container): array {
            /** @var array $cssSettingsFields */
            $cssSettingsFields = $container->get('embedded_payment.settings.fields');
            return \array_merge($previous, $cssSettingsFields);
        },
        'checkout.flow_options' => static function (array $paymentFlowOptions): array {
            $paymentFlowOptions['embedded'] = \__('Embedded', 'payoneer-checkout');
            return $paymentFlowOptions;
        },
        'checkout.flow_options_description' => static function (string $paymentOptionsDescription): string {
            $embeddedDescription = \__('Embedded (default): customers get a payment page that\'s embedded in your shop.', 'payoneer-checkout');
            $paymentOptionsDescription .= '<br>' . $embeddedDescription;
            return $paymentOptionsDescription;
        },
        'inpsyde_payoneer_api.payment_request_validator' => static function (PaymentRequestValidatorInterface $previous, ContainerInterface $container): PaymentRequestValidatorInterface {
            $isEnabled = (bool) $container->get('embedded_payment.is_enabled');
            $isCheckoutPay = (bool) $container->get('wc.is_checkout_pay_page');
            if (!$isEnabled || $isCheckoutPay) {
                return $previous;
            }
            /** @var string $listUrlInputName */
            $listUrlInputName = $container->get('payment_methods.payoneer-checkout.list_url_container_id');
            /** @var ListSessionManager $listSessionManager */
            $listSessionManager = $container->get('list_session.manager');
            return new ListUrlPaymentRequestValidator($listUrlInputName, $listSessionManager, $previous);
        },
        'payment_gateway.payoneer-checkout.payment_fields_renderers' => static function (array $renderers, ContainerInterface $container): array {
            return \array_merge($renderers, PaymentFieldsRendererFactory::forComponent((string) $container->get('payment_methods.payoneer-checkout.payment_fields_component'), $container));
        },
        'payment_gateway.payoneer-afterpay.payment_fields_renderers' => static function (array $renderers, ContainerInterface $container): array {
            return \array_merge($renderers, PaymentFieldsRendererFactory::forComponent((string) $container->get('payment_methods.payoneer-afterpay.payment_fields_component'), $container));
        },
        /**
         * Make consumers aware that the order-pay page now also features an AJAX call
         */
        'wc.is_checkout_pay_page' => static function (bool $previous, ContainerInterface $container): bool {
            if (!$previous) {
                return (bool) $container->get('embedded_payment.ajax_order_pay.is_ajax_order_pay');
            }
            return $previous;
        },
        /**
         * In our AJAX call, the order ID cannot be fetched with get_query_var(),
         * resulting in an empty string. We pick it using information from the AJAX call here.
         */
        'wc.pay_for_order_id' => static function (int $orderId, ContainerInterface $container): int {
            $isAjaxOrderPay = (bool) $container->get('embedded_payment.ajax_order_pay.is_ajax_order_pay');
            if (!$isAjaxOrderPay) {
                return $orderId;
            }
            $payload = $container->get('embedded_payment.ajax_order_pay.checkout_payload');
            \assert($payload instanceof OrderPayload);
            return $payload->getOrder()->get_id();
        },
        'checkout.settings.appearance_settings_fields' => static function (array $fields, ContainerInterface $container): array {
            /** @var array<string, array-key> $customCssFields */
            $customCssFields = $container->get('embedded_payment.settings.fields');
            return \array_merge($fields, $customCssFields);
        },
    ];
};
