<?php

declare (strict_types=1);
namespace Syde\Vendor;

use Syde\Vendor\Inpsyde\Modularity\Module\Module;
use Syde\Vendor\Inpsyde\Modularity\Package;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Core\PayoneerProperties;
return static function (string $mainPluginFile, callable $onError, Module ...$modules): Package {
    $autoload = \dirname($mainPluginFile) . '/vendor/autoload.php';
    if (\is_readable($autoload)) {
        include_once $autoload;
    }
    $properties = PayoneerProperties::new($mainPluginFile);
    $package = Package::new($properties);
    \add_action($package->hookName(Package::ACTION_FAILED_BOOT), $onError);
    \load_plugin_textdomain('payoneer-checkout');
    $package->boot(...$modules);
    return $package;
};
