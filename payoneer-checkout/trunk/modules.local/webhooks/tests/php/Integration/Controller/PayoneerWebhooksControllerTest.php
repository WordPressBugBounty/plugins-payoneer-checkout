<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Webhooks\Tests\Integration\Controller;

use Brain\Monkey;
use Inpsyde\PayoneerForWoocommerce\Webhooks\Tests\Integration\WebhookTestCase;
use Mockery;
use Mockery\MockInterface;
use WC_Order;
use WC_Order_Refund;

use function Brain\Monkey\Functions\expect;

class PayoneerWebhooksControllerTest extends WebhookTestCase
{
    public function testWebhookPaymentCharged()
    {
        $package                 = $this->createPackage();
        $container               = $package->container();
        $chargeIdOrderFieldName  = $container->get(
            'core.payment_gateway.order.charge_id_field_name'
        );
        $securityHeaderFieldName = $container->get(
            'core.payment_gateway.order.security_header_field_name'
        );
        $webhooksReceivedFieldName = $container->get(
            'webhooks.order.processed_id_field_name'
        );
        $transactionId           = uniqid('transaction-id-');
        $longId                  = uniqid('long-id-');
        $paymentAmount           = floatval(rand(1, 10000) / rand(1, 10));
        $paymentCurrency         = 'EUR';
        $pspCode                 = uniqid('pspcode-');
        $notificationId          = uniqid('notification-id-');
        $securityHeaderValue     = uniqid('auth-header-value-');

        $wcOrder = $this->createWcOrderMock([
            $securityHeaderFieldName => $securityHeaderValue,
            $chargeIdOrderFieldName => $longId,
            $webhooksReceivedFieldName => [],
        ]);
        $wcOrder->allows([
            'get_total'             => $paymentAmount,
            'get_currency'          => $paymentCurrency
        ]);

        $wcOrder->expects('save')
                ->times(2);
        $wcOrder->expects('payment_complete');
        $wcOrder->expects('update_meta_data')
                ->with($webhooksReceivedFieldName, [0 => $notificationId]);
        $wcOrder->expects('update_meta_data')
                ->with($chargeIdOrderFieldName, $longId);
        $wcOrder->expects('add_order_note')
                ->andReturnUsing(function  (string $note) use ($notificationId){
                    $this->assertStringContainsString($notificationId, $note);
                });

        Monkey\Functions\expect('wc_get_orders')
            ->andReturn([$wcOrder]);

        $request  = $this->expectListSecurityHeader(
            $this->createRequestMock(
                [
                    'transactionId'  => $transactionId,
                    'statusCode'     => 'charged',
                    'entity'         => 'payment',
                    'longId'         => $longId,
                    'amount'         => $paymentAmount,
                    'currency'       => $paymentCurrency,
                    'pspCode'        => $pspCode,
                    'notificationId' => $notificationId,
                ]
            ),
            $securityHeaderValue
        );
        $sut      = $container->get('webhooks.controller.webhooks_controller');
        $response = $sut->handleWpRestRequest($request);

        //TODO: we never return anything else than 200 with empty body, so this assert is useless
        $this->assertSame(
            200,
            $response->get_status(),
            'Response code is not equals 200.'
        );
    }

