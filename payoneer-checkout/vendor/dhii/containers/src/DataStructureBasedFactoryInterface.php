<?php

declare (strict_types=1);
namespace Syde\Vendor\Dhii\Container;

use Syde\Vendor\Dhii\Collection\WritableMapFactoryInterface;
use Syde\Vendor\Dhii\Collection\WritableMapInterface;
use Exception;
use Syde\Vendor\Psr\Container\ContainerInterface as BaseContainerInterface;
/**
 * Creates a container hierarchy based on a traditional data structure.
 */
interface DataStructureBasedFactoryInterface extends WritableMapFactoryInterface
{
    /**
     * Based on a traditional data structure, creates a container hierarchy.
     *
     * @param mixed[] $structure The traditional data structure representation.
     *
     * @return WritableMapInterface A hierarchy of writable maps that reflects the data structure.
     *
     * @throws Exception If problem creating.
     */
    public function createContainerFromArray(array $structure): BaseContainerInterface;
}
