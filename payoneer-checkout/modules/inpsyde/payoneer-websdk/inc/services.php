<?php

declare (strict_types=1);
namespace Syde\Vendor;

use Syde\Vendor\Dhii\Services\Factory;
return static function (): array {
    return ['websdk.assets.umd.url.template' => new Factory(['websdk.assets.js.suffix'], static function (string $jsSuffix) {
        return "https://resources.<env>.oscato.com/web/libraries/checkout-web/umd/checkout-web" . $jsSuffix;
    }), 'websdk.assets.js.suffix' => new Factory(['wp.is_script_debug'], static function (bool $isScriptDebug): string {
        return $isScriptDebug ? '.js' : '.min.js';
    })];
};