    public function testWebhookPaymentRefunded()
    {
        $plugin    = $this->createPackage();
        $container = $plugin->container();

        $transactionIdOrderFieldName = $container->get('webhooks.order.transaction_id_field_name');
        $securityHeaderFieldName     = $container->get(
            'core.payment_gateway.order.security_header_field_name'
        );
        $chargeIdFieldName = $container->get('webhooks.order.charge_id_field_name');
        $webhooksReceivedFieldName = $container->get(
            'webhooks.order.processed_id_field_name'
        );
        $sut                         = $container->get('webhooks.controller.webhooks_controller');

        $transactionId       = uniqid('transaction-id-');
        $chargeId              = uniqid('long-id-');
        $payoutId = uniqid('payout-id');
        $refundAmount        = floatval(rand(1, 10000) / rand(1, 10));
        $totalAmount = $refundAmount + 1;
        $paymentCurrency     = 'EUR';
        $pspCode             = uniqid('pspcode-');
        $notificationId      = uniqid('notification-id-');
        $securityHeaderValue = uniqid('auth-header-value-');
        $orderId             = rand(1, 1000);

        $requestData = [
            'transactionId'  => $transactionId,
            'statusCode'     => 'paid_out',
            'entity'         => 'payment',
            'longId'         => $payoutId,
            'amount'         => $refundAmount,
            'currency'       => $paymentCurrency,
            'pspCode'        => $pspCode,
            'notificationId' => $notificationId,
        ];

        $orderItems = [];

        $metaData = [
            $chargeIdFieldName => $chargeId,
            $securityHeaderFieldName => $securityHeaderValue,
            $webhooksReceivedFieldName => []
        ];
        $wcOrder = $this->createWcOrderMock($metaData);
        $wcOrder->allows(
            [
                'get_currency' => $paymentCurrency,
                'get_total' => $totalAmount,
                'get_id' => $orderId,
                'get_items' => $orderItems,
            ]
        );

        $wcOrderRefund = Mockery::mock(WC_Order_Refund::class);
        $wcOrderRefund->allows(
            [
                'add_meta_data' => null,
            ]);
        $wcOrderRefund->expects('save')
            ->atLeast(1);

        $validArgs = \Mockery::on(function (array $args) use (
            $transactionIdOrderFieldName,
            $transactionId
        ) {
            return isset($args[$transactionIdOrderFieldName]) &&
                   $args[$transactionIdOrderFieldName] === $transactionId;
        });
        expect('wc_get_orders')
            ->once()
            ->with($validArgs)
            ->andReturn([$wcOrder]);

        expect('wc_get_orders')
            ->once()
            ->with(
                \Mockery::on(function (array $args) {
                    return isset($args['type']) && $args['type'] === 'shop_order_refund';
                })
            )
            ->andReturn([]);
        $wcOrder->expects('update_meta_data')
                ->with($webhooksReceivedFieldName, [0 => $notificationId]);
        $wcOrder->expects('save')
                ->once();
        $request = $this->expectListSecurityHeader(
            $this->createRequestMock($requestData),
            $securityHeaderValue
        );

        expect('wc_create_refund')
            ->once()
            ->andReturnUsing(
                function ($arg) use ($refundAmount, $orderItems, $orderId, $wcOrderRefund) {
                    $this->assertIsString($arg['reason']);
                    $this->assertSame($refundAmount, $arg['amount']);
                    $this->assertSame($orderItems, $arg['line_items']);
                    $this->assertFalse($arg['refund_payment']);

                    return $wcOrderRefund;
                }
            );


        $response = $sut->handleWpRestRequest($request);

        //TODO: we never return anything else than 200 with empty body, so this assert is useless
        $this->assertSame(
            200,
            $response->get_status(),
            'Response code is not equals 200.'
        );
    }

    public function testDuplicateRefund()
    {
        $plugin    = $this->createPackage();
        $container = $plugin->container();

        $transactionIdOrderFieldName = $container->get('webhooks.order.transaction_id_field_name');
        $securityHeaderFieldName     = $container->get(
            'core.payment_gateway.order.security_header_field_name'
        );
        $webhooksReceivedFieldName = $container->get(
            'webhooks.order.processed_id_field_name'
        );
        $chargeIdFieldName = $container->get('webhooks.order.charge_id_field_name');
        $sut                         = $container->get('webhooks.controller.webhooks_controller');

        $transactionId       = uniqid('transaction-id-');
        $longId              = uniqid('long-id-');
        $payoutId = uniqid('payout-it-');
        $refundAmount        = floatval(rand(1, 10000) / rand(1, 10));
        $totalAmount = $refundAmount +1;
        $paymentCurrency     = 'EUR';
        $pspCode             = uniqid('pspcode-');
        $notificationId      = uniqid('notification-id-');
        $securityHeaderValue = uniqid('auth-header-value-');
        $orderId             = rand(1, 1000);

        $requestData = [
            'transactionId'  => $transactionId,
            'statusCode'     => 'paid_out',
            'entity'         => 'payment',
            'longId'         => $payoutId,
            'amount'         => $refundAmount,
            'currency'       => $paymentCurrency,
            'pspCode'        => $pspCode,
            'notificationId' => $notificationId,
        ];

        $orderItems = [];

        $metaData = [
            $chargeIdFieldName       => $longId,
            $securityHeaderFieldName => $securityHeaderValue,
            $webhooksReceivedFieldName => [],
        ];
        $wcOrder = $this->createWcOrderMock($metaData);

        $wcOrder->allows(
            [
                'get_currency' => $paymentCurrency,
                'get_total' => $totalAmount,
                'get_id' => $orderId,
                'get_items' => $orderItems,
            ]
        );

        $wcOrderRefund = Mockery::mock(WC_Order_Refund::class);

        expect('wc_get_orders')
            ->once()
            ->with(
                \Mockery::on(function (array $args) use (
                    $transactionIdOrderFieldName,
                    $transactionId
                ) {
                    return isset($args[$transactionIdOrderFieldName]) &&
                           $args[$transactionIdOrderFieldName] === $transactionId;
                })
            )
            ->andReturn([$wcOrder]);

        expect('wc_get_orders')
            ->once()
            ->with(
                \Mockery::on(function (array $args) {
                    return isset($args['type']) && $args['type'] === 'shop_order_refund';
                })
            )
            ->andReturn([$wcOrderRefund]);
        $wcOrder->expects('update_meta_data')
                ->with($webhooksReceivedFieldName, [0 => $notificationId]);
        $wcOrder->expects('save');
        $request = $this->expectListSecurityHeader(
            $this->createRequestMock($requestData),
            $securityHeaderValue
        );

        expect('wc_create_refund')->never();


        $response = $sut->handleWpRestRequest($request);

        //TODO: we never return anything else than 200 with empty body, so this assert is useless
        $this->assertSame(
            200,
            $response->get_status(),
            'Response code is not equals 200.'
        );
    }

