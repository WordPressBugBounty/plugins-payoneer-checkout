<?php

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\Settings;

use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentGateway;

interface SettingsFieldRendererInterface
{
    public function render(string $fieldId, array $fieldConfig, PaymentGateway $gateway): string;
}
