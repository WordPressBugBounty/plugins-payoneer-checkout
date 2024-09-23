<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\Factory\Product;

use Inpsyde\Assets\Exception\InvalidArgumentException;
use Inpsyde\PayoneerSdk\Api\Entities\Product\ProductInterface;
use WC_Order_Item_Coupon;

interface CouponItemBasedProductFactoryInterface
{
    /**
     * Create product from order item coupon.
     *
     * @param WC_Order_Item_Coupon $couponItem WC order item to get data from.
     * @param string $currency Currency of the coupon amount.
     *
     * @return ProductInterface Created product.
     *
     * @throws InvalidArgumentException If failed to create product from provided item.
     */
    public function createProduct(WC_Order_Item_Coupon $couponItem, string $currency): ProductInterface;
}
