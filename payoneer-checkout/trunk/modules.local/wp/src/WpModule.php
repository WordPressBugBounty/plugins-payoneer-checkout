<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Wp;

use Inpsyde\Modularity\Module\ServiceModule;
use Psr\Container\ContainerInterface;

/**
 * The WP core features module.
 */
class WpModule implements ServiceModule
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
        return 'payoneer-wp';
    }

    /**
     * @inheritDoc
     */
    public function services(): array
    {
        return $this->services;
    }
}
