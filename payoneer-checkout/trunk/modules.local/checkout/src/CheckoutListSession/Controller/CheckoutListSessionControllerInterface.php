<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Checkout\CheckoutListSession\Controller;

use Inpsyde\PayoneerForWoocommerce\Checkout\CheckoutExceptionInterface;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use WC_Cart;
use WC_Customer;

/**
 * A service able to create or update LIST session using WC checkout data.
 */
interface CheckoutListSessionControllerInterface
{
    /**
     * Update existing session with WC session data.
     *
     * @param WC_Customer $customer Customer data source.
     * @param WC_Cart $cart Products and amount data source.
     *
     * @return ListInterface Created LIST session instance.
     *
     * @throws CheckoutExceptionInterface If failed to update LIST session.
     */
    public function updateListSessionFromCheckoutData(
        WC_Customer $customer,
        WC_Cart $cart
    ): ListInterface;
}
