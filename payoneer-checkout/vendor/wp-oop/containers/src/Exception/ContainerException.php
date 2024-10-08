<?php

declare (strict_types=1);
namespace Syde\Vendor\WpOop\Containers\Exception;

use Syde\Vendor\Psr\Container\ContainerExceptionInterface;
use Exception;
use Syde\Vendor\Psr\Container\ContainerInterface;
use Throwable;
/**
 * Basic implementation of container exception.
 *
 * @package WpOop\Containers
 */
class ContainerException extends Exception implements ContainerExceptionInterface
{
    /**
     * @var ContainerInterface|null
     */
    protected $container;
    /**
     * @param string $message The exception message.
     * @param int $code The exception code.
     * @param Throwable|null $previous The inner exception, if any,
     * @param ContainerInterface|null $container The container that caused the exception, if any,
     */
    public function __construct(string $message = "", int $code = 0, Throwable $previous = null, ContainerInterface $container = null)
    {
        parent::__construct($message, $code, $previous);
        $this->container = $container;
    }
    /**
     */
    public function getContainer(): ?ContainerInterface
    {
        return $this->container;
    }
}
