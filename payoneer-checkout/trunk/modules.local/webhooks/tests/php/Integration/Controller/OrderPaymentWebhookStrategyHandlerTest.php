<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Webhooks\Tests\Integration\Controller;

use Inpsyde\PayoneerForWoocommerce\Webhooks\OrderPaymentWebhookHandler\ChargeBackPaymentHandler;
use Inpsyde\PayoneerForWoocommerce\Webhooks\OrderPaymentWebhookHandler\ChargedPaymentHandler;
use Inpsyde\PayoneerForWoocommerce\Webhooks\OrderPaymentWebhookHandler\FailedPaymentHandler;
use Inpsyde\PayoneerForWoocommerce\Webhooks\OrderPaymentWebhookHandler\RefundedPaymentHandler;
use Inpsyde\PayoneerForWoocommerce\Webhooks\Tests\Integration\WebhookTestCase;


class OrderPaymentWebhookStrategyHandlerTest extends WebhookTestCase
{
    /**
     * @dataProvider providerRequestData
     * @test
     */
    public function selectCorrectPaymentHandler($expectedClass, $statusCode)
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
        $longId                  = uniqid('long-id-');
        $securityHeaderValue     = uniqid('auth-header-value-');

        $wcOrder = $this->createWcOrderMock(
            [
                $securityHeaderFieldName   => $securityHeaderValue,
                $chargeIdOrderFieldName    => $longId,
                $webhooksReceivedFieldName => [],
            ]
        );

        $request  = $this->createRequestMock(
            [
                'statusCode'     => $statusCode,
                'entity'         => 'payment',
                'longId'         => '',
            ]
        );
        $sut      = $container->get('webhooks.controller.payment_webhook_strategy_handler');
        $strategy = $sut->selectStrategies($request, $wcOrder);
        self::assertInstanceOf($expectedClass, $strategy[0]);
    }

    /**
     *
     * @test
     */
    public function noPaymentHandlerWhenIncorrectStatus()
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
        $longId                  = uniqid('long-id-');
        $securityHeaderValue     = uniqid('auth-header-value-');

        $wcOrder = $this->createWcOrderMock(
            [
                $securityHeaderFieldName   => $securityHeaderValue,
                $chargeIdOrderFieldName    => $longId,
                $webhooksReceivedFieldName => [],
            ]
        );

        $request  = $this->createRequestMock(
            [
                'statusCode'     => 'weird_status_code',
                'entity'         => 'payment',
                'longId'         => '',
            ]
        );
        $sut      = $container->get('webhooks.controller.payment_webhook_strategy_handler');
        $strategy = $sut->selectStrategies($request, $wcOrder);
        self::assertEquals([], $strategy);
    }

    public function providerRequestData() {
        return array(
            [ChargedPaymentHandler::class, 'charged'],
            [FailedPaymentHandler::class, 'failed'],
            [FailedPaymentHandler::class, 'canceled'],
            [FailedPaymentHandler::class, 'declined'],
            [FailedPaymentHandler::class, 'rejected'],
            [FailedPaymentHandler::class, 'aborted'],
            [ChargeBackPaymentHandler::class, 'charged_back'],
            [RefundedPaymentHandler::class, 'paid_out'],
        );
    }
}
