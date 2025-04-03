<?php

declare (strict_types=1);
namespace Syde\Vendor;

use Syde\Vendor\Dhii\Services\Factory;
use Syde\Vendor\Dhii\Services\Factories\Value;
use Syde\Vendor\Dhii\Services\Factories\Constructor;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\WebSdk\Security\SdkIntegrityService;
return static function (): array {
    return ['websdk.assets.umd.url.template' => new Factory(['websdk.assets.js.suffix'], static function (string $jsSuffix) {
        return "https://resources.<env>.oscato.com/web/libraries/checkout-web/umd/checkout-web<version>" . $jsSuffix;
    }), 'websdk.assets.js.suffix' => new Factory(['wp.is_script_debug'], static function (bool $isScriptDebug): string {
        return $isScriptDebug ? '.js' : '.min.js';
    }), 'websdk.security.environment_version_map' => new Value(['sandbox' => '1.14.0', 'live' => '1.14.0']), 'websdk.security.integrity_hashes' => new Value(['1.14.0' => ['.js' => 'sha384-4U0ZFrafaj0LxeiUF1YgP0uhm4wi8q6S2hmVpduGutj8PFArn8rbm3EYhmwwPgpc', '.min.js' => 'sha384-s31eL4e9J9mZElaihKGnBZlwxtLk/Rt07nAgnJA6MCMMEt8VrHbaupZeVVughfP+']]), 'websdk.security.integrity' => new Constructor(SdkIntegrityService::class, ['websdk.assets.js.suffix', 'websdk.security.environment_version_map', 'websdk.security.integrity_hashes'])];
};
