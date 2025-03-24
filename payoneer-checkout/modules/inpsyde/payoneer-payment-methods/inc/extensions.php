<?php

declare (strict_types=1);
namespace Syde\Vendor;

use Syde\Vendor\Psr\Container\ContainerInterface;
return static function (): array {
    return ['payment_gateways' => static function (array $gateways, ContainerInterface $container): array {
        return \array_merge($gateways, (array) $container->get('payment_methods.all'));
    }, 'payoneer_settings.settings_fields' => static function (array $existingFields): array {
        $paymentMethodsFields = require __DIR__ . '/fields.php';
        return \array_merge_recursive($existingFields, $paymentMethodsFields);
    }];
};
