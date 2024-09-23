<?php

declare(strict_types=1);

use Dhii\Services\Factories\Alias;
use Dhii\Services\Factories\Constructor;
use Dhii\Services\Factories\FuncService;
use Dhii\Services\Factories\StringService;
use Dhii\Services\Factories\Value;
use Dhii\Services\Factory;
use Inpsyde\Assets\Script;
use Inpsyde\Assets\Style;
use Inpsyde\PayoneerForWoocommerce\AssetCustomizer\AssetProcessorInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\HashProvider\HashProviderInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\ListSessionProvider;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\OrderAwareListSessionPersistor;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\OrderAwareListSessionProvider;
use Inpsyde\PayoneerForWoocommerce\Checkout\MisconfigurationDetector\MisconfigurationDetectorInterface;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\AjaxOrderPay\AjaxPayAction;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\AjaxOrderPay\OrderPayload;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer\CheckoutHashFieldRenderer;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer\ListDebugFieldRenderer;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer\ListUrlFieldRenderer;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer\WidgetPlaceholderFieldRenderer;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\Settings\CssField;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentProcessor\EmbeddedPaymentProcessor;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\CommandFactory\WcOrderBasedUpdateCommandFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentFieldsRenderer\PaymentFieldsRendererInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentGateway;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\PaymentProcessor\PaymentProcessorInterface;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListSerializerInterface;
use Psr\Container\ContainerInterface;

