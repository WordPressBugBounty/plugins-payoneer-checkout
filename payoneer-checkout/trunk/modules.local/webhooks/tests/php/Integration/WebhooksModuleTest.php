<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Webhooks\Tests\Integration;

use Inpsyde\PayoneerForWoocommerce\Tests\Integration\AbstractApplicationTestCase;
use Inpsyde\PayoneerForWoocommerce\Webhooks\WebhooksModule;

class WebhooksModuleTest extends AbstractApplicationTestCase
{
    public function testRun()
    {
        $package = $this->createPackage();

        $this->assertTrue(
            is_int(has_action('rest_api_init', 'static function ()')),
            'Rest route is not registered.'
        );

        $this->assertFalse(
            $package->moduleIs(WebhooksModule::class, 'executed-failed'),
            'Module should execute without error'
        );
    }
}
