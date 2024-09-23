<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Checkout;

class TransactionIdGenerator implements TransactionIdGeneratorInterface
{
    public function generateTransactionId(): string
    {
        return sprintf(
            'tr-%1$s-%2$s',
            wp_generate_password(6, false),
            time()
        );
    }
}
