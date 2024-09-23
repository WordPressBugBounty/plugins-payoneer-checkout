<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\Factory\Product;

use Inpsyde\PayoneerSdk\Api\Entities\Product\ProductInterface;
use WC_Order;

interface WcOrderBasedProductsFactoryInterface
{
    /**
     * Create a products of a WC Order instance.
     *
     * @return ProductInterface[]
     */
    public function createProductsFromWcOrder(WC_Order $order): array;
}
