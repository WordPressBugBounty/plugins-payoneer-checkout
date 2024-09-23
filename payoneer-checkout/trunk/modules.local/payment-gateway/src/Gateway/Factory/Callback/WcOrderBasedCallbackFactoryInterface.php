<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\Factory\Callback;

use Inpsyde\PayoneerSdk\Api\Entities\Callback\CallbackInterface;
use WC_Order;

interface WcOrderBasedCallbackFactoryInterface
{
    /**
     * @param WC_Order $order
     *
     * @return CallbackInterface
     */
    public function createCallback(WC_Order $order): CallbackInterface;
}
