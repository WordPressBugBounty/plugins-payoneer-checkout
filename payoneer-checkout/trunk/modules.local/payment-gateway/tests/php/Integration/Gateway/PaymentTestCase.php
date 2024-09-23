<?php

declare(strict_types=1);


namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Tests\Integration\Gateway;

use Dhii\Services\Factories\Value;

use function Brain\Monkey\Functions\when;

class PaymentTestCase extends PaymentGatewayTestCase
{
    /**
     * @var string
     */
    protected $securityToken;

    public function setUp(): void
    {
        /**
         * TODO replace 'inpsyde_payment_gateway.payoneer' with a mocked PaymentProcessor/ChargeCommand
         * This would allow us to actually expect that it is called with our mocked order data
         * For now we just silence it...
         */
        when('wp_remote_retrieve_response_code')->justReturn(200);
        when('wp_remote_retrieve_response_message')->justReturn('OK');
        when('is_admin')->justReturn(false);
        when('is_checkout')->justReturn(true);
        when('wp_doing_ajax')->justReturn(true);
        when('is_checkout_pay_page')->justReturn(false);
        $this->securityToken = uniqid('token-');
        $this->injectService(
            'inpsyde_payment_gateway.list_security_token',
            new Value($this->securityToken)
        );
        $this->injectService(
            'checkout.security_token',
            new Value($this->securityToken)
        );
        $this->getWcSession()->allows('get');

        parent::setUp();
    }

    /**
     * Prepare the application environment with a preconfigured Order and LIST session data
     * @param string $statusCode
     * @param string $statusReason
     * @param string $longId
     *
     * @return void
     */
    protected function preparePayment(
        string $statusCode,
        string $statusReason,
        string $longId,
        string $transactionId
    ) {
        $this->prepareGateway();
        $this->prepareRequest([
            'status.code'           => $statusCode,
            'status.reason'         => $statusReason,
            'identification.longId' => $longId,
            'identification.transactionId' => $transactionId,
        ]);
        $this->prepareListSessionData($longId, $transactionId);
        $order = $this->getOrder();
        $orderItem = $this->expectProductOrderItem();
        $order->expects('get_items')->once()->andReturn([$orderItem]);
    }

    protected function prepareListSessionData(
        string $longId = null,
        string $transactionId = null,
        string $merchantId = null
    ) {
        $longId               = $longId ?? uniqid('long-id-');
        $transactionId        = $transactionId ?? uniqid('transaction-id-');
        $container            = $this->createPackage()->container();
        $listSessionFieldName = $container->get('inpsyde_payment_gateway.list_session_field_name');
        $transactionIdFieldName = $container->get('webhooks.order.transaction_id_field_name');
        $merchantId = $merchantId ?? $container->get('inpsyde_payment_gateway.sandbox_merchant_id');
        $merchantIdFieldName = $container->get('inpsyde_payment_gateway.merchant_id_field_name');
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
                'amount'    => 18.0,
                'currency'  => self::$currency,
                'invoiceId' => 'foo'
            ],

            'status' => [
                'code'   => 'listed',
                'reason' => 'listed'
            ],
        ];
        $this->expectOrderMeta($listSessionFieldName, $listSessionData);
        $this->expectOrderMeta($transactionIdFieldName, $transactionId);
        $this->expectOrderMeta($merchantIdFieldName, $merchantId);
    }
}
