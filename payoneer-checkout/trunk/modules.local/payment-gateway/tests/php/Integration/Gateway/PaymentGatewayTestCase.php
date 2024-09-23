<?php

declare(strict_types=1);


namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Tests\Integration\Gateway;


use Dhii\Collection\MapFactoryInterface;
use Dhii\Collection\MapInterface;
use Dhii\Services\Factories\Value;
use Inpsyde\PayoneerForWoocommerce\Checkout\HashProvider\HashProviderInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\ContainerMapMerchantModel;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\MerchantInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\MerchantQueryInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\SaveMerchantCommandInterface;
use Inpsyde\PayoneerForWoocommerce\Tests\Helpers\RenderTemplateHelper;
use Inpsyde\PayoneerForWoocommerce\Tests\Integration\AbstractApplicationTestCase;
use Mockery;
use Mockery\MockInterface;
use Psr\Container\ContainerInterface;

use Psr\Http\Message\UriFactoryInterface;
use stdClass;


use WC_Cart;
use WC_Customer;
use WP_Http;

use function Brain\Monkey\Functions\expect;
use function Brain\Monkey\Functions\when;

abstract class PaymentGatewayTestCase extends AbstractApplicationTestCase
{
    protected $order = null;
    protected $orderData = [];

    protected static $currency = 'EUR';
    private $session;

    public function setUp(): void
    {
        $this->order      = null;
        $this->orderItems = [];
        when('get_woocommerce_currency')
            ->justReturn('EUR');
        $this->injectService('wp.current_locale',function (){
            return 'de_DE';
        });
        $this->injectService(
            'checkout.list_session_manager.cache_key',
            new Value(uniqid('session-key-'))
        );

        parent::setUp();
    }

    public function getGatewayConfig()
    {
        return [
            'is_sandbox' => true,
            'base_url'   => 'https://foo.bar'
        ];
    }

    protected function expectOrderMeta(string $name, $value, bool $single = true)
    {
        $this->getOrder()
            ->allows('get_meta')
            ->atLeast(1)
            ->with($name, $single)
            ->andReturns($value);
    }

    /**
     * @return \WC_Order|MockInterface
     */
    public function getOrder()
    {
        if ($this->order === null) {
            $order = \Mockery::mock(\WC_Order::class);
            $orderId  = rand(1, PHP_INT_MAX);
            $order->allows([
                'get_id'                          => $orderId,
                'get_order_number'                => (string)rand(1, PHP_INT_MAX),
                'get_total'                       => rand(1, PHP_INT_MAX),
                'get_currency'                    => self::$currency,
                'get_checkout_payment_url'        => 'https://example.com/',
                'get_checkout_order_received_url' => 'https://example.com/',
                'get_view_order_url'              => 'https://example.com/',
                'get_cancel_order_url_raw'        => 'https://example.com/',
                'get_billing_phone'               => 'https://example.com/',
                'get_billing_first_name'          => uniqid('billing-first-name-'),
                'get_billing_last_name'           => uniqid('billing-last-name-'),
                'get_billing_country'             => uniqid('billing-country-'),
                'get_billing_city'                => uniqid('billing-city-'),
                'get_billing_address_1'           => uniqid('billing-address-1-'),
                'get_billing_postcode'            => uniqid('billing-postcode-'),
                'has_shipping_address'            => false,
                //TODO specifically test for differing shipping address
                'get_shipping_postcode'           => uniqid('shipping-postcode-'),
                'get_customer_id'                 => rand(0, 100),
                'get_billing_email'               => uniqid('billing-email-'),
                'save'                            => $orderId
            ]);
            $this->order = $order;
        }

        return $this->order;
    }

    /**
     * @param array $productData
     *
     * @return \WC_Order_Item_Product|MockInterface
     */
    protected function expectProductOrderItem(int $quantity = 1, array $productData = [])
    {
        $productData = array_merge(
            [
                'get_id'          => rand(1, 1000),
                'get_price'       => (float)rand(1, 10000) / 100,
                'get_title'       => 'Product title',
                'get_description' => 'Some test product description',
                'get_image_id'    => rand(1, 1000),
                'get_permalink'   => 'https://example/com/images/test-product',
                'is_virtual'      => false
            ],
            $productData
        );
        $product     = \Mockery::mock(\WC_Product::class, $productData);

        expect('wp_get_attachment_image_src')
            ->with($productData['get_image_id'])
            ->andReturn('https://example.com/images/image1.jpg');

        $orderItem = \Mockery::mock(\WC_Order_Item_Product::class, [
            'get_quantity' => $quantity,
            'get_product'  => $product,
            'get_name' => uniqid('product-item-name-'),
            'get_subtotal' => $subtotal = rand(0, 1000) / 100,
            'get_total_tax' => $subtotal / 10,
        ]);
        $orderItem->allows('get_quantity')->andReturn($quantity);
        $orderItem->allows('get_product')->andReturn($product);

        return $orderItem;
    }

    /**
     * @return \WC_Session&MockInterface
     */
    public function getWcSession():\WC_Session_Handler
    {
        if($this->session === null){
            $this->session = \Mockery::mock(implode(
                ', ',
                [
                    \WC_Session::class,
                    \WC_Session_Handler::class,
                ]
            ));
        }
        return $this->session;
    }


