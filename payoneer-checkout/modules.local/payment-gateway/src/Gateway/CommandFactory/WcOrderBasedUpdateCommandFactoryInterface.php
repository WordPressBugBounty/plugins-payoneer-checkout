<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\CommandFactory;

use Inpsyde\PayoneerForWoocommerce\Checkout\CheckoutExceptionInterface;
use Inpsyde\PayoneerSdk\Api\ApiExceptionInterface;
use Inpsyde\PayoneerSdk\Api\Command\UpdateListCommandInterface;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use WC_Order;

interface WcOrderBasedUpdateCommandFactoryInterface
{
    /**
     * Create a configured command instance using data from provided order.
     *
     * @param WC_Order $order Order to take data from.
     *
     * @return UpdateListCommandInterface Created and configured command instance.
     *
     * @throws CheckoutExceptionInterface|ApiExceptionInterface
     */
    public function createUpdateCommand(WC_Order $order, ListInterface $list): UpdateListCommandInterface;
}
