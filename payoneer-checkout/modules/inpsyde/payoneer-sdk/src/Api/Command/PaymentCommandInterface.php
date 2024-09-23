<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command;

use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Payment\PaymentInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Product\ProductInterface;
/**
 * Represents a payment-related command.
 */
interface PaymentCommandInterface extends CommandInterface
{
    /**
     * Return new instance with provided payment.
     *
     * @param PaymentInterface $payment A payment to add to a new instance.
     *
     * @return static Created new instance.
     */
    public function withPayment(PaymentInterface $payment): self;
    /**
     * Return a new instance with provided products.
     *
     * @param ProductInterface[] $products
     *
     * @return static Created new instance.
     */
    public function withProducts(array $products): self;
}
