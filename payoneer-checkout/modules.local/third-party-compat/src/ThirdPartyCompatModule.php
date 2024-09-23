<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\ThirdPartyCompat;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Inpsyde\Modularity\Module\ExecutableModule;
use Inpsyde\Modularity\Module\ExtendingModule;
use Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Inpsyde\Modularity\Module\ServiceModule;
use Psr\Container\ContainerInterface;

class ThirdPartyCompatModule implements ExecutableModule, ServiceModule, ExtendingModule
{
    use ModuleClassNameIdTrait;

    /**
     * @inheritDoc
     */
    public function run(ContainerInterface $container): bool
    {
        //PN-381
        add_filter('sgo_js_minify_exclude', static function (array $scripts) {
            $scripts[] = 'op-payment-widget';
            $scripts[] = 'payoneer-checkout';
            return $scripts;
        });

        $mainPluginFile = (string)$container->get('core.main_plugin_file');

        /**
         * WooCommerce High Performance Order Storage
         * @psalm-suppress UnusedVariable
         */
        add_action('before_woocommerce_init', static function () use ($mainPluginFile) {
            if (!class_exists(FeaturesUtil::class)) {
                return;
            }
            FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                $mainPluginFile,
                true
            );
        });

        return true;
    }

    /**
     * @inheritDoc
     */
    public function services(): array
    {

        static $services;

        if ($services === null) {
            $services = require_once dirname(__DIR__) . '/inc/services.php';
        }

        /** @var callable(): array<string, callable(ContainerInterface $container):mixed> $services */
        return $services();
    }

    /**
     * @inheritDoc
     */
    public function extensions(): array
    {
        static $extensions;

        if ($extensions === null) {
            $extensions = require_once dirname(__DIR__) . '/inc/extensions.php';
        }

        /** @var callable(): array<string, callable(mixed $service, \Psr\Container\ContainerInterface $container):mixed> $extensions */
        return $extensions();
    }
}
