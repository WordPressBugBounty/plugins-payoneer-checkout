<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Checkout;

use Dhii\Services\Factories\Alias;
use Dhii\Services\Factories\Constructor;
use Dhii\Services\Factories\StringService;
use Dhii\Services\Factories\Value;
use Dhii\Services\Factory;
use Dhii\Services\Service;
use Inpsyde\PayoneerForWoocommerce\Checkout\Authentication\TokenGenerator;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\Customer\WcBasedCustomerFactory;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\Customer\WcBasedCustomerFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\ListSession\OrderBasedListCommandFactory;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\ListSession\OrderBasedListSessionFactory;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\ListSession\OrderBasedListSessionFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\ListSession\WcBasedListSessionFactory;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\Product\QuantityNormalizer;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\Product\QuantityNormalizerInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\Product\WcBasedProductFactory;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\Product\WcBasedProductFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\Product\WcCartBasedProductListFactory;
use Inpsyde\PayoneerForWoocommerce\Checkout\HashProvider\CheckoutHashProvider;
use Inpsyde\PayoneerForWoocommerce\Checkout\GatewayIconsRenderer\GatewayIconsRenderer;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\CachingListSessionManager;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\CascadingListSessionManager;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\CheckoutListSessionCreator;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\ListSessionPersistor;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\ListSessionProvider;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\OrderAwareListSessionPersistor;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\OrderAwareListSessionProvider;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\OrderPayListSessionCreator;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\WcOrderListSessionPersistor;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\WcOrderListSessionProvider;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\WcOrderListSessionRemover;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\WcSessionListSessionManager;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\WcTransientListSessionManager;
use Inpsyde\PayoneerForWoocommerce\Checkout\MisconfigurationDetector\MisconfigurationDetector;
use Inpsyde\PayoneerForWoocommerce\Checkout\PaymentFieldsRenderer\CompoundPaymentFieldsRenderer;
use Inpsyde\PayoneerForWoocommerce\Checkout\StateProvider\StateProvider;
use Inpsyde\PayoneerForWoocommerce\Checkout\TransactionIdGenerator\TransactionIdGenerator;
use Inpsyde\PayoneerForWoocommerce\Checkout\TransactionIdGenerator\TransactionIdGeneratorInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\Factory\SecurityHeader\SecurityHeaderFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\GatewayIconsRenderer\GatewayIconsRendererInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentFieldsRenderer\PaymentFieldsRendererInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\WcProductSerializer\WcProductSerializerInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Callback\CallbackFactoryInterface;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListDeserializerInterface;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListSerializerInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Payment\PaymentFactoryInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Product\ProductFactoryInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Style\StyleFactoryInterface;
use Inpsyde\PayoneerSdk\Api\Entities\System\System;
use Inpsyde\PayoneerSdk\Api\Entities\System\SystemInterface;
use Inpsyde\PayoneerSdk\Api\PayoneerInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\UriInterface;
use WC_Cart;
use WC_Customer;
use WC_Session;

