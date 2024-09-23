<?php

declare (strict_types=1);
namespace Syde\Vendor;

use Syde\Vendor\Dhii\Services\Factories\Constructor;
use Syde\Vendor\Dhii\Services\Factories\ServiceList;
use Syde\Vendor\Dhii\Services\Factory;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\ProductTaxCodeProvider\ProductTaxCodeProviderInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\TransactionIdGenerator\TransactionIdGeneratorInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\Customer\WcBasedCustomerFactory;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\Customer\WcBasedCustomerFactoryInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\ListSession\OrderBasedListCommandFactory;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\ListSession\OrderBasedListSessionFactory;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\ListSession\WcBasedListSessionFactory;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\Product\QuantityNormalizer;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\Product\QuantityNormalizerInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\Product\WcBasedProductFactory;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\Product\WcBasedProductFactoryInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\Product\WcCartBasedProductListFactory;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ApiListSessionProvider;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionManager;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionManagerProxy;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\NoopListSessionPersistor;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Middleware\UpdatingMiddleware;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Middleware\WcOrderMiddleware;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Middleware\WcSessionMiddleware;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Api\Gateway\WcProductSerializer\WcProductSerializerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Callback\CallbackFactoryInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Payment\PaymentFactoryInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Product\ProductFactoryInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Style\StyleFactoryInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\System\SystemInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\PayoneerIntegrationTypes;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\PayoneerInterface;
use Syde\Vendor\Psr\Container\ContainerInterface;
use Syde\Vendor\Psr\Http\Message\UriInterface;
return static function (): array {
    return [
        'list_session.wc_based_customer_factory' => new Constructor(WcBasedCustomerFactory::class, ['core.customer_factory', 'core.phone_factory', 'core.address_factory', 'core.name_factory', 'core.registration_factory', 'checkout.customer_registration_id_field_name', 'checkout.state_provider', 'list_session.fallback_country']),
        'list_session.order_based_list_command_factory' => new Constructor(OrderBasedListCommandFactory::class, ['checkout.payoneer', 'checkout.transaction_id_generator', 'checkout.wc_order_based_callback_factory', 'checkout.wc_order_based_customer_factory', 'checkout.wc_order_based_payment_factory', 'checkout.style_factory', 'checkout.wc_order_based_products_factory', 'list_session.list_session_system', 'wp.current_locale.normalized', 'checkout.merchant_division']),
        'list_session.order_based_list_session_factory' => new Constructor(OrderBasedListSessionFactory::class, ['list_session.order_based_list_command_factory']),
        'list_session.list_session_factory' => new Factory(['core.payoneer', 'core.callback_factory', 'core.style_factory', 'core.payment_factory', 'list_session.wc_based_customer_factory', 'list_session.wc_cart_based_product_list_factory', 'checkout.notification_url', 'wp.current_locale.normalized', 'wc.currency', 'list_session.list_session_system', 'checkout.transaction_id_generator', 'payoneer_settings.merchant_division'], static function (PayoneerInterface $payoneer, CallbackFactoryInterface $callbackFactory, StyleFactoryInterface $styleFactory, PaymentFactoryInterface $paymentFactory, WcBasedCustomerFactoryInterface $wcBasedCustomerFactory, WcCartBasedProductListFactory $wcCartBasedProductListFactory, UriInterface $notificationUrl, string $checkoutLocale, string $currency, SystemInterface $system, TransactionIdGeneratorInterface $transactionIdGenerator, string $division): WcBasedListSessionFactory {
            return new WcBasedListSessionFactory($payoneer, $callbackFactory, $paymentFactory, $styleFactory, $wcBasedCustomerFactory, $wcCartBasedProductListFactory, $notificationUrl, $checkoutLocale, $currency, $system, $transactionIdGenerator, $division);
        }),
        'list_session.quantity_normalizer' => new Constructor(QuantityNormalizer::class),
        'list_session.wc_based_product_factory' => static function (ContainerInterface $container): WcBasedProductFactoryInterface {
            /** @var WcProductSerializerInterface $wcProductSerializer */
            $wcProductSerializer = $container->get('core.wc_product_serializer');
            /** @var ProductFactoryInterface $productFactory */
            $productFactory = $container->get('core.product_factory');
            /** @var string $currency */
            $currency = $container->get('core.store_currency');
            /** @var QuantityNormalizerInterface $quantityNormalizer */
            $quantityNormalizer = $container->get('list_session.quantity_normalizer');
            /** @var ProductTaxCodeProviderInterface $taxCodeProvider */
            $taxCodeProvider = $container->get('list_session.product_tax_code_provider');
            return new WcBasedProductFactory($wcProductSerializer, $productFactory, $quantityNormalizer, $currency, $taxCodeProvider);
        },
        'list_session.wc_cart_based_product_list_factory' => new Constructor(WcCartBasedProductListFactory::class, ['list_session.wc_based_product_factory', 'checkout.product_factory', 'checkout.store_currency']),
        'list_session.integration_type' => new Factory(['list_session.selected_payment_flow'], static function (string $selectedPaymentFlow): string {
            return $selectedPaymentFlow === 'hosted' ? PayoneerIntegrationTypes::HOSTED : PayoneerIntegrationTypes::EMBEDDED;
        }),
        'list_session.hosted_version' => static function (): string {
            return 'v4';
        },
        'list_session.default_persistor' => new Constructor(NoopListSessionPersistor::class, []),
        'list_session.creator' => new Constructor(ApiListSessionProvider::class, ['list_session.list_session_factory', 'list_session.order_based_list_session_factory', 'list_session.integration_type', 'list_session.hosted_version']),
        'list_session.middlewares.wc-order' => new Constructor(WcOrderMiddleware::class, ['checkout.order_list_session_field_name', 'core.list_serializer', 'core.list_deserializer']),
        'list_session.middlewares.wc-session' => new Constructor(WcSessionMiddleware::class, ['wc.session', 'checkout.list_session_manager.cache_key', 'core.list_serializer', 'core.list_deserializer']),
        'list_session.middlewares.wc-session-update' => new Constructor(UpdatingMiddleware::class, ['list_session.manager.proxy', 'list_session.list_session_factory', 'checkout.checkout_hash_provider', 'checkout.session_hash_key', 'core.order_based_update_command_factory']),
        'list_session.middlewares' => new ServiceList(['list_session.middlewares.wc-order', 'list_session.creator', 'list_session.default_persistor']),
        'list_session.manager' => new Constructor(ListSessionManager::class, ['list_session.middlewares']),
        /**
         * An unpleasant helper to break a recursive dependency chain.
         *
         */
        'list_session.manager.proxy' => static function (ContainerInterface $container): ListSessionManagerProxy {
            /**
             * @return ListSessionManager
             * @var callable():ListSessionManager $factory
             */
            $factory = static function () use ($container): ListSessionManager {
                /**
                 * @var ListSessionManager $manager
                 */
                $manager = $container->get('list_session.manager');
                return $manager;
            };
            return new ListSessionManagerProxy($factory);
        },
    ];
};
