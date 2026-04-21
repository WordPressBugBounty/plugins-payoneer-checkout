<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command;

use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\Exception\CommandExceptionInterface;
/**
 * Fetches a charge by its longId and returns the status code.
 *
 * Unlike LIST commands, this operates on charge entities
 * (GET charges/{longId}) and returns a simple status string.
 */
interface FetchChargeCommandInterface
{
    /**
     * @param string $longId The charge longId.
     *
     * @return static
     */
    public function withLongId(string $longId): self;
    /**
     * Fetch the charge and return its status code (e.g. "charged").
     *
     * @return string The charge status code.
     *
     * @throws CommandExceptionInterface If the charge cannot be fetched.
     */
    public function execute(): string;
}
