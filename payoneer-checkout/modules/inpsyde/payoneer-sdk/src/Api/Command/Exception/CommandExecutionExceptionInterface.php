<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\Exception;

use Syde\Vendor\Psr\Http\Message\RequestInterface;
use Syde\Vendor\Psr\Http\Message\ResponseInterface;
/**
 * A problem with a command execution.
 */
interface CommandExecutionExceptionInterface extends CommandExceptionInterface
{
    public function getRequest(): RequestInterface;
    public function getResponse(): ?ResponseInterface;
}
