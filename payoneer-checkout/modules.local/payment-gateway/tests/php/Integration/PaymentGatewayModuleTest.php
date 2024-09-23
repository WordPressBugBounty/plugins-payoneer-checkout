<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Tests\Integration;

use Inpsyde\PayoneerForWoocommerce\PaymentGateway\PaymentGatewayModule;
use Inpsyde\PayoneerForWoocommerce\Tests\Integration\AbstractApplicationTestCase;

use function Brain\Monkey\Actions\expectAdded;

class PaymentGatewayModuleTest extends AbstractApplicationTestCase
{

    public function testRun(): void
    {
        $package = $this->createPackage();
        $this->assertFalse(
            $package->moduleIs(PaymentGatewayModule::class, 'executed-failed'),
            'Module should execute without error'
        );
    }


    public function testExcludedCountries()
    {
        $called = false;
        $this->injectExtension(
            'inpsyde_payment_gateway.exclude_not_supported_countries',
            function (callable $prev) use (&$called) {
                return function () use (&$called, $prev) {
                    $prev();
                    $called = true;
                };
            }
        );
        expectAdded('woocommerce_init')->whenHappen(function (callable $onWooCommerceInit) {
            $onWooCommerceInit();
        });
        $this->createPackage();
        $this->assertTrue((bool)$called, 'Excluded countries should be registered');
    }
}
