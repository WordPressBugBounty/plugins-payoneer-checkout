<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\CommandFactory;

use Inpsyde\PayoneerSdk\Api\Command\ChargeCommandInterface;
use WC_Order;

/**
 * Service responsible for processing payment.
 */
interface WcOrderBasedChargeCommandFactoryInterface
{
    /**
     * Configure charge command with provided WC_Order data.
     *
     * @param WC_Order $order
     *
     * @return ChargeCommandInterface
     *
     * @throws CommandFactoryException
     *
     */
    public function createChargeCommand(WC_Order $order): ChargeCommandInterface;
}
