<?php

declare (strict_types=1);
namespace Syde\Vendor;

use Syde\Vendor\Dhii\Services\Factory;
return static fn() => ['wc.is_checkout' => new Factory(['wc'], static function (): bool {
    return is_checkout();
})];
