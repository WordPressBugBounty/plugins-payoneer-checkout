<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;

return
    /**
     * @return array<string, callable>
     * @psalm-return array<string, callable(ContainerInterface): mixed>
     */
    static function (): array {
        return [];
    };