    /**
     * Status notification about updated CHARGE after PAYOUT was made is
     * similar to the PAYOUT notification. But it must not lead to creating
     * a refund in WC in contrast with PAYOUT notification.
     */
    public function testWebhookChargeStatusUpdated()
    {
        $plugin    = $this->createPackage();
        $container = $plugin->container();
        $notificationId          = uniqid('notification-id-');
        $transactionIdOrderFieldName = $container->get('webhooks.order.transaction_id_field_name');
        $securityHeaderFieldName = $container->get('core.payment_gateway.order.security_header_field_name');
        $chargeIdFieldName = $container->get('webhooks.order.charge_id_field_name');
        $webhooksReceivedFieldName = $container->get(
            'webhooks.order.processed_id_field_name'
        );
        $sut = $container->get('webhooks.controller.webhooks_controller');

        $securityHeaderValue = uniqid('auth-header-value-');
        $transactionId = uniqid('transaction-id-');
        $chargeLongId = uniqid('charge-long-id-');
        $orderAmount = floatval(rand(1, 10000) / rand(1, 10));
        $currency = 'EUR';
        $refundAmount = $orderAmount;
        $requestData = [
            'transactionId' => $transactionId,
            'statusCode' => 'paid_out',
            'amount' => (string) $refundAmount,
            'currency' => $currency,
            'longId' => $chargeLongId,
            'notificationId' => $notificationId,
        ];
        $orderMeta = [
            $securityHeaderFieldName => $securityHeaderValue,
            $chargeIdFieldName => $chargeLongId,
            $webhooksReceivedFieldName => [],
        ];
        /** @var WC_Order|MockInterface $wcOrder */
        $wcOrder = $this->createWcOrderMock($orderMeta);
        $wcOrder->allows([
            'get_total' => $orderAmount,
            'get_currency' => $currency,
        ]);
        $wcOrder->expects('update_meta_data')
                ->with($webhooksReceivedFieldName, [0 => $notificationId]);
        $wcOrder->expects('save');
        expect('wc_get_orders')->once()
            ->with(\Mockery::on(function (array $args) use ($transactionIdOrderFieldName, $transactionId){
                return isset($args[$transactionIdOrderFieldName]) &&
                       $args[$transactionIdOrderFieldName] === $transactionId;
            }))
            ->andReturn([$wcOrder]);

        $request = $this->expectListSecurityHeader(
            $this->createRequestMock($requestData),
            $securityHeaderValue
        );

        expect('wc_create_refund')->never();

        $sut->handleWpRestRequest($request);
    }

    public function testChargedBack()
    {
        $package                 = $this->createPackage();
        $container               = $package->container();
        $securityHeaderFieldName = $container->get(
            'core.payment_gateway.order.security_header_field_name'
        );
        $webhooksReceivedFieldName = $container->get(
            'webhooks.order.processed_id_field_name'
        );
        $chargeIdFieldName       = $container->get('webhooks.order.charge_id_field_name');
        $transactionId           = uniqid('transaction-id-');
        $longId                  = uniqid('long-id-');
        $paymentAmount           = floatval(rand(1, 10000) / rand(1, 10));
        $paymentCurrency         = 'EUR';
        $pspCode                 = uniqid('pspcode-');
        $notificationId          = uniqid('notification-id-');
        $securityHeaderValue     = uniqid('auth-header-value-');

        $wcOrder = $this->createWcOrderMock([
            $securityHeaderFieldName => $securityHeaderValue,
            $chargeIdFieldName => $longId,
            $webhooksReceivedFieldName => [],
        ]);

        $wcOrder->expects('add_order_note')
            ->andReturnUsing(function ($note) use ($notificationId){
                $this->assertStringContainsString($notificationId, $note);
            });
        $wcOrder->expects('save')
            ->times(2);
        Monkey\Functions\expect('wc_get_orders')
            ->andReturn([$wcOrder]);
        $wcOrder->expects('update_meta_data')
                ->with($webhooksReceivedFieldName, [0 => $notificationId]);
        $request  = $this->expectListSecurityHeader(
            $this->createRequestMock(
                [
                    'transactionId'  => $transactionId,
                    'statusCode'     => 'charged_back',
                    'entity'         => 'payment',
                    'longId'         => $longId,
                    'amount'         => $paymentAmount,
                    'currency'       => $paymentCurrency,
                    'pspCode'        => $pspCode,
                    'notificationId' => $notificationId,
                ]
            ),
            $securityHeaderValue
        );
        $sut      = $container->get('webhooks.controller.webhooks_controller');
        $response = $sut->handleWpRestRequest($request);

        //TODO: we never return anything else than 200 with empty body, so this assert is useless
        $this->assertSame(
            200,
            $response->get_status(),
            'Response code is not equals 200.'
        );
    }

