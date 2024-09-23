<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Checkout\ListSession;

use Inpsyde\PayoneerForWoocommerce\Checkout\CheckoutException;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;

class ExpirationAwareWcOrderListSessionProvider extends WcOrderListSessionProvider
{
    public function provide(): ListInterface
    {
        $timestamp = (int)$this->ensureOrder()->get_meta($this->key . '_timestamp') ?: time();
        $delta = time() - $timestamp;
        if ($delta > MINUTE_IN_SECONDS * 30) {
            throw new CheckoutException(
                'Invalid List timestamp encountered.'
            );
        }
        return parent::provide();
    }
}
