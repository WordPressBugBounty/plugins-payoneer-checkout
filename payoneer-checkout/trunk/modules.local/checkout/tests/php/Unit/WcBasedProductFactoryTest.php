<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Checkout\Tests\Unit;

use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\Product\QuantityNormalizerInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\Product\WcBasedProductFactory;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\WcProductSerializer\WcProductSerializerInterface;
use Inpsyde\PayoneerForWoocommerce\Tests\AbstractTestCase;
use Inpsyde\PayoneerSdk\Api\Entities\Product\ProductFactoryInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Product\ProductInterface;
use Mockery;
use WC_Product;

class WcBasedProductFactoryTest extends AbstractTestCase
{
    /**
     * @dataProvider createProductDataProvider
     */
    public function testCreateProductFromWcProduct(array $serializedProductData): void
    {
        $wcProductSerializer = Mockery::mock(WcProductSerializerInterface::class,
            [
                'serializeWcProduct' => $serializedProductData,
            ]
        );
        $quantity = $serializedProductData['quantity'];

        $product = Mockery::mock(ProductInterface::class);
        $product->allows('getQuantity')
                ->andReturn((int) $quantity);
        $productFactory = Mockery::mock(ProductFactoryInterface::class);
        $currency = 'EUR';

        $productFactory->expects('createProduct')
                       ->with(
                           $serializedProductData['type'],
                           $serializedProductData['code'],
                           $serializedProductData['name'],
                           $serializedProductData['amount'],
                           $currency,
                           $quantity,
                           $serializedProductData['netAmount'],
                           $serializedProductData['taxAmount'],
                           $serializedProductData['productDescriptionUrl'],
                           $serializedProductData['productImageUrl'],
                           $serializedProductData['description']
                       )
                       ->andReturn($product);

        $wcProduct = Mockery::mock(WC_Product::class);

        $quantityNormalizer = Mockery::mock(QuantityNormalizerInterface::class);
        $quantityNormalizer->allows('normalizeQuantity')->andReturnArg(0);

        $sut = new WcBasedProductFactory($wcProductSerializer, $productFactory, $quantityNormalizer, $currency);

        $sut->createProductFromWcProduct(
            $wcProduct,
            $quantity,
            $serializedProductData['netAmount'],
            $serializedProductData['taxAmount']
        );
    }

    public function createProductDataProvider()
    {
        $baseUrl = 'https://example.com';

        return [
            [
                [
                    'type'                  => 'PHYSICAL',
                    'code'                  => uniqid('code-'),
                    'name'                  => uniqid('name-'),
                    'amount'                => $amount = rand(0, 1000) / 100,
                    'currency'              => 'EUR',
                    'quantity'              => 1,
                    'taxAmount'             => $taxAmount = $amount / 10,
                    'netAmount'             => $amount - $taxAmount,
                    'productDescriptionUrl' => uniqid($baseUrl . '/product/description'),
                    'productImageUrl'       => uniqid($baseUrl . '/product/productImageUrl'),
                    'description'           => uniqid('decription-'),

                ]
            ],
        ];
    }
}
