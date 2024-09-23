<?php

declare(strict_types=1);


namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Tests\Integration\Gateway;


use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentGateway;
use Inpsyde\PayoneerForWoocommerce\Webhooks\RefundFinder\RefundFinder;
use Inpsyde\PayoneerSdk\Api\Command\PayoutCommandInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Identification\IdentificationInterface;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Payment\Payment;
use Inpsyde\PayoneerSdk\Api\PayoneerInterface;
use Mockery\MockInterface;

use function Brain\Monkey\Functions\when;
use function Brain\Monkey\Functions\expect;

class ProcessRefundTest extends PaymentGatewayTestCase
{
    public function setUp(): void
    {
        when('is_admin')->justReturn(false);
        when('is_checkout')->justReturn(true);
        when('wp_doing_ajax')->justReturn(true);
        when('is_checkout_pay_page')->justReturn(false);
        parent::setUp();
    }

    public function testSuccessfulRefund()
    {
        $transactionId = uniqid('transaction-id-');
        $longId        = uniqid('long-id-');
        $chargeId      = uniqid('charge-id');
        $amount        = (float)rand(1, 10000) / 100;

        $payoutCommand = $this->mockPayoutCommand($chargeId, $transactionId, $amount);
        $list          = \Mockery::mock(ListInterface::class, [
            'getIdentification' => \Mockery::mock(IdentificationInterface::class, [
                'getLongId' => $longId
            ])
        ]);
        $payoutCommand->expects('execute')->andReturn($list);


        $this->prepareRequest([
            'identification.longId'        => $longId,
            'identification.transactionId' => $transactionId,
            'chargeId'                     => $chargeId,
            'payment.amount'               => $amount,
        ]);
        $this->prepareGateway();
        $this->prepareListSessionData($longId, $transactionId, $amount);

        $container = $this->createPackage()->container();

        $chargeIdFieldName = $container->get('inpsyde_payment_gateway.charge_id_field_name');

        $this->expectOrderMeta($chargeIdFieldName, $chargeId);

        when('get_current_blog_id')->justReturn(1);
        when('get_rest_url')->justReturn('rest_url');
        $paymentGateway = $container->get('inpsyde_payment_gateway.gateway');

        $this->assertInstanceOf(PaymentGateway::class, $paymentGateway);
        $order         = $this->getOrder();
        $orderId = $order->get_id();
        $refundReason = 'Some test refund reason.';

        /**
         * @see RefundFinder
         * Maybe mock this away more elegantly.
         * We can also double-check duplicate refunds here if we feel the need to
         */
        expect('wc_get_orders')->andReturn([]);


        $result = $paymentGateway->process_refund($orderId, $amount, $refundReason);

        $this->assertTrue($result);
    }

    public function testFailedRefund()
    {
        $WP_ERROR = \Mockery::mock('overload:' . \WP_Error::class);
        $amount   = (float)rand(1, 10000) / 100;
        $this->prepareGateway();

        $container      = $this->createPackage()->container();
        when('get_current_blog_id')->justReturn(1);
        when('get_rest_url')->justReturn('rest_url');
        $paymentGateway = $container->get('inpsyde_payment_gateway.gateway');

        $this->assertInstanceOf(PaymentGateway::class, $paymentGateway);
        $orderId      = $this->getOrder()->get_id();
        $refundReason = 'Some test refund reason.';
        $result       = $paymentGateway->process_refund($orderId, $amount, $refundReason);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    /**
     * Mock away the PayoutCommand with a set of expectations
     *
     * @return PayoutCommandInterface&MockInterface
     */
    protected function mockPayoutCommand(
        string $longId,
        string $transactionId,
        float $amount
    ): PayoutCommandInterface {
        $payoutCommand = \Mockery::mock(PayoutCommandInterface::class);
        $payoutCommand->expects('withLongId')
                      ->with($longId)
                      ->andReturn($payoutCommand);

        $payoutCommand->expects('withTransactionId')
                      ->with($transactionId)
                      ->andReturn($payoutCommand);

        $payoutCommand->expects('withPayment')
                      ->with(
                          \Mockery::on(function (Payment $payment) use ($amount) {
                              return $payment->getAmount() === $amount;
                          })
                      )->andReturn($payoutCommand);

        $this->injectService('inpsyde_payment_gateway.payoneer', function () use ($payoutCommand) {
            $payoneer = \Mockery::mock(
                PayoneerInterface::class,
                ['getPayoutCommand' => $payoutCommand]
            );

            return $payoneer;
        });

        return $payoutCommand;
    }

    protected function prepareListSessionData(
        string $longId = null,
        string $transactionId = null,
        float $amount = null
    ) {
        $longId               = $longId ?? uniqid('long-id-');
        $transactionId        = $transactionId ?? uniqid('transaction-id-');
        $amount               = $amount ?? (float)rand(1, 10000) / 100;
        $container            = $this->createPackage()->container();
        $listSessionFieldName = $container->get('inpsyde_payment_gateway.list_session_field_name');
        $listSessionData      = [
            'links' => [
                'self' => "https://api.sandbox.oscato.com/pci/v1/$longId",
                'lang' => 'https://resources.sandbox.oscato.com/resource/lang/INPSYDE/de_DE/checkout.properties',
            ],

            'identification' => [
                'longId'        => $longId,
                'shortId'       => '02827-27671',
                'transactionId' => $transactionId,
                'pspId'         => '',
            ],

            'payment' => [
                'reference' => 'Checkout payment',
                'amount'    => $amount,
                'currency'  => self::$currency,
                'invoiceId' => 'foo'
            ],

            'status' => [
                'code'   => 'charged',
                'reason' => 'debited'
            ],

        ];
        $this->expectOrderMeta($listSessionFieldName, $listSessionData);
    }

    public function refundListSessionDataProvider(): array
    {
        $transactionId = uniqid('transaction-id-');
        $longId        = uniqid('long-id-');
        $chargeId      = uniqid('charge-id');

        $listSessionData = [
            'links' => [
                'self' => "https://api.sandbox.oscato.com/pci/v1/$longId",
                'lang' => 'https://resources.sandbox.oscato.com/resource/lang/INPSYDE/de_DE/checkout.properties',
            ],

            'identification' => [
                'longId'        => $longId,
                'shortId'       => '02827-27671',
                'transactionId' => $transactionId,
                'pspId'         => '',
            ],

            'payment' => [
                'reference' => 'Checkout payment',
                'amount'    => 18.0,
                'currency'  => self::$currency,
            ],

            'status' => [
                'code'   => 'charged',
                'reason' => 'debited'
            ]
        ];

        return [
            [
                $listSessionData,
                $chargeId,
            ]
        ];
    }
}
