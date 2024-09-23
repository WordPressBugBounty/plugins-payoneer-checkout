<?php

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway;

use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Exception\PaymentGatewayExceptionInterface;

interface PaymentRequestValidatorInterface
{
    /**
     * @param \WC_Order $wcOrder
     * @param PaymentGateway $gateway
     * @throws PaymentGatewayExceptionInterface
     *
     * @return void
     */
    public function assertIsValid(\WC_Order $wcOrder, PaymentGateway $gateway): void;
}
