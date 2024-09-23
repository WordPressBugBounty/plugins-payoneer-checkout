<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\Factory\Customer;

use Inpsyde\PayoneerSdk\Api\Entities\Customer\CustomerInterface;
use WC_Order;

interface WcOrderBasedCustomerFactoryInterface
{
    /**
     * Create a new Customer using data from provided order.
     *
     * @param WC_Order $order To get data from
     *
     * @return CustomerInterface Created customer
     */
    public function createCustomer(WC_Order $order): CustomerInterface;
}
