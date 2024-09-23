<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Checkout\Tests\Integration;

use Inpsyde\PayoneerForWoocommerce\Checkout\CheckoutModule;
use Inpsyde\PayoneerForWoocommerce\Tests\Integration\AbstractApplicationTestCase;

use function Brain\Monkey\Actions\expectAdded;
use function Brain\Monkey\Actions\expectDone;

class CheckoutModuleTest extends AbstractApplicationTestCase
{

    public function testRun()
    {
        $package = $this->createPackage();
        $this->assertFalse(
            $package->moduleIs(CheckoutModule::class, 'executed-failed'),
            'Module should execute without error'
        );

        $this->assertTrue(
            is_int(
                has_action('woocommerce_init', 'static function ()')
            ),
            'Checkout assets are not registered.'
        );
    }

    public function testActionsNotAddedIfGatewayDisabled()
    {

        $this->injectService('checkout.payment_gateway.is_enabled', '__return_false');
        expectAdded('woocommerce_init')->whenHappen(function (callable $callback) {
            $callback();
        });

        expectDone('payoneer-checkout.init_checkout')
            ->never();

        $package = $this->createPackage();
        $this->assertFalse(
            $package->moduleIs(CheckoutModule::class, 'executed-failed'),
            'Module should execute without error'
        );
    }
}
