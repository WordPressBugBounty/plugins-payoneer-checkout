<?php

declare (strict_types=1);
namespace Syde\Vendor;

use Syde\Vendor\Dhii\Services\Factories\Alias;
use Syde\Vendor\Dhii\Services\Factories\Constructor;
use Syde\Vendor\Dhii\Services\Factories\Value;
use Syde\Vendor\Dhii\Services\Factory;
use Syde\Vendor\Inpsyde\PaymentGateway\GatewayIconsRendererInterface;
use Syde\Vendor\Inpsyde\PaymentGateway\PaymentFieldsRendererInterface;
use Syde\Vendor\Inpsyde\PaymentGateway\PaymentGateway;
use Syde\Vendor\Inpsyde\PaymentGateway\PaymentProcessorInterface;
use Syde\Vendor\Inpsyde\PaymentGateway\PaymentRequestValidatorInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Api\Gateway\CommandFactory\WcOrderBasedUpdateCommandFactoryInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\Authentication\TokenGeneratorInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\GatewayIconsRenderer\GatewayIconsRenderer;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\MisconfigurationDetector\MisconfigurationDetectorInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\PaymentFieldsRenderer\CompoundPaymentFieldsRenderer;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentProcessor\EmbeddedPaymentProcessor;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\HostedPayment\PaymentProcessor\HostedPaymentProcessor;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionManager;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\PaymentMethods\AvailabilityCallback\AvailabilityCallbackInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\PaymentMethods\AvailabilityCallback\CompoundAvailabilityCallback;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\PaymentMethods\AvailabilityCallback\FilteredAvailabilityCallback;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\PaymentMethods\AvailabilityCallback\ListConditionAvailabilityCallback;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\PaymentMethods\AvailabilityCallback\LiveModeAvailabilityCallback;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\PaymentMethods\AvailabilityCallback\ConditionalCallbackDecorator;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\PaymentMethods\ExcludeNotSupportedCountries;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\PaymentMethods\ListCondition\MatchNetworkCodeCondition;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\PaymentMethods\ListCondition\MatchNetworkGroupingCondition;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\PaymentMethods\ListCondition\NoopListCondition;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\PaymentMethods\RefundProcessor;
use Syde\Vendor\Psr\Container\ContainerInterface;
return static function (): array {
    return [
        'payment_methods.payoneer-checkout.id' => new Value('payoneer-checkout'),
        'payment_methods.payoneer-hosted.id' => new Value('payoneer-hosted'),
        'payment_methods.payoneer-afterpay.id' => new Value('payoneer-afterpay'),
        'payment_methods.payoneer-checkout.instance' => new Factory(['wc', 'payment_methods.payoneer-checkout.id'], static function (\WooCommerce $wooCommerce, string $id) {
            $gateways = $wooCommerce->payment_gateways()->payment_gateways();
            return $gateways[$id];
        }),
        'payment_methods.payoneer-hosted.instance' => new Factory(['wc', 'payment_methods.payoneer-hosted.id'], static function (\WooCommerce $wooCommerce, string $id) {
            $gateways = $wooCommerce->payment_gateways()->payment_gateways();
            return $gateways[$id];
        }),
        'payment_methods.payoneer-afterpay.instance' => new Factory(['wc', 'payment_methods.payoneer-afterpay.id'], static function (\WooCommerce $wooCommerce, string $id) {
            $gateways = $wooCommerce->payment_gateways()->payment_gateways();
            return $gateways[$id];
        }),
        'payment_gateway.payoneer-checkout.supports' => new Value(['products', 'refunds']),
        'payment_gateway.payoneer-hosted.supports' => new Alias('payment_gateway.payoneer-checkout.supports'),
        'payment_gateway.payoneer-afterpay.supports' => new Alias('payment_gateway.payoneer-checkout.supports'),
        'payment_gateway.payoneer-checkout.register_blocks' => new Value(\false),
        'payment_gateway.payoneer-hosted.register_blocks' => new Value(\false),
        'payment_gateway.payoneer-afterpay.register_blocks' => new Value(\false),
        'payment_gateway.payoneer-checkout.method_title' => new Factory([], static function (): string {
            return \__('Payoneer Checkout - Credit / Debit cards', 'payoneer-checkout');
        }),
        'payment_methods.fallback_title' => fn() => \__('Pay with', 'payoneer-checkout'),
        'payment_gateway.payoneer-checkout.title' => new Factory(['payoneer_settings.is_payments_settings_page', 'payment_methods.payoneer-checkout.gateway_icons_renderer', 'wc.is_checkout', 'payment_methods.is_live_mode', 'payment_methods.payoneer-checkout.instance', 'payment_methods.fallback_title'], static function (bool $isPaymentsSettingsPage, GatewayIconsRendererInterface $gatewayIconsRenderer, bool $isCheckout, bool $isLiveMode, \WC_Payment_Gateway $gateway, string $fallbackTitle): string {
            $baseName = (string) $gateway->get_option('title-payoneer-checkout');
            if ($baseName === '') {
                $baseName = $fallbackTitle;
            }
            if ($isCheckout && !$isLiveMode) {
                $baseName = \__('Test:', 'payoneer-checkout') . ' ' . $baseName;
            }
            if (!$isPaymentsSettingsPage) {
                return $baseName;
            }
            return \sprintf('%1$s %2$s', $baseName, $gatewayIconsRenderer->renderIcons());
        }),
        'payment_gateway.payoneer-hosted.method_title' => new Factory([], static function (): string {
            return \__('Payoneer Checkout - Hosted payment page', 'payoneer-checkout');
        }),
        'payment_gateway.payoneer-hosted.title' => new Factory(['payoneer_settings.is_payments_settings_page', 'payment_methods.payoneer-hosted.gateway_icons_renderer', 'wc.is_checkout', 'payment_methods.is_live_mode', 'payment_methods.payoneer-hosted.instance', 'payment_methods.fallback_title'], static function (bool $isPaymentsSettingsPage, GatewayIconsRendererInterface $gatewayIconsRenderer, bool $isCheckout, bool $isLiveMode, \WC_Payment_Gateway $gateway, string $fallbackTitle): string {
            $baseName = (string) $gateway->get_option('title-payoneer-hosted');
            if ($baseName === '') {
                $baseName = $fallbackTitle;
            }
            if ($isCheckout && !$isLiveMode) {
                $baseName = \__('Test:', 'payoneer-checkout') . ' ' . $baseName;
            }
            if (!$isPaymentsSettingsPage) {
                return $baseName;
            }
            return \sprintf('%1$s %2$s', $baseName, $gatewayIconsRenderer->renderIcons());
        }),
        'payment_gateway.payoneer-afterpay.method_title' => new Factory([], static function (): string {
            return \__('Payoneer Checkout - Afterpay', 'payoneer-checkout');
        }),
        'payment_gateway.payoneer-afterpay.title' => new Factory(['payoneer_settings.is_payments_settings_page', 'payment_methods.payoneer-afterpay.gateway_icons_renderer', 'wc.is_checkout', 'payment_methods.is_live_mode', 'payment_methods.payoneer-afterpay.instance', 'payment_methods.fallback_title'], static function (bool $isPaymentsSettingsPage, GatewayIconsRendererInterface $gatewayIconsRenderer, bool $isCheckout, bool $isLiveMode, \WC_Payment_Gateway $gateway, string $fallbackTitle): string {
            $baseName = (string) $gateway->get_option('title-payoneer-afterpay');
            if ($baseName === '') {
                $baseName = $fallbackTitle;
            }
            if ($isCheckout && !$isLiveMode) {
                $baseName = \__('Test:', 'payoneer-checkout') . ' ' . $baseName;
            }
            if (!$isPaymentsSettingsPage) {
                return $baseName;
            }
            return \sprintf('%1$s %2$s', $baseName, $gatewayIconsRenderer->renderIcons());
        }),
        'payment_gateway.payoneer-checkout.order_button_text' => new Factory([], static function (): string {
            return \__('Pay', 'payoneer-checkout');
        }),
        'payment_gateway.payoneer-hosted.description' => new Factory(['payment_methods.payoneer-hosted.instance'], static fn(\WC_Payment_Gateway $gateway): string => (string) $gateway->get_option('description-payoneer-hosted')),
        'payment_gateway.payoneer-hosted.method_description' => new Alias('payment_gateway.payoneer-checkout.method_description'),
        'payment_gateway.payoneer-afterpay.method_description' => new Alias('payment_gateway.payoneer-checkout.method_description'),
        'payment_methods.payoneer-checkout.method_description.payments_settings_page' => static function (): string {
            $description = \__('Payoneer Checkout is the next generation of payment processing platforms.', 'payoneer-checkout');
            $descriptionLegal = \sprintf(
                /* translators: %1$s, %2$s, %3$s and %4$s are replaced with opening and closing 'a' tags. */
                \__('By using Payoneer Checkout, you agree to the %1$sTerms of Service%2$s and %3$sPrivacy policy%4$s.', 'payoneer-checkout'),
                '<a href="https://www.payoneer.com/legal-agreements/?cnty=HK" target="_blank">',
                '</a>',
                '<a target="_blank" href="https://www.payoneer.com/legal/privacy-policy/">',
                '</a>'
            );
            return \sprintf('<p>%1$s</p><p>%2$s</p>', $description, $descriptionLegal);
        },
        'payment_methods.payoneer-checkout.method_description.settings_page' => new Factory([], static function (): string {
            return \sprintf(
                /* translators: %1$s, %2$s, %3$s, %4$s, %5$s and %6$s is replaced with the opening and closing 'a' tags.*/
                \__('Before you begin read How to %1$sConnect WooCommerce%2$s to Payoneer Checkout. Make sure you have a Payoneer Account. If you don\'t, see %3$sRegister for Checkout%4$s. You can get your %5$sauthentication data%6$s in the Payoneer Account.', 'payoneer-checkout'),
                '<a href="https://checkoutdocs.payoneer.com/docs/integrate-with-woocommerce" target="_blank">',
                '</a>',
                '<a href="https://www.payoneer.com/solutions/checkout/woocommerce-integration/?utm_source=Woo+plugin&utm_medium=referral&utm_campaign=WooCommerce+config+page#form-modal-trigger" target="_blank">',
                '</a>',
                '<a href="https://myaccount.payoneer.com/ma/checkout/tokens" target="_blank">',
                '</a>'
            );
        }),
        'payment_gateway.payoneer-checkout.method_description' => new Factory(['payment_methods.payoneer-checkout.method_description.payments_settings_page', 'payment_methods.payoneer-checkout.method_description.settings_page', 'payoneer_settings.is_payments_settings_page'], static function (string $paymentsSettingsPageDescription, string $settingsPageDescription, bool $isPaymentsSettingsPage): string {
            if ($isPaymentsSettingsPage) {
                return $paymentsSettingsPageDescription;
            }
            return $settingsPageDescription;
        }),
        'payment_methods.availability_callback.checkout_predicate' => static function (ContainerInterface $container) {
            return static function () use ($container) {
                return $container->get('wc.is_checkout') || $container->get('wc.is_checkout_pay_page');
            };
        },
        'payment_methods.availability_callback.live_mode' => new Constructor(LiveModeAvailabilityCallback::class, ['payment_methods.is_live_mode', 'wc.admin_permission', 'payment_methods.show_payment_widget_to_customers_in_sandbox_mode']),
        'payment_methods.payoneer-hosted.availability_callback' => new Factory(['payment_methods.availability_callback.live_mode', 'list_session.manager', 'embedded_payment.ajax_order_pay.is_ajax_order_pay', 'payment_methods.availability_callback.checkout_predicate'], static function (AvailabilityCallbackInterface $liveModeCallback, ListSessionManager $listSessionManager, bool $isAjaxOrderPay, callable $checkoutPredicate): AvailabilityCallbackInterface {
            $callbacks = [$liveModeCallback];
            $callbacks[] = new ConditionalCallbackDecorator($checkoutPredicate, new ListConditionAvailabilityCallback($listSessionManager, new NoopListCondition(), $isAjaxOrderPay));
            return new CompoundAvailabilityCallback(...$callbacks);
        }),
        'payment_methods.payoneer-afterpay.availability_callback' => new Factory(['payment_methods.availability_callback.live_mode', 'list_session.manager', 'embedded_payment.ajax_order_pay.is_ajax_order_pay', 'checkout.payment_flow_override_flag.is_set', 'payment_methods.availability_callback.checkout_predicate'], static function (
            //todo: consider refactoring, these callbacks are almost the same.
            AvailabilityCallbackInterface $liveModeCallback,
            ListSessionManager $listSessionManager,
            bool $isAjaxOrderPay,
            bool $hppOverrideFlag,
            callable $checkoutPredicate
        ): AvailabilityCallbackInterface {
            if ($hppOverrideFlag) {
                return $liveModeCallback;
            }
            $callbacks = [$liveModeCallback];
            $callbacks[] = new ConditionalCallbackDecorator($checkoutPredicate, new CompoundAvailabilityCallback(new ListConditionAvailabilityCallback($listSessionManager, new MatchNetworkGroupingCondition('DIRECT_DEBIT'), $isAjaxOrderPay), new ListConditionAvailabilityCallback($listSessionManager, new MatchNetworkCodeCondition('AFTERPAY'), $isAjaxOrderPay)));
            return new CompoundAvailabilityCallback(...$callbacks);
        }),
        'payment_methods.payoneer-checkout.availability_callback' => new Factory(['payment_methods.availability_callback.live_mode', 'list_session.manager', 'embedded_payment.ajax_order_pay.is_ajax_order_pay', 'checkout.payment_flow_override_flag.is_set', 'payment_methods.availability_callback.checkout_predicate'], static function (AvailabilityCallbackInterface $liveModeCallback, ListSessionManager $listSessionManager, bool $isAjaxOrderPay, bool $hppOverrideFlag, callable $checkoutPredicate): AvailabilityCallbackInterface {
            if ($hppOverrideFlag) {
                return $liveModeCallback;
            }
            $callbacks = [$liveModeCallback];
            $callbacks[] = new ConditionalCallbackDecorator($checkoutPredicate, new ListConditionAvailabilityCallback($listSessionManager, new MatchNetworkGroupingCondition('CREDIT_CARD'), $isAjaxOrderPay));
            return new CompoundAvailabilityCallback(...$callbacks);
        }),
        'payment_gateway.payoneer-checkout.availability_callback' => new Factory(['payment_methods.payoneer-checkout.availability_callback'], static fn(AvailabilityCallbackInterface $callback): AvailabilityCallbackInterface => new FilteredAvailabilityCallback($callback)),
        'payment_gateway.payoneer-hosted.availability_callback' => new Factory(['payment_methods.payoneer-hosted.availability_callback'], static fn(AvailabilityCallbackInterface $callback): AvailabilityCallbackInterface => new FilteredAvailabilityCallback($callback)),
        'payment_gateway.payoneer-afterpay.availability_callback' => new Factory(['payment_methods.payoneer-afterpay.availability_callback'], static fn(AvailabilityCallbackInterface $callback): AvailabilityCallbackInterface => new FilteredAvailabilityCallback($callback)),
        'payment_methods.live_merchant_id' => new Value(1),
        'payment_methods.sandbox_merchant_id' => new Value(2),
        'payment_methods.default_options' => new Factory(['payoneer_sdk.remote_api_url.base_string.live', 'payoneer_sdk.remote_api_url.base_string.sandbox', 'payment_methods.live_merchant_id', 'payment_methods.sandbox_merchant_id', 'payoneer_settings.merchant.label.live', 'payoneer_settings.merchant.label.sandbox'], static function (string $liveUrl, string $sandboxUrl, int $liveMerchantId, int $sandboxMerchantId, string $liveLabel, string $sandboxLabel): array {
            return ['live_mode' => 'no', 'merchant_id' => $liveMerchantId, 'base_url' => $liveUrl, 'label' => $liveLabel, 'sandbox_merchant_id' => $sandboxMerchantId, 'sandbox_base_url' => $sandboxUrl, 'sandbox_label' => $sandboxLabel];
        }),
        'payment_methods.transaction_url_template_field_name' => new Value('_transaction_url_template'),
        'payment_gateway.payoneer-hosted.payment_processor' => new Factory(['list_session.manager', 'payment_methods.order.transaction_id_field_name', 'hosted_payment.misconfiguration_detector', 'hosted_payment.order_based_update_command_factory', 'checkout.security_token_generator', 'checkout.order.security_header_field_name', 'hosted_payment.payment_flow_override_flag.is_set', 'checkout.session_hash_key'], static function (ListSessionManager $listSessionManager, string $transactionIdFieldName, MisconfigurationDetectorInterface $misconfigurationDetector, WcOrderBasedUpdateCommandFactoryInterface $updateCommandFactory, TokenGeneratorInterface $tokenGenerator, string $tokenKey, bool $fallbackToHostedModeFlag, string $sessionHashKey): PaymentProcessorInterface {
            return new HostedPaymentProcessor($listSessionManager, $transactionIdFieldName, $misconfigurationDetector, $listSessionManager, $updateCommandFactory, $tokenGenerator, $tokenKey, $fallbackToHostedModeFlag, $sessionHashKey);
        }),
        'payment_gateway.payoneer-checkout.payment_processor' => new Factory(['inpsyde_payoneer_api.update_command_factory', 'list_session.manager', 'payment_methods.order.transaction_id_field_name', 'checkout.payment_flow_override_flag', 'embedded_payment.misconfiguration_detector', 'checkout.security_token_generator', 'checkout.order.security_header_field_name', 'checkout.session_hash_key'], static function (WcOrderBasedUpdateCommandFactoryInterface $updateCommandFactory, ListSessionManager $listSessionManager, string $transactionIdFieldName, string $hostedModeOverrideFlag, MisconfigurationDetectorInterface $misconfigurationDetector, TokenGeneratorInterface $tokenGenerator, string $tokenKey, string $sessionHashKey): PaymentProcessorInterface {
            return new EmbeddedPaymentProcessor($updateCommandFactory, $listSessionManager, $listSessionManager, $tokenGenerator, $tokenKey, $transactionIdFieldName, $hostedModeOverrideFlag, $misconfigurationDetector, $sessionHashKey);
        }),
        'payment_gateway.payoneer-afterpay.payment_processor' => new Alias('payment_gateway.payoneer-checkout.payment_processor'),
        'payment_gateway.payoneer-checkout.refund_processor' => new Constructor(RefundProcessor::class, ['inpsyde_payment_gateway.payoneer', 'list_session.manager', 'inpsyde_payment_gateway.payment_factory', 'inpsyde_payment_gateway.charge_id_field_name', 'payment_methods.payout_id_field_name', 'payment_methods.refund_reason_suffix_template', 'payment_gateways']),
        'payment_gateway.payoneer-hosted.refund_processor' => new Alias('payment_gateway.payoneer-checkout.refund_processor'),
        'payment_gateway.payoneer-afterpay.refund_processor' => new Alias('payment_gateway.payoneer-checkout.refund_processor'),
        'payment_methods.refund_reason_suffix_template' => static function (): string {
            return \__('Refunded by Payoneer Checkout - long ID: %1$s', 'payoneer-checkout');
        },
        'payment_gateway.payoneer-checkout.form_fields' => new Alias('payoneer_settings.settings_fields'),
        'payment_gateway.payoneer-hosted.form_fields' => new Alias('payment_gateway.payoneer-checkout.form_fields'),
        'payment_gateway.payoneer-afterpay.form_fields' => new Alias('payment_gateway.payoneer-checkout.form_fields'),
        'payment_gateway.payoneer-checkout.payment_request_validator' => static function (): PaymentRequestValidatorInterface {
            return new class implements PaymentRequestValidatorInterface
            {
                public function assertIsValid(\WC_Order $order, PaymentGateway $param): void
                {
                    //We have nothing to validate here so far.
                }
            };
        },
        'payment_gateway.payoneer-hosted.payment_request_validator' => new Alias('payment_gateway.payoneer-checkout.payment_request_validator'),
        'payment_gateway.payoneer-afterpay.payment_request_validator' => new Alias('payment_gateway.payoneer-checkout.payment_request_validator'),
        'payment_gateway.payoneer-checkout.gateway_icons_renderer' => static function (ContainerInterface $container): GatewayIconsRendererInterface {
            $iconElements = $container->get('checkout.gateway_icon_elements_cards.enabled');
            /** @var string[] $iconElements */
            return new GatewayIconsRenderer($iconElements);
        },
        'payment_gateway.payoneer-hosted.gateway_icons_renderer' => new Alias('payment_gateway.payoneer-checkout.gateway_icons_renderer'),
        'payment_gateway.payoneer-afterpay.gateway_icons_renderer' => static function (ContainerInterface $container): GatewayIconsRendererInterface {
            $iconElements = $container->get('checkout.afterpay_icon_enabled') ? $container->get('checkout.gateway_icon_elements_afterpay') : [];
            /** @var string[] $iconElements */
            return new GatewayIconsRenderer($iconElements);
        },
        'payment_methods.payoneer-checkout.list_url_container_id' => new Value('payoneer-list-url'),
        'payment_methods.payoneer-checkout.payment_fields_component' => new Value('cards'),
        /**
         * Provide the default implementation for checkout fields. A renderer
         * that prints a list of sub-renderers that can be dynamically extended according
         * to the chosen payment flow
         */
        'payment_gateway.payoneer-checkout.payment_fields_renderers' => new Value([]),
        'payment_gateway.payoneer-checkout.payment_fields_renderer' => new Factory(['payment_gateway.payoneer-checkout.payment_fields_renderers'], static function (array $renderers): CompoundPaymentFieldsRenderer {
            /**
             * @var PaymentFieldsRendererInterface[] $renderers
             */
            return new CompoundPaymentFieldsRenderer(...$renderers);
        }),
        'payment_methods.payoneer-afterpay.payment_fields_component' => fn() => 'afterpay',
        /**
         * Provide the default implementation for checkout fields. A renderer
         * that prints a list of sub-renderers that can be dynamically extended according
         * to the chosen payment flow
         */
        'payment_gateway.payoneer-afterpay.payment_fields_renderers' => new Value([]),
        'payment_gateway.payoneer-afterpay.payment_fields_renderer' => new Factory(['payment_gateway.payoneer-afterpay.payment_fields_renderers'], static function (array $renderers): CompoundPaymentFieldsRenderer {
            /**
             * @var PaymentFieldsRendererInterface[] $renderers
             */
            return new CompoundPaymentFieldsRenderer(...$renderers);
        }),
        'payment_gateway.payoneer-checkout.has_fields' => '__return_true',
        'payment_gateway.payoneer-checkout.option_key' => new Value('woocommerce_payoneer-checkout_settings'),
        'payment_gateway.payoneer-hosted.option_key' => new Alias('payment_gateway.payoneer-checkout.option_key'),
        'payment_gateway.payoneer-afterpay.option_key' => new Alias('payment_gateway.payoneer-checkout.option_key'),
        'payment_methods.exclude_not_supported_countries' => new Constructor(ExcludeNotSupportedCountries::class, ['payment_methods.not_supported_countries']),
        'payment_methods.is_live_mode' => new Factory(['inpsyde_payment_gateway.options'], static function (ContainerInterface $options): bool {
            $optionValue = $options->get('live_mode');
            $optionValue = $optionValue !== 'no';
            return $optionValue;
        }),
        'payment_methods.payoneer-checkout.is_enabled' => new Factory(['inpsyde_payment_gateway.options', 'core.payment_gateway.is_enabled'], static function (ContainerInterface $options, bool $payoneerPaymentMethodsEnabled): bool {
            if (!$payoneerPaymentMethodsEnabled) {
                return \false;
            }
            return $options->get('payment_flow') === 'embedded';
        }),
        'payment_methods.payoneer-hosted.is_enabled' => new Factory(['inpsyde_payment_gateway.options', 'core.payment_gateway.is_enabled'], static function (ContainerInterface $options, bool $payoneerPaymentMethodsEnabled): bool {
            if (!$payoneerPaymentMethodsEnabled) {
                return \false;
            }
            return $options->get('payment_flow') === 'hosted';
        }),
        'payment_methods.payoneer-afterpay.is_enabled' => new Alias('payment_methods.payoneer-checkout.is_enabled'),
        'payment_methods.show_payment_widget_to_customers_in_sandbox_mode' => '__return_false',
    ];
};
