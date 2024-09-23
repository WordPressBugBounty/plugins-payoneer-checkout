<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Checkout;

use Dhii\Services\Factory;
use Inpsyde\PayoneerForWoocommerce\Checkout\Authentication\TokenGenerator;
use Inpsyde\PayoneerForWoocommerce\Checkout\PaymentProcessor\MetadataSavingProcessorDecorator;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentGateway;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\MerchantInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\PaymentProcessor\PaymentProcessorInterface;
use Inpsyde\PayoneerSdk\Client\ApiClient;
use Inpsyde\PayoneerSdk\Client\ApiClientInterface;
use Inpsyde\PayoneerSdk\Client\TokenAwareInterface;
use Inpsyde\Wp\HttpClient\Client as WpClient;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use WC_Session;
use WC_Session_Handler;

return
    /**
     * @return array<string, callable>
     * @psalm-return array<string, callable>
     */
    static function (): array {
        return [
            'core.path_resolver.mappings' => static function (
                array $previous,
                ContainerInterface $container
            ): array {
                /** @var string $sourcePath */
                $sourcePath = $container->get('checkout.templates_dir_virtual_path');
                /** @var string $destinationPath */
                $destinationPath = $container->get('checkout.templates_dir_local_path');

                return array_merge($previous, [$sourcePath => $destinationPath]);
            },

            'wc.session' => static function (
                WC_Session $session,
                ContainerInterface $container
            ): WC_Session {
                $tokenField = (string)$container->get('checkout.order.security_header_field_name');
                assert(
                    $session instanceof WC_Session_Handler
                    ||
                    /**
                     * Achieve CoCart compatibility without directly addressing CoCart
                     *
                     * @see https://github.com/co-cart/co-cart/issues/268#issuecomment-1269806425
                     */
                    count(
                        array_intersect([
                            'has_session',
                            'init_session_cookie',
                            'set_customer_session_cookie',
                        ], get_class_methods($session))
                    )
                );

                if (empty($session->get($tokenField))) {
                    $tokenGenerator = $container->get('checkout.security_token_generator');
                    assert($tokenGenerator instanceof TokenGenerator);
                    $session->set($tokenField, $tokenGenerator->generateToken());
                }

                return $session;
            },

            'checkout.settings.appearance_settings_fields' => static function (
                array $fields,
                ContainerInterface $container
            ): array {
                /** @var array<string, array-key> $amexField */
                $amexField = $container->get('checkout.amex_settings_field');

                return array_merge($fields, $amexField);
            },

            'inpsyde_payment_gateway.settings_fields' => static function (
                array $previous,
                ContainerInterface $container
            ): array {
                /** @var array<string, array-key> $generalSettingsFields */
                $generalSettingsFields = $container->get('checkout.settings.general_settings_fields');
                /** @var array<string, array-key> $appearanceSettingsFields */
                $appearanceSettingsFields = $container->get('checkout.settings.appearance_settings_fields');

                return array_merge(
                    $previous,
                    $generalSettingsFields,
                    $appearanceSettingsFields
                );
            },

            'inpsyde_payment_gateway.payment_processor' => static function (
                PaymentProcessorInterface $previous,
                ContainerInterface $container
            ): PaymentProcessorInterface {
                /**
                 * @var callable(ContainerInterface):PaymentProcessorInterface $factory
                 */
                $factory = new Factory(
                    [
                        'inpsyde_payment_gateway.gateway',
                        'inpsyde_payment_gateway.merchant',
                        'inpsyde_payment_gateway.merchant_id_field_name',
                        'inpsyde_payment_gateway.order.security_header_field_name',
                        'inpsyde_payment_gateway.list_security_token',
                    ],
                    static function (
                        PaymentGateway $paymentGateway,
                        MerchantInterface $merchant,
                        string $merchantIdFieldName,
                        string $securityHeaderFieldName,
                        string $securityToken
                    ) use ($previous): PaymentProcessorInterface {
                        return new MetadataSavingProcessorDecorator(
                            $previous,
                            $paymentGateway,
                            $merchant,
                            $merchantIdFieldName,
                            $securityHeaderFieldName,
                            $securityToken
                        );
                    }
                );

                return $factory($container);
            },
            'payoneer_sdk.http_client' => static function (
                ClientInterface $previous,
                ContainerInterface $container
            ): ClientInterface {
                /**
                 * Our decorator works by modifying WP hooks, so we only apply it if our
                 * own WP-based PSR-7 client is used for API calls
                 */
                if (!$previous instanceof WpClient) {
                    return $previous;
                }
                $timeout = (int)$container->get('checkout.http_request_timeout');

                return new TimeoutIncreasingApiClient($previous, $timeout);
            },

            /**
             * This is a temporary workaround to disable validation.
             * Should be removed in the future versions after OPG is updated.
             */
            'payoneer_sdk.api_client' => static function (
                ApiClientInterface $_previous,
                ContainerInterface $container
            ): ApiClientInterface {

                $httpClient = $container->get('payoneer_sdk.http_client');
                assert($httpClient instanceof ClientInterface);

                $requestFactory = $container->get('payoneer_sdk.request_factory');
                assert($requestFactory instanceof RequestFactoryInterface);

                $streamFactory = $container->get('payoneer_sdk.stream_factory');
                assert($streamFactory instanceof StreamFactoryInterface);

                $baseUrl = $container->get('payoneer_sdk.remote_api_url.base');
                assert($baseUrl instanceof UriInterface);

                $tokenProvider = $container->get('payoneer_sdk.token_provider');
                assert($tokenProvider instanceof TokenAwareInterface);

                return new class (
                    $httpClient,
                    $requestFactory,
                    $baseUrl,
                    $streamFactory,
                    $tokenProvider
                ) extends ApiClient implements ApiClientInterface {
                    protected function prepareBody(array $params): StreamInterface
                    {

                        /**
                         * We don't want to disable validation on the last request,
                         * when the payment processing start.
                         */
                        $processingStarted = did_action('woocommerce_checkout_process') ||
                                             did_action('woocommerce_before_pay_action');

                        if (! $processingStarted && isset($params['payment']['netAmount'])) {
                            $params['payment']['netAmount'] = 0.0;
                        }

                        return parent::prepareBody($params);
                    }
                };
            },
            'embedded_payment.settings.fields' => static function (
                array $fields,
                ContainerInterface $container
            ): array {
                /** @psalm-var array<string, array-key> $amexField */
                $amexField = $container->get('checkout.amex_settings_field');

                if (! array_key_exists('checkout_css_fieldset_title', $fields)) {
                    return array_merge($fields, $amexField);
                }

                $combinedFields = [];

                foreach ($fields as $fieldName => $fieldConfig) {
                    $combinedFields[$fieldName] = $fieldConfig;

                    if ($fieldName === 'checkout_css_fieldset_title') {
                        $combinedFields = array_merge($combinedFields, $amexField);
                    }
                }

                return $combinedFields;
            },
        ];
    };
