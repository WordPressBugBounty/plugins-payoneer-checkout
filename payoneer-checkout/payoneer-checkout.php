<?php

/**
 * Plugin Name: Payoneer Checkout
 * Description: Payoneer Checkout for WooCommerce
 * Version: 3.5.6
 * Author:      Payoneer
 * Requires at least: 6.5
 * Tested up to: 6.9
 * WC requires at least: 8.0
 * WC tested up to: 10.5
 * Requires PHP: 7.4
 * Author URI:  https://www.payoneer.com/
 * License:     MPL-2.0
 * Text Domain: payoneer-checkout
 * Domain Path: /languages
 * SHA: d38e1885c5d36224df012ae61bc8ef3ba44718a0
 */
declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce;

use Syde\Vendor\Inpsyde\Modularity\Package;
if (is_readable(dirname(__FILE__) . '/vendor/autoload.php')) {
    include_once dirname(__FILE__) . '/vendor/autoload.php';
}
/**
 * Provide the plugin instance.
 *
 * @return Package
 *
 * @link https://github.com/inpsyde/modularity#access-from-external
 */
function plugin(): Package
{
    static $package;
    if (!$package) {
        /** @var callable $bootstrap */
        $bootstrap = require __DIR__ . '/inc/bootstrap.php';
        $onError = require __DIR__ . '/inc/error.php';
        $modules = (require __DIR__ . '/inc/modules.php')();
        $modules = apply_filters('payoneer-checkout.modules_list', $modules);
        $package = $bootstrap(__FILE__, $onError, ...$modules);
    }
    /** @var Package $package */
    return $package;
}
add_action('plugins_loaded', 'Syde\Vendor\Inpsyde\PayoneerForWoocommerce\plugin');
register_activation_hook(__FILE__, static function (): void {
    add_option('payoneer-checkout_plugin_activated', 1);
});
