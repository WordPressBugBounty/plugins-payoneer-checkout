<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\HostedPayment;

use Dhii\Services\Factory;

return static function (): array {
    return [
        'hosted_payment.is_enabled' => new Factory([
            'checkout.selected_payment_flow',
        ], static function (
            string $configuredFlow
        ): bool {
            return $configuredFlow === 'hosted';
        }),
    ];
};
