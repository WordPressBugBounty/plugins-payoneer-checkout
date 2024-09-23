<?php

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\Settings;

use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentGateway;
use RangeException;

/**
 * @psalm-suppress MissingParamType
 */
interface SettingsFieldSanitizerInterface
{
    /**
     * @param string $key
     * @param mixed $value
     * @param PaymentGateway $gateway
     *
     * @return mixed
     * @throws RangeException
     */
    public function sanitize(string $key, $value, PaymentGateway $gateway);
}
