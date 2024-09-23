<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\AssetCustomizer;

use Inpsyde\Modularity\Module\ServiceModule;
use Psr\Container\ContainerInterface;

/**
 * The Asset Customizer module.
 */
class AssetCustomizerModule implements ServiceModule
{
    /**
     * @var array<string, callable>
     * @psalm-var array<string, callable(ContainerInterface): mixed>
     */
    protected $services;

    public function __construct()
    {

        $moduleRootDir = dirname(__FILE__, 2);
        $this->services = (require "$moduleRootDir/inc/services.php")();
    }

    /**
     * @inheritDoc
     */
    public function id(): string
    {
        return 'payoneer-asset-customizer';
    }

    /**
     * @inheritDoc
     */
    public function services(): array
    {
        return $this->services;
    }
}