    public function testWebhookPaymentFailed()
    {
        $package                 = $this->createPackage();
        $container               = $package->container();
        $securityHeaderFieldName = $container->get(
            'core.payment_gateway.order.security_header_field_name'
        );
        $webhooksReceivedFieldName = $container->get(
            'webhooks.order.processed_id_field_name'
        );
        $chargeIdFieldName       = $container->get('webhooks.order.charge_id_field_name');
        $transactionId           = uniqid('transaction-id-');
        $longId                  = uniqid('long-id-');
        $paymentAmount           = floatval(rand(1, 10000) / rand(1, 10));
        $paymentCurrency         = 'EUR';
        $pspCode                 = uniqid('pspcode-');
        $notificationId          = uniqid('notification-id-');
        $securityHeaderValue     = uniqid('auth-header-value-');
        $statusCode              = 'failed';

        $wcOrder = $this->createWcOrderMock([
            $securityHeaderFieldName => $securityHeaderValue,
            $chargeIdFieldName => $longId,
            $webhooksReceivedFieldName => [],
        ]);

        $wcOrder->expects('has_status')->andReturn(false);
        $wcOrder->expects('update_status')
                ->withArgs([$statusCode]);
        $wcOrder->expects('add_order_note')
            ->once();
        $wcOrder->expects('save')
                ->times(2);
        $wcOrder->expects('update_meta_data')
                ->with($webhooksReceivedFieldName, [0 => $notificationId]);
        Monkey\Functions\expect('wc_get_orders')
            ->andReturn([$wcOrder]);

        $request  = $this->expectListSecurityHeader(
            $this->createRequestMock(
                [
                    'transactionId'  => $transactionId,
                    'statusCode'     => $statusCode,
                    'entity'         => 'payment',
                    'longId'         => $longId,
                    'amount'         => $paymentAmount,
                    'currency'       => $paymentCurrency,
                    'pspCode'        => $pspCode,
                    'notificationId' => $notificationId,
                ]
            ),
            $securityHeaderValue
        );
        $sut      = $container->get('webhooks.controller.webhooks_controller');
        $response = $sut->handleWpRestRequest($request);

        //TODO: we never return anything else than 200 with empty body, so this assert is useless
        $this->assertSame(
            200,
            $response->get_status(),
            'Response code is not equals 200.'
        );
    }

    public function testWebhookAlreadyProcessed()
    {
        $package                 = $this->createPackage();
        $container               = $package->container();

        $securityHeaderFieldName = $container->get(
            'core.payment_gateway.order.security_header_field_name'
        );
        $webhooksReceivedFieldName = $container->get(
            'webhooks.order.processed_id_field_name'
        );
        $notificationId          = uniqid('notification-id-');
        $securityHeaderValue     = uniqid('auth-header-value-');

        $wcOrder = $this->createWcOrderMock([
            $securityHeaderFieldName => $securityHeaderValue,
            $webhooksReceivedFieldName => [0 => $notificationId],
        ]);

        $wcOrder->expects('payment_complete')->never();
        $wcOrder->expects('update_meta_data')
                ->never();

        Monkey\Functions\expect('wc_get_orders')
            ->andReturn([$wcOrder]);

        $request  = $this->expectListSecurityHeader(
            $this->createRequestMock(
                [
                    'notificationId' => $notificationId,
                ]
            ),
            $securityHeaderValue
        );
        $sut      = $container->get('webhooks.controller.webhooks_controller');
        $sut->handleWpRestRequest($request);
    }
}
