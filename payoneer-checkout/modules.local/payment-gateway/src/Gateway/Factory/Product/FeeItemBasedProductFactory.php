<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\Factory\Product;

use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\Product\QuantityNormalizerInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Product\ProductFactoryInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Product\ProductInterface;
use WC_Order_Item_Fee;

class FeeItemBasedProductFactory extends AbstractOrderItemBasedProductFactory implements FeeItemBasedProductFactoryInterface
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
    public function createProduct(WC_Order_Item_Fee $feeItem, string $currency): ProductInterface
    {
        return $this->createProductFromShippingOrFeeItem($feeItem, $currency);
    }
}
