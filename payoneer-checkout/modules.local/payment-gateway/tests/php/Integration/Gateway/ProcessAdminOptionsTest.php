<?php

declare(strict_types=1);

namespace php\Integration\Gateway;

use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Tests\Integration\Gateway\PaymentGatewayTestCase;

use function Brain\Monkey\Filters\expectAdded;
use function Brain\Monkey\Functions\when;

class ProcessAdminOptionsTest extends PaymentGatewayTestCase
{
    public function testFilteringOutVirtualFields(): void
    {

        expectAdded('woocommerce_settings_api_sanitized_fields_payoneer-checkout');
        when('is_admin')->justReturn(true);
        when('is_checkout')->justReturn(false);
        when('is_checkout_pay_page')->justReturn(false);

        $this->prepareGateway();
        $package = $this->createPackage();
        $container = $package->container();

        $container->get('inpsyde_payment_gateway.gateway');
    }
}
