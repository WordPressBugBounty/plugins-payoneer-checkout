<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Checkout\TransactionIdGenerator;

/**
 * A service generating transaction id.
 */
interface TransactionIdGeneratorInterface
{
    public function generateTransactionId(): string;
}
