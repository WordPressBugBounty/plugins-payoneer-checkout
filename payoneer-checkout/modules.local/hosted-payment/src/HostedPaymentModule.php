<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\HostedPayment;

use Inpsyde\Modularity\Module\ExtendingModule;
use Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Inpsyde\Modularity\Module\ServiceModule;
use Psr\Container\ContainerInterface;

class HostedPaymentModule implements ServiceModule, ExtendingModule
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
