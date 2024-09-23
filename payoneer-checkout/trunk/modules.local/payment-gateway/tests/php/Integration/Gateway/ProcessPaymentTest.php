<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Tests\Integration\Gateway;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Exception;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentGateway;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentRequestValidatorInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\MerchantDeserializerInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\MerchantInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\MerchantQueryInterface;
use Inpsyde\PayoneerForWoocommerce\Webhooks\OrderFinder\OrderFinderInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Header\HeaderInterface;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use WC_Order;

/**
 *
 * @psalm-type MerchantData = array{
 *  id?: ?positive-int,
 *  label?: string,
 *  code?: string,
 *  division?: string,
 *  token?: string,
 *  base_url?: string,
 *  transaction_url_template?: string
 * }
 */
class ProcessPaymentTest extends PaymentTestCase
{
    public function setUp(): void
    {
        $mock = Mockery::mock(PaymentRequestValidatorInterface::class, [
            'assertIsValid' => true
        ]);
        /**
         * Since we're testing actual payment processing here, we expect all previous validation
         * to pass
         */
        $this->injectService(
            'inpsyde_payment_gateway.payment_request_validator',
            function () use ($mock) {
                return $mock;
            }
        );
        $this->skipExtensions('inpsyde_payment_gateway.payment_request_validator');
        parent::setUp();
    }
    public function testSuccessfulPayment()
    {
        $longId = uniqid('long-id-');
        $this->injectServiceRequestValidationOverride();

        $transactionId = uniqid('transaction-id');
        $this->injectMerchants();

        $this->preparePayment(
            'charged',
            'debited',
            $longId,
            $transactionId
        );

        $container = $this->createPackage()->container();
        $order     = $this->getOrder();

        $this->expectCommonOrderMeta($longId, $container, $order);

        $order->expects('payment_complete');
        $this->getWcSession()->allows('set');

        /** @var PaymentGateway $paymentGateway */
        $paymentGateway = $container->get('inpsyde_payment_gateway.gateway');
        $result         = $paymentGateway->process_payment($order->get_id());

        $this->assertSame('success', $result['result'], 'Payment processing has failed.');
    }

    public function testPendingPayment()
    {
        $longId = uniqid('long-id-');
        $transactionId = uniqid('transaction-id');

        $this->injectServiceRequestValidationOverride();
        $this->injectMerchants();

        $this->preparePayment(
            'pending',
            'debit_requested',
            $longId,
            $transactionId
        );

        $container = $this->createPackage()->container();
        $order     = $this->getOrder();

        $this->expectCommonOrderMeta($longId, $container, $order);
        $order->expects('update_status')->with('on-hold', \Mockery::type('string'));
        $this->getWcSession()->allows('set');

        $redirectUrl = 'https://shop.com';
        Functions\expect('add_query_arg')->once()->andReturn($redirectUrl);
        /** @var PaymentGateway $paymentGateway */
        $paymentGateway = $container->get('inpsyde_payment_gateway.gateway');
        $result         = $paymentGateway->process_payment($order->get_id());

        $this->assertSame('success', $result['result'], 'Payment processing was failed.');
        $this->assertSame($redirectUrl, $result['redirect'], 'Unexpected redirect URL.');
    }


    public function testFailedPayment()
    {
        $longId = uniqid('long-id-');
        $transactionId = uniqid('transaction-id');
        $this->injectServiceRequestValidationOverride();
        $this->injectMerchants();

        $this->preparePayment(
            'failed',
            'debit_failed',
            $longId,
            $transactionId
        );

        $container = $this->createPackage()->container();
        $order     = $this->getOrder();

        $this->expectCommonOrderMeta($longId, $container, $order);
        /**
         * Force-refresh of the frontend checkout fragments is triggered by this session flag
         */
        $this->getWcSession()->expects('set')->with('refresh_totals', true);

        /**
         * An appropriate notice should be displayed
         */
        Functions\expect('wc_add_notice')->once()->with(
            \Mockery::on(function ($notice) {
                return strpos($notice, 'Payment processing failed') !== false;
            }),
            'error'
        );
        /**
         * Appropriate action should be triggered
         */
        Actions\expectDone('payoneer-checkout.payment_processing_failure')->once();

        /** @var PaymentGateway $paymentGateway */
        $paymentGateway = $container->get('inpsyde_payment_gateway.gateway');
        $result         = $paymentGateway->process_payment($order->get_id());


        $this->assertSame('failure', $result['result'], 'Payment processing was failed.');
    }

    protected function injectMerchants()
    {
        $this->injectService(
            'inpsyde_payment_gateway.merchant.model',
            function (ContainerInterface $c): MerchantQueryInterface {
                $merchants     = $this->createMerchants($c);
                $merchantModel = $this->createMerchantModel($merchants);

                return $merchantModel;
            }
        );
    }
    /**
     * Currently, all paths through `process_payment` write the same meta data on the mocked Order
     * So this method is used to deal with their respective expectations across all tests
     *
     * @param string $longId
     * @param ContainerInterface $container
     * @param \WC_Order|MockInterface $order
     *
     * @return void
     */
    protected function expectCommonOrderMeta(
        string $longId,
        ContainerInterface $container,
        \WC_Order $order
    ) {
        $merchantIdFieldName  = $container->get('inpsyde_payment_gateway.merchant_id_field_name');
        $chargeIdFieldName = $container->get('inpsyde_payment_gateway.charge_id_field_name');
        $securityHeaderFieldName = $container->get('inpsyde_payment_gateway.order.security_header_field_name');
        $merchant = $container->get('inpsyde_payment_gateway.merchant');
        assert($merchant instanceof MerchantInterface);
        $merchantId = $merchant->getId();

        /**
         * Sandbox flag should be attached to the successful Order
         */
        $order->expects('update_meta_data')->once()->with($merchantIdFieldName, $merchantId);
        /**
         * Security token should be attached to the successful Order
         */
        $order->expects('update_meta_data')->once()->with(
            $securityHeaderFieldName,
            \Mockery::type('string')
        );
        /**
         * Transaction URL should be attached to the successful Order
         */
        $order->expects('update_meta_data')->once()->with(
            '_transaction_url_template',
            \Mockery::type('string')
        );

        /**
         * 'identification.longId' should be attached to the successful Order
         */
        $order->expects('update_meta_data')->once()->with($chargeIdFieldName, $longId);
    }

