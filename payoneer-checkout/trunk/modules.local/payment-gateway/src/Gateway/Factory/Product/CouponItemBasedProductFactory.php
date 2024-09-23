<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\Factory\Product;

use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\Product\QuantityNormalizerInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Product\ProductFactoryInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Product\ProductInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Product\ProductType;
use WC_Order_Item_Coupon;

class CouponItemBasedProductFactory extends AbstractOrderItemBasedProductFactory implements CouponItemBasedProductFactoryInterface
{
    /**
     * @var ProductFactoryInterface
     */
    protected $productFactory;

    /**
     * @var QuantityNormalizerInterface
     */
    protected $quantityNormalizer;

    /**
     * @inheritDoc
     */
    public function createProduct(WC_Order_Item_Coupon $couponItem, string $currency): ProductInterface
    {
        $type = ProductType::DIGITAL;
        $code = (string) $couponItem->get_id();
        $name = $couponItem->get_name();
        $amount = - (float) $couponItem->get_discount();
        $netAmount = $amount;
        $taxAmount = 0.0; //No taxes for coupons.
        /**
         * @var int|float|string $quantity
         */
        $quantity = $couponItem->get_quantity();
        $quantity = $this->quantityNormalizer->normalizeQuantity($quantity);

        return $this->productFactory
            ->createProduct(
                $type,
                $code,
                $name,
                $amount,
                $currency,
                $quantity,
                $netAmount,
                $taxAmount
            );
    }
}
