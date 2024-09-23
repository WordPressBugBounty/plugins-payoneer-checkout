<?php

declare(strict_types=1);

namespace php\Integration\Gateway;

use Dhii\Services\Factories\Value;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\ListSession\WcBasedListSessionFactory;
use Inpsyde\PayoneerForWoocommerce\Checkout\HashProvider\HashProviderInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\ListSessionProvider;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentGateway;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Tests\Integration\Gateway\PaymentGatewayTestCase;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use Mockery;

use function Brain\Monkey\Functions\when;

class RenderPaymentFieldsTest extends PaymentGatewayTestCase
{
    public function testRenderedPaymentFieldsContainCheckoutHash()
    {
        when('is_checkout')->justReturn(true);
        when('wp_doing_ajax')->justReturn(true);
        when('is_checkout_pay_page')->justReturn(false);
        $list = Mockery::mock(ListInterface::class, [
            'getLinks' => ['self' => 'foo']
        ]);

        $listProvider = Mockery::mock(ListSessionProvider::class, [
            'provide' => $list
        ]);
        $this->injectService('checkout.list_session_provider', new Value($listProvider));
        $this->injectService('wc.is_fragment_update', new Value(true));

        $sessionKey = uniqid('session-key-');
        $this->injectService(
            'checkout.list_session_manager.cache_key',
            new Value($sessionKey)
        );

        $hash = uniqid('checkout-hash-');
        $checkoutHashProvider = Mockery::mock(HashProviderInterface::class, ['provideHash' => $hash]);
        $this->injectExtension('checkout.checkout_hash_provider', static function () use (
            $checkoutHashProvider
        ){
            return $checkoutHashProvider;
        });

        $this->prepareGateway();
        $container = $this->createPackage()->container();
        /** @var PaymentGateway $paymentGateway */
        $paymentGateway = $container->get('inpsyde_payment_gateway.gateway');
        /** @var string $listHashAttributeName */
        $listHashAttributeName = $container->get('inpsyde_payment_gateway.list_hash_container_id');
        $paymentGateway->payment_fields();

        $this->expectOutputRegex('~.*<script id="' . $listHashAttributeName . '".*>' . $hash . '<\/script>' . '.*~');
    }
}
