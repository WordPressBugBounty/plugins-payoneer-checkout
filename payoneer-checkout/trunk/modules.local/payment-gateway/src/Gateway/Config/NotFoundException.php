<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\Config;

use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Exception\PaymentGatewayException;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Should be thrown when key not found in container.
 */
class NotFoundException extends PaymentGatewayException implements NotFoundExceptionInterface
{
}