return
    /**
     * @return array<string, callable>
     * @psalm-return array<string, callable>
     */
    static function (): array {
        return [
            'checkout.module_root_path' => static function (): string {
                return dirname(__DIR__);
            },

            'checkout.module_directory_name' => static function (): string {
                return 'checkout';
            },

            'checkout.ajax_library_path' => static function (): string {
                return '';
            },

            'checkout.ajax_library_url' => static function (): string {
                return '';
            },

            'checkout.list_session_remover.wc_order' => new Factory([
                'checkout.order_list_session_field_name',
            ], static function (
                string $listSessionFieldName
            ) {
                return new WcOrderListSessionRemover($listSessionFieldName);
            }),
            'checkout.list_session_persistor.wc_order' => new Factory([
                'checkout.order_list_session_field_name',
                'core.list_serializer',
            ], static function (
                string $listSessionFieldName,
                ListSerializerInterface $listSerializer
            ): ListSessionPersistor {
                return new WcOrderListSessionPersistor(
                    $listSessionFieldName,
                    $listSerializer
                );
            }),
            'checkout.list_session_provider.wc_order' => new Factory([
                'checkout.order_list_session_field_name',
                'core.list_deserializer',
            ], static function (
                string $listSessionFieldName,
                ListDeserializerInterface $listDeserializer
            ): OrderAwareListSessionProvider {
                return new WcOrderListSessionProvider(
                    $listSessionFieldName,
                    $listDeserializer
                );
            }),

            'checkout.list_session_manager' => new Factory([
                'wc.order_under_payment',
                'checkout.list_session_provider.wc_order',
                'checkout.list_session_persistor.wc_order',
                'checkout.list_session_manager.wc_session',
                'checkout.list_session_provider.api',
            ], static function (
                int $orderId,
                OrderAwareListSessionProvider $orderAwareListSessionProvider,
                OrderAwareListSessionPersistor $orderAwareListSessionPersistor,
                ListSessionProvider $wcSessionListSessionProvider,
                ListSessionProvider $checkoutApiProvider
            ): ListSessionProvider {
                $providers = [];
                $wcOrder = wc_get_order($orderId);
                if ($wcOrder instanceof \WC_Order) {
                    /**
                     * There's a chance that payment is attempted multiple times.
                     * If we redirect to cancelUrl because of a failed CHARGE, this would result
                     * in a new LIST despite the previously persisted one still being valid.
                     * So we let this take precedence over other means of providing the LIST
                     */
                    $providers[] = $orderAwareListSessionProvider->withOrder($wcOrder);
                    $providers[] = $orderAwareListSessionPersistor->withOrder($wcOrder);
                }
                $providers[] = $wcSessionListSessionProvider;
                $providers[] = $checkoutApiProvider;
                /**
                 * Put it all together in a composite provider
                 */
                $compositeProvider = new CascadingListSessionManager(
                    $providers
                );

                /**
                 * Add a caching layer around the whole thing to prevent multiple API calls
                 */
                return new CachingListSessionManager($compositeProvider);
            }),

            'checkout.list_session_manager.cache_key.salt.option_name' =>
                new Factory([
                    'checkout.plugin.version_string',
                ], static function (string $pluginVersion): string {
                    $versionHash = md5($pluginVersion);
                    $versionShortHash = (string) substr($versionHash, 0, 8);

                    return sprintf('payoneer_list_session_cache_key_salt_%1$s', $versionShortHash);
                }),

            'checkout.list_session_manager.cache_key.salt' =>
                new Factory(
                    [
                    'checkout.list_session_manager.cache_key.salt.option_name',
                    ],
                    static function (string $optionName): string {
                        $salt = (string) get_option($optionName);

                        if (! $salt) {
                            $salt = wp_generate_password();
                            update_option($optionName, $salt);
                        }

                        return $salt;
                    }
                ),

            'checkout.list_session_manager.cache_key.checkout' => new StringService(
                'payoneer_list_checkout_{0}',
                ['checkout.list_session_manager.cache_key.salt']
            ),
            'checkout.list_session_manager.cache_key.payment' => new StringService(
                'payoneer_list_pay_{0}_{1}',
                [
                    'wc.pay_for_order_id',
                    'checkout.list_session_manager.cache_key.salt',
                ]
            ),
            'checkout.list_session_manager.cache_key' => static function (
                ContainerInterface $container
            ): string {
                $isPayment = (bool)$container->get('wc.is_checkout_pay_page');

                $key = $isPayment
                    ? $container->get('checkout.list_session_manager.cache_key.payment')
                    : $container->get('checkout.list_session_manager.cache_key.checkout');

                return (string)$key;
            },
            'checkout.list_session_manager.transient' => new Factory([
                'checkout.list_session_manager.cache_key',
                'core.list_serializer',
                'core.list_deserializer',
            ], static function (
                string $cacheKey,
                ListSerializerInterface $listSerializer,
                ListDeserializerInterface $listDeserializer
            ): ListSessionProvider {
                return new WcTransientListSessionManager(
                    $cacheKey,
                    $listSerializer,
                    $listDeserializer
                );
            }),
            'checkout.list_session_manager.wc_session' => new Factory([
                'wc.session',
                'checkout.list_session_manager.cache_key',
                'core.list_serializer',
                'core.list_deserializer',
            ], static function (
                WC_Session $wcSession,
                string $listSessionKey,
                ListSerializerInterface $listSerializer,
                ListDeserializerInterface $listDeserializer
            ): ListSessionProvider {
                return new WcSessionListSessionManager(
                    $wcSession,
                    $listSessionKey,
                    $listSerializer,
                    $listDeserializer
                );
            }),
            'checkout.list_session_factory' => new Factory(
                [
                    'core.payoneer',
                    'checkout.security_header_factory',
                    'core.callback_factory',
                    'core.style_factory',
                    'core.payment_factory',
                    'checkout.wc_based_customer_factory',
                    'checkout.wc_cart_based_product_list_factory',
                    'checkout.notification_url',
                    'wp.current_locale.normalized',
                    'wc.currency',
                    'checkout.list_session_system',
                    'checkout.transaction_id_generator',
                    'inpsyde_payment_gateway.merchant_division',
                ],
                static function (
                    PayoneerInterface $payoneer,
                    SecurityHeaderFactoryInterface $securityHeaderFactory,
                    CallbackFactoryInterface $callbackFactory,
                    StyleFactoryInterface $styleFactory,
                    PaymentFactoryInterface $paymentFactory,
                    WcBasedCustomerFactoryInterface $wcBasedCustomerFactory,
                    WcCartBasedProductListFactory $wcCartBasedProductListFactory,
                    UriInterface $notificationUrl,
                    string $checkoutLocale,
                    string $currency,
                    SystemInterface $system,
                    TransactionIdGeneratorInterface $transactionIdGenerator,
                    string $division
                ): WcBasedListSessionFactory {
                    return new WcBasedListSessionFactory(
                        $payoneer,
                        $securityHeaderFactory,
                        $callbackFactory,
                        $paymentFactory,
                        $styleFactory,
                        $wcBasedCustomerFactory,
                        $wcCartBasedProductListFactory,
                        $notificationUrl,
                        $checkoutLocale,
                        $currency,
                        $system,
                        $transactionIdGenerator,
                        $division
                    );
                }
            ),

            'checkout.order_based_list_command_factory' =>
                new Constructor(OrderBasedListCommandFactory::class, [
                    'checkout.payoneer',
                    'checkout.transaction_id_generator',
                    'checkout.wc_order_based_callback_factory',
                    'checkout.wc_order_based_customer_factory',
                    'checkout.wc_order_based_payment_factory',
                    'checkout.style_factory',
                    'checkout.wc_order_based_products_factory',
                'checkout.list_session_system',
                    'wp.current_locale.normalized',
                    'checkout.merchant_division',
                ]),

            'checkout.order_based_list_session_factory' => new Constructor(
                OrderBasedListSessionFactory::class,
                [
                    'checkout.order_based_list_command_factory',
                ]
            ),
            'checkout.list_session_system' => new Factory(
                [
                    'checkout.plugin.version_string',
                ],
                static function (string $version): SystemInterface {
                    return new System(
                        'SHOP_PLATFORM',
                        'WOOCOMMERCE',
                        $version
                    );
                }
            ),
            'checkout.list_session_persistor' => new Alias('checkout.list_session_provider'),
            'checkout.list_session_remover' => new Alias('checkout.list_session_provider'),
            'checkout.list_session_provider' => new Alias('checkout.list_session_manager'),

            'checkout.list_session_provider.api' => new Factory([
                'checkout.list_session_factory',
                'checkout.order_based_list_session_factory',
                'wc.customer',
                'wc.cart',
                'checkout.security_token',
                'wc.is_checkout_pay_page',
                'wc.pay_for_order_id',
            ], static function (
                WcBasedListSessionFactory $listSessionFactory,
                OrderBasedListSessionFactoryInterface $orderBasedListSessionFactory,
                WC_Customer $customer,
                WC_Cart $cart,
                string $listSecurityToken,
                bool $isOrderPay,
                int $payForOrderId
            ): ListSessionProvider {
                if ($isOrderPay) {
                    return new OrderPayListSessionCreator(
                        $orderBasedListSessionFactory,
                        $listSecurityToken,
                        $payForOrderId
                    );
                }

                return new CheckoutListSessionCreator(
                    $listSessionFactory,
                    $customer,
                    $cart,
                    $listSecurityToken
                );
            }),

            'checkout.security_token' => new Factory([
                'wc.session',
                'checkout.order.security_header_field_name',
            ], static function (
                WC_Session $wcSession,
                string $tokenKey
            ): string {
                $token = $wcSession->get($tokenKey);
                if (! is_string($token)) {
                    throw new \RuntimeException(
                        sprintf(
                            "Invalid value for WC_Session key %s. Expected string, got %s",
                            $tokenKey,
                            print_r($token, true)
                        )
                    );
                }

                return $token;
            }),

            'checkout.js_extension' => static function (): string {
                //todo: use min.js when script debug is enabled
                return '.js';
            },

            'checkout.ajax_library_filename' => static function (
                ContainerInterface $container
            ): string {
                $jsExtension = (string)$container->get('checkout.js_extension');

                return sprintf('op-payment-widget%1$s', $jsExtension);
            },

            'checkout.checkout_script_filename' => static function (
                ContainerInterface $container
            ): string {
                $jsExtension = (string)$container->get('checkout.js_extension');

                return sprintf('payoneer-checkout%1$s', $jsExtension);
            },

            'checkout.success_url' => new Value(''),

            'checkout.failure_url' => new Value(''),

            'checkout.script_debug' => new Value(false),

            'checkout.css_assets_relative_path' => new Factory(
                [
                    'core.local_modules_directory_name',
                    'checkout.module_directory_name',
                ],
                static function (
                    string $modulesDirectoryRelativePath,
                    string $moduleDirectoryName
                ): string {
                    $moduleRelativePath = sprintf(
                        '%1$s/%2$s',
                        $modulesDirectoryRelativePath,
                        $moduleDirectoryName
                    );

                    return sprintf('%1$s/assets/css', $moduleRelativePath);
                }
            ),

            'checkout.list_url_container_attribute_name' => static function (): string {
                return 'data-payoneer-list-url';
            },

            'checkout.session_hash_key' => new Value('_payoneer_checkout_hash'),

            'checkout.list_hash_container_id' => new Value('data-payoneer-list-hash'),

            'checkout.module_name' => new Value('checkout'),

            'checkout.templates_dir_virtual_path' => new StringService(
                '{module_name}/resources/templates',
                ['module_name' => 'checkout.module_name']
            ),

            'checkout.module_path' => new Value(dirname(__FILE__, 2)),

            'checkout.templates_dir_local_path' => new StringService(
                '{module_path}/resources/templates',
                ['module_path' => 'checkout.module_path']
            ),

            'checkout.security_token_generator' => new Constructor(
                TokenGenerator::class
            ),

            'checkout.transaction_id_generator' =>
                new Constructor(TransactionIdGenerator::class),

            'checkout.wc_based_customer_factory' => new Constructor(
                WcBasedCustomerFactory::class,
                [
                    'core.customer_factory',
                    'core.phone_factory',
                    'core.address_factory',
                    'core.name_factory',
                    'core.registration_factory',
                    'checkout.customer_registration_id_field_name',
                    'checkout.state_provider',
                ]
            ),

            'checkout.quantity_normalizer' => new Constructor(
                QuantityNormalizer::class
            ),

            'checkout.wc_based_product_factory' => static function (
                ContainerInterface $container
            ): WcBasedProductFactoryInterface {
                /** @var WcProductSerializerInterface $wcProductSerializer */
                $wcProductSerializer = $container->get('core.wc_product_serializer');
                /** @var ProductFactoryInterface $productFactory */
                $productFactory = $container->get('core.product_factory');
                /** @var string $currency */
                $currency = $container->get('core.store_currency');
                /** @var QuantityNormalizerInterface $quantityNormalizer */
                $quantityNormalizer = $container->get('checkout.quantity_normalizer');

                return new WcBasedProductFactory(
                    $wcProductSerializer,
                    $productFactory,
                    $quantityNormalizer,
                    $currency
                );
            },

            'checkout.wc_cart_based_product_list_factory' =>
                new Constructor(
                    WcCartBasedProductListFactory::class,
                    [
                        'checkout.wc_based_product_factory',
                        'checkout.product_factory',
                        'checkout.store_currency',
                    ]
                ),

            'checkout.checkout_hash_provider' =>
                new Constructor(CheckoutHashProvider::class, ['wc']),

            'checkout.misconfiguration_detector' =>
                new Constructor(MisconfigurationDetector::class),

            'checkout.gateway_icon_elements_filenames' =>
                new Factory(
                    [
                        'checkout.amex_icon_enabled',
                    ],
                    static function (bool $amexEnabled): array {
                        $icons = [
                            'mastercard.svg',
                            'visa.svg',
                        ];

                        if ($amexEnabled) {
                            $icons = array_merge(['amex.svg'], $icons);
                        }

                        return $icons;
                    }
                ),

            'checkout.amex_settings_field' => Service::fromFile(
                __DIR__ . '/amex_icon_setting_field.php'
            ),

            'checkout.gateway_icon_elements' => static function (
                ContainerInterface $container
            ): array {
                $moduleRootDir = (string)$container->get('checkout.module_root_path');
                $iconElementsList = [];
                $basePath = "$moduleRootDir/assets/img";
                $imgFiles = $container->get('checkout.gateway_icon_elements_filenames');
                foreach ($imgFiles as $file) {
                    assert(is_string($file));
                    $imgUrl = plugins_url('img/' . $file, $basePath);

                    $iconElementsList[] = $imgUrl;
                }

                return $iconElementsList;
            },
            'checkout.gateway_icons_renderer' => static function (
                ContainerInterface $container
            ): GatewayIconsRendererInterface {

                $iconElements = $container->get('checkout.gateway_icon_elements');

                /** @var string[] $iconElements */
                return new GatewayIconsRenderer($iconElements);
            },
            'checkout.settings.general_settings_fields' => Service::fromFile(
                __DIR__ . "/general_settings_fields.php"
            ),

            'checkout.settings.appearance_settings_fields' => Service::fromFile(
                __DIR__ . "/appearance_settings_fields.php"
            ),

            'checkout.on_error_refresh_fragment_flag' => new Value('payoneer_refresh_fragment_onError'),
            'checkout.is_on_error_refresh_fragment_flag' => new Factory([
                'checkout.on_error_refresh_fragment_flag',
            ], static function (
                string $onErrorFlag
            ): bool {
                /**
                 * We can force refresh if a special flag is added
                 */
                $postData = [];
                $data = filter_input(INPUT_POST, 'post_data') ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                assert(is_string($data));
                parse_str($data, $postData);
                if (isset($postData[$onErrorFlag]) && $postData[$onErrorFlag] === 'true') {
                    return true;
                }

                return false;
            }),
            'checkout.flow_options' => new Value([]),
            'checkout.flow_options_description' => static function (): string {
                return __(
                    'Select the payment flow for every transaction.',
                    'payoneer-checkout'
                );
            },
            'checkout.payment_flow_override_flag' => new Value('payoneer_force_hosted_flow'),
            'checkout.selected_payment_flow' => new Factory([
                'inpsyde_payment_gateway.options',
                'checkout.payment_flow_override_flag',
            ], static function (
                ContainerInterface $options,
                string $overrideFlag
            ): string {
                try {
                    /**
                     * We can force usage of hosted flow if a special flag is added either
                     * in POST or GET request parameters
                     */
                    if (
                        filter_input(INPUT_GET, $overrideFlag, (int)FILTER_VALIDATE_BOOL)
                        || filter_input(INPUT_POST, $overrideFlag, (int)FILTER_VALIDATE_BOOL)
                    ) {
                        return 'hosted';
                    }

                    return (string)$options->get('payment_flow');
                } catch (\Throwable $exc) {
                    return "embedded"; // default
                }
            }),
            /**
             * Provide the default implementation for checkout fields. A renderer
             * that prints a list of sub-renderers that can be dynamically extended according
             * to the chosen payment flow
             *
             */
            'inpsyde_payment_gateway.payment_fields_renderer' => new Factory(
                [
                    'checkout.payment_field_renderers',
                ],
                static function (array $renderers): CompoundPaymentFieldsRenderer {
                    /**
                     * @var PaymentFieldsRendererInterface[] $renderers
                     */
                    return new CompoundPaymentFieldsRenderer(...$renderers);
                }
            ),
            'checkout.payment_field_renderers' => new Value([]),
            'checkout.http_request_timeout' => new Value(70),
            'checkout.notification_received' =>
                new Factory([
                    'checkout.notification_received.option_name',
                ], static function (string $optionName): bool {
                    return get_option($optionName) === 'yes';
                }),
            'checkout.notification_received.option_name' =>
                new Value('payoneer-checkout-notification-received'),
            'checkout.state_provider' => new Constructor(
                StateProvider::class,
                [
                    'checkout.wc.countries',
                ]
            ),
            'inpsyde_payment_gateway.is_live_mode' =>
                new Factory([
                    'inpsyde_payment_gateway.options',
                ], static function (ContainerInterface $options): bool {
                    $optionValue = $options->get('live_mode');
                    $optionValue = $optionValue !== 'no';

                    return $optionValue;
                }),
            'checkout.amex_icon_enabled' =>
                new Factory([
                    'checkout.payment_gateway_options',
                ], static function (ContainerInterface $options): bool {
                    if (! $options->has('show_amex_icon')) {
                        return true; //Show icon by default even if options wasn't saved yet.
                    }

                    $enabled = $options->get('show_amex_icon') !== 'no';

                    return $enabled;
                }),
        ];
    };
