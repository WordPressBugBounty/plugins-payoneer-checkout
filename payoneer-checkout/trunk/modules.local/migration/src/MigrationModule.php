<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Migration;

use Exception;
use Inpsyde\Modularity\Module\ExecutableModule;
use Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Inpsyde\Modularity\Module\ServiceModule;
use Inpsyde\Modularity\Package;
use Inpsyde\Modularity\Properties\PluginProperties;
use Psr\Container\ContainerInterface;

class MigrationModule implements ExecutableModule, ServiceModule
{
    use ModuleClassNameIdTrait;

    /**
     * @throws Exception
     */
    public function run(ContainerInterface $container): bool
    {

        $callback = static function () use ($container): void {
            /** @var PluginProperties $properties */
            $properties = $container->get(Package::PROPERTIES);

            $pluginVersion = $properties->version();

            if (! version_compare($pluginVersion, '0.0.1', '>=')) {
                return; //this is a development version.
            }

            $pluginVersionOptionName = (string) $container->get('migration.plugin_version_option_name');

            $dbPluginVersion = (string) get_option($pluginVersionOptionName);

            if (version_compare($pluginVersion, $dbPluginVersion, '>')) {
                /** @var MigratorInterface $migrator */
                $migrator = $container->get('migration.migrator');
                $migrator->migrate();
                update_option($pluginVersionOptionName, $pluginVersion);
            }
        };

        add_action('init', $callback);

        return true;
    }

    public function services(): array
    {
        static $services;

        if ($services === null) {
            $services = require_once dirname(__DIR__) . '/inc/services.php';
        }

        /** @var callable(): array<string, callable(\Psr\Container\ContainerInterface $container):mixed> $services */
        return $services();
    }
}