return static function (): array {
    return [
        'embedded_payment.is_enabled' => new Factory([
            'checkout.selected_payment_flow',
        ], static function (
            string $configuredFlow
        ): bool {
            return $configuredFlow === 'embedded';
        }),
        'embedded_payment.settings.fields' => static function (ContainerInterface $container) {
            return (require __DIR__ . "/custom_css_fields.php")($container);
        },

        'embedded_payment.settings.checkout_css_custom_css.default' =>
            new Value(
                (string) file_get_contents(dirname(__DIR__) . '/assets/css/custom-css-default.css')
            ),

        'embedded_payment.widget.button_id' => new Value('payoneer_place_order'),

        'embedded_payment.widget.css_url' => new Factory([
            'embedded_payment.path.css',
            'core.main_plugin_file',
        ], static function (
            string $cssPath,
            string $pluginMainFile
        ): string {
            return plugins_url(
                $cssPath . 'widget.css',
                $pluginMainFile
            );
        }),

        'embedded_payment.widget.asset.template.options' => new Factory(
            [
                'checkout.payment_gateway',
                'embedded_payment.widget.asset.template.extra_options',
            ],
            static function (
                WC_Payment_Gateway $gateway,
                array $extraCssOptions
            ): array {
                $options = [
                    'checkout_css_custom_css' => $gateway->get_option(
                        'checkout_css_custom_css',
                        ''
                    ),
                ];

                return array_merge($options, $extraCssOptions);
            }
        ),

        //Any content provided as array element with an 'extra_css' key
        //will be applied to generated CSS unconditionally.
        'embedded_payment.widget.asset.template.extra_options' => new Value([
            'extra_css' => '',
        ]),

        'embedded_payment.widget.custom_css_url' => new Factory([
            'embedded_payment.widget.asset.processor',
            'embedded_payment.widget.asset.template.location',
            'embedded_payment.widget.asset.template.options',
        ], static function (
            AssetProcessorInterface $assetProcessor,
            string $cssPath,
            array $cssOptions
        ): string {
            /** @psalm-suppress InvalidArgument */
            /** @psalm-suppress MixedArgumentTypeCoercion */
            return (string)$assetProcessor->process($cssPath, $cssOptions);
        }),
        'embedded_payment.widget_script_data' => new Factory([
            'core.list_url_container_id',
            'checkout.list_url_container_attribute_name',
            'core.payment_fields_container_id',
            'embedded_payment.assets.widget.css_url',
            'embedded_payment.widget.custom_css_url',
            'embedded_payment.widget.button_id',
            'checkout.payment_flow_override_flag',
            'checkout.on_error_refresh_fragment_flag',
            'wc.order_under_payment',
            'embedded_payment.pay_order_error_flag',
        ], static function (
            string $listUrlContainerId,
            string $listUrlContainerAttributeName,
            string $paymentFieldsContainerId,
            string $widgetCssUrl,
            string $customCssUrl,
            string $buttonId,
            string $hostedModeOverrideFlag,
            string $onErrorRefreshFragmentFlag,
            int $payForOrderId,
            string $payOrderErrorFlag
        ): array {
            return [
                'listUrlContainerId' => $listUrlContainerId,
                'listUrlContainerAttributeName' => $listUrlContainerAttributeName,
                'paymentFieldsContainerId' => $paymentFieldsContainerId,
                'payButtonId' => $buttonId,
                'widgetCssUrl' => $widgetCssUrl,
                'developmentMode' => false,
                'isPayForOrder' => is_wc_endpoint_url('order-pay'),
                'cssUrl' => $customCssUrl,
                'hostedModeOverrideFlag' => $hostedModeOverrideFlag,
                'onErrorRefreshFragmentFlag' => $onErrorRefreshFragmentFlag,
                'payForOrderId' => $payForOrderId,
                'payOrderErrorFlag' => $payOrderErrorFlag,
            ];
        }),

        'embedded_payment.pay_order_error_flag' =>
            new Value('payoneer-checkout-on-before-server-error'),

        'embedded_payment.widget.asset.template.name' =>
            new Value('custom.css'),

        'embedded_payment.widget.asset.template.location' => new StringService(
            'checkout/resources/templates/{template_name}',
            ['template_name' => 'embedded_payment.widget.asset.template.name']
        ),

        'embedded_payment.widget.asset.processor' => new Alias('checkout.asset_processor'),

        'embedded_payment.path.assets' => new Factory(
            [
                'core.local_modules_directory_name',
            ],
            static function (
                string $modulesDirectoryRelativePath
            ): string {
                $moduleRelativePath = sprintf(
                    '%1$s/%2$s',
                    $modulesDirectoryRelativePath,
                    'embedded-payment'
                );

                return sprintf('%1$s/assets', $moduleRelativePath);
            }
        ),
        'embedded_payment.path.js' => new StringService(
            '{0}/js/',
            ['embedded_payment.path.assets']
        ),
        'embedded_payment.path.js.suffix' => new Factory([
            'core.is_debug',
        ], static function (
            bool $isDebug
        ): string {
            return $isDebug ? '.min.js' : '.js';
        }),
        'embedded_payment.path.css' => new StringService(
            '{0}/css/',
            ['embedded_payment.path.assets']
        ),
        'embedded_payment.path.css.suffix' => new Factory([
            'core.is_debug',
        ], static function (
            bool $isDebug
        ): string {
            return $isDebug ? '.min.css' : '.css';
        }),
        'embedded_payment.assets.can_enqueue' => new FuncService([
            'wc.is_checkout',
            'inpsyde_payment_gateway.is_enabled',
        ], static function (
            bool $isCheckout,
            bool $isGatewayEnabled
        ): bool {
            return $isCheckout and $isGatewayEnabled;
        }),
        'embedded_payment.assets.css.websdk' => new Factory([
            'embedded_payment.assets.css.websdk.url',
            'embedded_payment.assets.can_enqueue',
        ], static function (
            string $webSdkCssUrl,
            callable $canEnqueue
        ): Style {
            $style = new Style('op-payment-widget', $webSdkCssUrl);
            /** @psalm-var callable():bool $canEnqueue */
            $style->canEnqueue($canEnqueue);

            return $style;
        }),

        'embedded_payment.assets.js.websdk' => new Factory([
            'embedded_payment.assets.js.websdk.url',
            'embedded_payment.assets.can_enqueue',
        ], static function (
            string $webSdkJsUrl,
            callable $canEnqueue
        ): Script {

            $script = new Script('op-payment-widget', $webSdkJsUrl);
            /** @psalm-var callable():bool $canEnqueue */
            $script->canEnqueue($canEnqueue);

            return $script;
        }),

        'embedded_payment.assets.css.checkout' => new Factory([
            'core.main_plugin_file',
            'embedded_payment.path.css',
            'embedded_payment.assets.can_enqueue',
        ], static function (
            string $mainPluginFile,
            string $cssPath,
            callable $canEnqueue
        ): Style {
            $url = plugins_url(
                $cssPath . 'payoneer-checkout.css',
                $mainPluginFile
            );
            $style = new Style('payoneer-checkout', $url);
            /** @psalm-var callable():bool $canEnqueue */
            $style->canEnqueue($canEnqueue);

            return $style;
        }),
        'embedded_payment.assets.js.checkout' => new Factory([
            'core.main_plugin_file',
            'embedded_payment.path.js',
            'embedded_payment.widget_script_data',
            'embedded_payment.assets.can_enqueue',
        ], static function (
            string $mainPluginFile,
            string $jsPath,
            array $widgetScriptData,
            callable $canEnqueue
        ): Script {
            $url = plugins_url(
                $jsPath . 'payoneer-checkout.js',
                $mainPluginFile
            );
            $script = new Script('payoneer-checkout', $url);
            $script->withLocalize('PayoneerData', $widgetScriptData);
            /** @psalm-var callable():bool $canEnqueue */
            $script->canEnqueue($canEnqueue);

            return $script;
        }),
        'embedded_payment.assets' => new Factory(
            [
                'embedded_payment.assets.js.websdk',
                'embedded_payment.assets.css.websdk',
                'embedded_payment.assets.js.checkout',
                'embedded_payment.assets.css.checkout',
            ],
            static function (
                Script $webSdkJs,
                Style $webSdkCss,
                Script $checkoutJs,
                Style $checkoutCss
            ): array {
                return [$webSdkJs, $webSdkCss, $checkoutJs, $checkoutCss];
            }
        ),
        /**
         *
         * Checkout payment fields.
         * For embedded flow, these take care of rendering containers and configuration
         * for the interactive payment widget of the WebSDK
         *
         */
        'embedded_payment.payment_fields_renderer.placeholder' => new Constructor(
            WidgetPlaceholderFieldRenderer::class,
            ['inpsyde_payment_gateway.payment_fields_container_id']
        ),
        'embedded_payment.payment_fields_renderer.list_url' => new Factory([
            'checkout.list_session_provider',
            'inpsyde_payment_gateway.list_url_container_id',
            'core.list_url_container_attribute_name',
        ], static function (
            ListSessionProvider $listSessionProvider,
            string $containerId,
            string $attributeName
        ): PaymentFieldsRendererInterface {
            return new ListUrlFieldRenderer($listSessionProvider, $containerId, $attributeName);
        }),
        'embedded_payment.payment_fields_renderer.list_hash' => new Factory([
            'checkout.checkout_hash_provider',
            'inpsyde_payment_gateway.list_hash_container_id',
        ], static function (
            HashProviderInterface $hashProvider,
            string $containerId
        ): PaymentFieldsRendererInterface {
            return new CheckoutHashFieldRenderer($hashProvider, $containerId);
        }),
        'embedded_payment.payment_fields_renderer.debug' => new Factory([
            'checkout.list_session_provider',
            'core.list_serializer',
        ], static function (
            ListSessionProvider $listSessionProvider,
            ListSerializerInterface $serializer
        ): PaymentFieldsRendererInterface {
            return new ListDebugFieldRenderer($listSessionProvider, $serializer);
        }),
        'inpsyde_payment_gateway.payment_processor' => new Factory([
            'inpsyde_payment_gateway.gateway',
            'inpsyde_payment_gateway.update_command_factory',
            'checkout.list_session_manager',
            'checkout.list_session_persistor.wc_order',
            'inpsyde_payment_gateway.charge_id_field_name',
            'inpsyde_payment_gateway.transaction_id_field_name',
            'checkout.payment_flow_override_flag',
            'embedded_payment.misconfiguration_detector',
        ], static function (
            PaymentGateway $paymentGateway,
            WcOrderBasedUpdateCommandFactoryInterface $updateCommandFactory,
            OrderAwareListSessionProvider $sessionProvider,
            OrderAwareListSessionPersistor $sessionPersistor,
            string $chargeIdFieldName,
            string $transactionIdFieldName,
            string $hostedModeOverrideFlag,
            MisconfigurationDetectorInterface $misconfigurationDetector
        ): PaymentProcessorInterface {
            return new EmbeddedPaymentProcessor(
                $paymentGateway,
                $updateCommandFactory,
                $sessionProvider,
                $sessionPersistor,
                $chargeIdFieldName,
                $transactionIdFieldName,
                $hostedModeOverrideFlag,
                $misconfigurationDetector
            );
        }),
        'embedded_payment.ajax_order_pay.is_ajax_order_pay' => static function (): bool {
            //phpcs:disable WordPress.Security.NonceVerification.Missing
            return wp_doing_ajax()
                   && isset($_POST['action'])
                   && $_POST['action'] === 'payoneer_order_pay';
        },
        'embedded_payment.ajax_order_pay.checkout_payload' => new Factory([
            'embedded_payment.ajax_order_pay.is_ajax_order_pay',
        ], static function (bool $isAjaxOrderPay): OrderPayload {
            if (! $isAjaxOrderPay) {
                throw new RuntimeException('Invalid Request');
            }
            return OrderPayload::fromGlobals();
        }),
        'embedded_payment.ajax_order_pay.payment_action' => new Factory([
            'inpsyde_payment_gateway.gateway',
        ], static function (WC_Payment_Gateway $paymentGateway): AjaxPayAction {
            return new AjaxPayAction($paymentGateway);
        }),
        /**
         * WC Settings API fields
         */
        'inpsyde_payment_gateway.settings_field_renderer.css' =>
            new Constructor(CssField::class),
    ];
};
