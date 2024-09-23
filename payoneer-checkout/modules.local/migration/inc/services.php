<?php

declare(strict_types=1);

use Dhii\Services\Factories\Constructor;
use Dhii\Services\Factories\Value;
use Inpsyde\PayoneerForWoocommerce\Migration\Migrator;

return static function (): array {
    return [
        'migration.migrator' => new Constructor(
            Migrator::class,
            [
                'migration.payment_gateway',
                'migration.embedded_payment.custom_css.default',
            ]
        ),

        'migration.plugin_version_option_name' =>
            new Value('payoneer_plugin_version'),

    ];
};
