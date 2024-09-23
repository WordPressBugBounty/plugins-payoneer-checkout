<?php

declare(strict_types=1);

use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\ListSessionProvider;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\AjaxOrderPay\OrderPayload;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\ListUrlPaymentRequestValidator;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer\CheckoutHashFieldRenderer;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer\HiddenInputRenderer;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer\ListDebugFieldRenderer;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer\ListUrlFieldRenderer;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer\WidgetPlaceholderFieldRenderer;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentRequestValidatorInterface;
use Psr\Container\ContainerInterface;

return static function (): array {
    return [
        'inpsyde_payment_gateway.settings_fields' => static function (
            array $previous,
            ContainerInterface $container
        ): array {
            /** @var array $cssSettingsFields */
            $cssSettingsFields = $container->get('embedded_payment.settings.fields');

            return array_merge(
                $previous,
                $cssSettingsFields
            );
        },
        'checkout.flow_options' => static function (
            array $paymentFlowOptions
        ): array {
            $paymentFlowOptions['embedded'] = __('Embedded', 'payoneer-checkout');

            return $paymentFlowOptions;
        },
        'checkout.flow_options_description' => static function (
            string $paymentOptionsDescription
        ): string {
            $embeddedDescription = __(
                'Embedded (default): customers get a payment page that\'s embedded in your shop.',
                'payoneer-checkout'
            );
            $paymentOptionsDescription .= '<br>' . $embeddedDescription;

            return $paymentOptionsDescription;
        },
        'inpsyde_payment_gateway.payment_request_validator' => static function (
            PaymentRequestValidatorInterface $previous,
            ContainerInterface $container
        ): PaymentRequestValidatorInterface {
            $isEnabled = (bool)$container->get('embedded_payment.is_enabled');
            $isCheckoutPay = (bool)$container->get('wc.is_checkout_pay_page');
            if (! $isEnabled || $isCheckoutPay) {
                return $previous;
            }

            /** @var string $listUrlInputName */
            $listUrlInputName = $container->get('inpsyde_payment_gateway.list_url_container_id');
            /** @var ListSessionProvider $listSessionProvider */
            $listSessionProvider = $container->get('checkout.list_session_provider');

            return new ListUrlPaymentRequestValidator(
                $listUrlInputName,
                $listSessionProvider,
                $previous
            );
        },
        'checkout.payment_field_renderers' => static function (
            array $renderers,
            ContainerInterface $container
        ): array {
            $isEnabled = (bool)$container->get('embedded_payment.is_enabled');
            if (! $isEnabled) {
                return $renderers;
            }

            $isCheckout = (bool)$container->get('wc.is_checkout');
            $isFragmentUpdate = (bool)$container->get('wc.is_fragment_update');
            $isOrderPay = (bool)$container->get('wc.is_checkout_pay_page');
            $shouldRenderList = $isFragmentUpdate || $isOrderPay;
            if (! ($isCheckout || $shouldRenderList)) {
                return $renderers;
            }
            /**
             * We add the flag to override hosted mode always, but then remove it in our JS
             * after the payment widget is loaded and right before it does CHARGE. If the payment
             * widget never initialized or failed trying to, the flag submitted with the form and
             * we are falling back to hosted mode.
             */
            $paymentFlowFlag = $container->get('checkout.payment_flow_override_flag');
            $renderers[] = new HiddenInputRenderer((string)$paymentFlowFlag);

            $onErrorFlag = $container->get('checkout.on_error_refresh_fragment_flag');
            $renderers[] = new HiddenInputRenderer((string)$onErrorFlag, "false");
            $listUrlRenderer = $container->get('embedded_payment.payment_fields_renderer.list_url');
            assert($listUrlRenderer instanceof ListUrlFieldRenderer);
            $placeholderRenderer = $container->get(
                'embedded_payment.payment_fields_renderer.placeholder'
            );
            assert($placeholderRenderer instanceof WidgetPlaceholderFieldRenderer);

            $checkoutHashRenderer = $container->get(
                'embedded_payment.payment_fields_renderer.list_hash'
            );
            assert($checkoutHashRenderer instanceof CheckoutHashFieldRenderer);

            $shouldRenderList && $renderers[] = $listUrlRenderer;
            $renderers[] = $placeholderRenderer;
            $renderers[] = $checkoutHashRenderer;
            $isDebug = (bool)$container->get('checkout.is_debug');
            if ($isDebug && $shouldRenderList) {
                $debugRenderer = $container->get(
                    'embedded_payment.payment_fields_renderer.debug'
                );
                assert($debugRenderer instanceof ListDebugFieldRenderer);
                $renderers[] = $debugRenderer;
            }

            return $renderers;
        },
        /**
         * Make consumers aware that the order-pay page now also features an AJAX call
         */
        'wc.is_checkout_pay_page' => static function (
            bool $previous,
            ContainerInterface $container
        ): bool {
            if (! $previous) {
                return (bool)$container->get('embedded_payment.ajax_order_pay.is_ajax_order_pay');
            }

            return $previous;
        },
        /**
         * In our AJAX call, the order ID cannot be fetched with get_query_var(),
         * resulting in an empty string. We pick it using information from the AJAX call here.
         */
        'wc.pay_for_order_id' => static function (
            int $orderId,
            ContainerInterface $container
        ): int {
            $isAjaxOrderPay = (bool)$container->get(
                'embedded_payment.ajax_order_pay.is_ajax_order_pay'
            );
            if (! $isAjaxOrderPay) {
                return $orderId;
            }
            $payload = $container->get('embedded_payment.ajax_order_pay.checkout_payload');
            assert($payload instanceof OrderPayload);

            return $payload->getOrder()->get_id();
        },

        'checkout.settings.appearance_settings_fields' => static function (
            array $fields,
            ContainerInterface $container
        ): array {
            /** @var array<string, array-key> $customCssFields */
            $customCssFields = $container->get('embedded_payment.settings.fields');

            return array_merge($fields, $customCssFields);
        },
    ];
};
