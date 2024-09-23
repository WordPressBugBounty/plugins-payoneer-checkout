<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\Tests\Integration;

use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\WcSessionListSessionManager;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentGateway;
use Inpsyde\PayoneerForWoocommerce\Tests\Integration\AbstractApplicationTestCase;
use Inpsyde\PayoneerSdk\Api\Entities\Identification\IdentificationInterface;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListSerializerInterface;

use function Brain\Monkey\Actions\expectAdded;
use function Brain\Monkey\Functions\when;

class EmbeddedPaymentModuleTest extends AbstractApplicationTestCase
{
    public function testUpdateOrderOnCheckout()
    {
        expectAdded('payoneer-checkout.init_checkout')->
        whenHappen(static function (callable $callback){
            $callback();
        });

        [$onCheckout, $order] = $this->prepareOrderUpdateTest(
            'woocommerce_checkout_order_processed'
        );

        when('determine_locale')->justReturn('en_US');
        do_action('woocommerce_init');
        foreach ($onCheckout as $callback) {
            $callback(0, [], $order);
        }
    }

    public function testUpdateOrderOnPayForOrder()
    {
        expectAdded('payoneer-checkout.init_checkout')->
        whenHappen(static function (callable $callback){
            $callback();
        });

        [$onCheckout, $order] = $this->prepareOrderUpdateTest(
            'woocommerce_before_pay_action'
        );
        do_action('woocommerce_init');
        when('is_checkout_pay_page')->justReturn(true);
        when('determine_locale')->justReturn('en_US');
        when('get_query_var')->justReturn('foo');
        foreach ($onCheckout as $callback) {
            $callback($order);
        }
    }

    protected function prepareOrderUpdateTest(string $hookName):array{
        /**
         * Fire 'woocommerce_init' immediately, so all expectations below are working
         */
        expectAdded('woocommerce_init')->whenHappen(
            function (\Closure $callback) {
                $callback();
            }
        );

        $gateway = \Mockery::mock(PaymentGateway::class, ['is_available' => true]);

        $this->injectService('checkout.payment_gateway', static function () use ($gateway) {
            return $gateway;
        });


        $list = \Mockery::mock(ListInterface::class, [
            'getIdentification' => \Mockery::mock(
                IdentificationInterface::class,
                [
                    'getTransactionId' => uniqid(),
                    'getLongId' => uniqid(),
                ]
            )
        ]);
        $listSessionManager = \Mockery::mock(WcSessionListSessionManager::class, [
            'provide'=>$list
        ]);
        $listSessionManager->allows('clear');
        $this->injectService(
            'checkout.list_session_manager',
            function () use ($listSessionManager) {
                return $listSessionManager;
            }
        );

        $listSerializer =\Mockery::mock(ListSerializerInterface::class);
        $listSerializer->expects('serializeListSession')->once()->with($list);
        $this->injectService('core.list_serializer',function ()use($listSerializer){
            return $listSerializer;
        });

        $onCheckout=[];
        expectAdded($hookName)->whenHappen(
            function (callable $callback) use (&$onCheckout) {
                $onCheckout[] = $callback;
            }
        );

        when('is_admin')->justReturn(false);
        when('get_permalink')->justReturn('');
        when('wc_get_page_id')->justReturn(rand(0, 100));
        $this->createPackage();
        $order = \Mockery::mock(\WC_Order::class);
        $order->allows('get_payment_method')->andReturn('payoneer-checkout');
        $order->expects('update_meta_data')->times(2);
        $order->expects('set_transaction_id');
        $order->expects('save')->times(2);

        do_action($hookName);
        return [$onCheckout,$order];
    }
}
