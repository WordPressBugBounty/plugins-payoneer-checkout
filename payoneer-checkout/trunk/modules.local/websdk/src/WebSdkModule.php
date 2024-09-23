<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\WebSdk;

use Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Inpsyde\Modularity\Module\ServiceModule;

class WebSdkModule implements ServiceModule
{
    use ModuleClassNameIdTrait;

    /**
     * @inheritDoc
     */
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
