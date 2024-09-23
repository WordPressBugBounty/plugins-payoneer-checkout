<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Exception;

use Exception;

/**
 * Higher-level module exception.
 */
class PaymentGatewayException extends Exception implements PaymentGatewayExceptionInterface
{
}