    public function prepareGateway(): void
    {

        $wc            = Mockery::mock(\WooCommerce::class);
        $wc->allows('initialize_session');
        when('wc')->justReturn($wc);
        $wcCountries   = new class {
            public function get_base_country(): string
            {
                return 'FR';
            }
        };
        $wc->countries = $wcCountries;
        $wcSession = $this->getWcSession();
        $wc->session = $wcSession;

        $cart = Mockery::mock(WC_Cart::class, ['get_total' => 1.0]);
        $wc->cart = $cart;

        $customer = Mockery::mock(WC_Customer::class, ['get_billing_country' => 'DE']);
        $wc->customer = $customer;

        $this->injectService('wc', static function () use ($wc){
            return $wc;
        });

        $this->injectService('wc.session', static function () use ($wcSession){
            return $wcSession;
        });

        $checkoutHashProvider = Mockery::mock(HashProviderInterface::class);
        $this->injectService(
            'inpsyde_payment_gateway.checkout_hash_provider',
            static function () use (
                $checkoutHashProvider
            ) {
                return $checkoutHashProvider;
            }
        );

        $this->order = $this->getOrder();

        expect('wc_get_order')
            ->with($this->order->get_id())
            ->andReturn($this->order);

        $merchantCode = uniqid('merchant-code-');
        $apiToken     = uniqid('api-token-');
        $this->injectService('inpsyde_payment_gateway.merchant_code', static function () use (
            $merchantCode
        ) {
            return $merchantCode;
        });
        $this->injectService('inpsyde_payment_gateway.merchant_token', static function () use (
            $apiToken
        ) {
            return $apiToken;
        });

        $this->injectService('wc.customer', static function ()use($customer): WC_Customer {
            return $customer;
        });

        $this->injectService('wc.currency', static function (): string {
            return 'EUR';
        });

        $this->injectService('wc.cart', static function ()use($cart): WC_Cart {
            return $cart;
        });

        $this->injectService('wp.is_frontend_request', static function (): bool {
            return true;
        });

        /**
         * @see PaymentGateway::process_admin_options()
         */
        $this->injectService(
            'inpsyde_payment_gateway.api_credentials_validator_callback',
            function () {
                return function () {
                    return null;
                };
            }
        );

        $checkoutHashProvider = $this->getCheckoutHashProvider();
        $this->injectService('inpsyde_payment_gateway.checkout_hash_provider', static function () use (
            $checkoutHashProvider
        ) {
            return $checkoutHashProvider;
        });

        $options = $this->getGatewayConfig();
        $this->injectService(
            'inpsyde_payment_gateway.options',
            function (ContainerInterface $container) use ($options): MapInterface {
                /**
                 * @var MapFactoryInterface $factory
                 */
                $factory = $container->get('core.data.structure_based_factory');

                return $factory->createContainerFromArray($options);
            }
        );
        $this->injectService(
            'inpsyde_payment_gateway.shop_url',
            function (ContainerInterface $container) {
                /**
                 * @var UriFactoryInterface $factory
                 */
                $factory = $container->get('inpsyde_payment_gateway.uri_factory');

                return $factory->createUri('https://payoneer.shop');
            }
        );

        parent::setUp();
    }

    protected function getCheckoutHashProvider(): HashProviderInterface {
        return Mockery::mock(HashProviderInterface::class, [
            'provideCheckoutHash' => uniqid('checkout-hash-'),
        ]);
    }

    protected function prepareRequest(array $data = []): array
    {
        $amount        = (float)rand(1, 10000) / 100;
        $longId        = uniqid('long-id-');
        $chargeId      = uniqid('charge-id-');
        $invoiceId     = uniqid('invoice-id-');
        $transactionId = uniqid('transaction-id-');
        $currency      = 'EUR';

        $templateRenderer = new RenderTemplateHelper('{{', '}}', '\\');
        $dataPath         = dirname(__FILE__, 4) . '/data/charge-response.json';
        $body             = array_merge([
            'identification.longId'        => $longId,
            'identification.transactionId' => $transactionId,
            'chargeId'                     => $chargeId,
            'payment.amount'               => $amount,
            'payment.currency'             => $currency,
            'payment.invoiceId'            => $invoiceId,
            'status.code'                  => 'success',
            'status.reason'                => 'Lorem ipsum',
        ], $data);
        $response         = $templateRenderer->renderFileWithContext(
            $dataPath,
            $body
        );

        $requestData = [
            'headers' => [],
            'cookies' => [],
            'body'    => $response,
        ];
        $wpHttp      = $this->getMockBuilder(WP_Http::class)
                            ->getMock();

        $wpHttp->method('request')
               ->willReturn(
                   $requestData
               );

        $this->injectService('wp.http.wp_http_object', function () use ($wpHttp) {
            return $wpHttp;
        });

        return $requestData;
    }

    /**
     * Creates a new merchants model with the specified merchants.
     *
     * @param MerchantInterface[] $merchants The list of merchants.
     *
     * @return MerchantQueryInterface&SaveMerchantCommandInterface&MockInterface The new model.
     */
    protected function createMerchantModel(array $merchants): MerchantQueryInterface
    {
        $mock = \Mockery::mock(ContainerMapMerchantModel::class, [
            'execute' => $merchants
        ]);
        $mock->allows('withId')->andReturn($mock);
        $mock->allows('saveMerchant')->andReturnUsing(
            function (MerchantInterface $merchant) use ($merchants) {
                return $merchant;
            }
        );
        return $mock;
    }


}