    /**
     * Nullifies all request validation
     *
     * @return void
     */
    protected function injectServiceRequestValidationOverride()
    {
        $this->injectExtension(
            'inpsyde_payment_gateway.payment_request_validator',
            static function () {
                return new class implements PaymentRequestValidatorInterface {
                    public function assertIsValid(
                        \WC_Order $wcOrder,
                        PaymentGateway $gateway
                    ): void {
                    }
                };
            }
        );
    }

    /**
     * Creates an order finder mock for the specified orders.
     *
     * @param array<string, WC_Order> $orders A map of transaction IDs to orders.
     *
     * @return OrderFinderInterface&MockInterface The new mock.
     */
    protected function createOrderFinder(array $orders): OrderFinderInterface
    {
        $mock = $this->getMockBuilder(OrderFinderInterface::class)
            ->onlyMethods(['findOrderByTransactionId'])
            ->getMock();

        $mock->method('findOrderByTransactionId')
             ->will($this->returnCallback(static function (string $transactionId) use ($orders) {
                 if (isset($orders[$transactionId])) {
                     return $orders[$transactionId];
                 }

                 return null;
             }));

        return $mock;
    }

    /**
     * Creates merchants.
     *
     * @param ContainerInterface $container
     * @param array<int, MerchantData> $overrides Merchant default data overrides.
     *
     * @return array<int, MerchantInterface> A map of merchant ID to merchant DTO.
     * @throws Exception If problem creating.
     */
    protected function createMerchants(ContainerInterface $container, array $overrides = []): array
    {
        $data      = $this->createMerchantsData($container, $overrides);
        $merchants = array_map(function (array $dto) use ($container): MerchantInterface {
            return $this->createMerchant($container, $dto);
        }, $data);

        return $merchants;
    }

    /**
     * Creates merchant data defaults.
     *
     * @param ContainerInterface $container
     * @param array $overrides Data to override defaults with.
     *
     * @return array<int, MerchantData> A map of merchant ID to merchant DTO.
     * @throws Exception If problem creating.
     */
    protected function createMerchantsData(ContainerInterface $container,array $overrides = []): array
    {
        $liveMerchantId = $container->get('inpsyde_payment_gateway.live_merchant_id');
        $sandboxMerchantId = $container->get('inpsyde_payment_gateway.sandbox_merchant_id');
        $baseUrlTemplate = 'https://api.%1$s.%2$s.com';
        $liveBaseUrl = sprintf($baseUrlTemplate, 'live', uniqid());
        $sandboxBaseUrl = sprintf($baseUrlTemplate, 'sandbox', uniqid());

        return $this->arrayMergeRecursive([
            $liveMerchantId => [
                'id' => $liveMerchantId,
                'label' => 'live',
                'code' => uniqid('live-code'),
                'division' => 'Default',
                'token' => uniqid('live-token'),
                'base_url' => $liveBaseUrl,
                'transaction_url_template' => sprintf('%1$s/transaction/%2$s', $liveBaseUrl, '%1$s'),
            ],
            $sandboxMerchantId => [
                'id' => $sandboxMerchantId,
                'label' => 'live',
                'code' => uniqid('live-code'),
                'division' => 'Default',
                'token' => uniqid('live-token'),
                'base_url' => $sandboxBaseUrl,
                'transaction_url_template' => sprintf('%1$s/transaction/%2$s', $sandboxBaseUrl, '%1$s'),
            ],
        ], $overrides);
    }

    /**
     * Creates a new merchant mock from the specified data.
     *
     * @param MerchantData $dto The data.
     *
     * @return MerchantInterface&MockObject The new merchant.
     */
    protected function createMerchant(ContainerInterface $container, array $dto): MerchantInterface
    {
        /** @var MerchantDeserializerInterface $deserializer */
        $deserializer = $container->get('inpsyde_payment_gateway.merchant.deserializer');
        $merchant = $deserializer->deserializeMerchant($dto);

        return $merchant;
    }

    /**
     * Retrieves the application container used for testing.
     *
     * @return ContainerInterface The container.
     *
     * @throws Exception If problem retrieving.
     */
    protected function getContainer(): ContainerInterface
    {
        $container = $this->createPackage()->container();

        return $container;
    }

    /**
     * Merges two arrays recursively.
     *
     * @param array $array1 The first array.
     * @param array $array2 The second array.
     *
     * @return array The union of the first and second array. If values are arrays, same for them,
     *               unless keys are numeric and unique, in which case they are appended.
     */
    protected function arrayMergeRecursive(array $array1, array $array2): array
    {
        $merged = $array1;

        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->arrayMergeRecursive($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
