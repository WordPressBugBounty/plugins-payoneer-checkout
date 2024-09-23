<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession;

/**
 * Describes an "order-based" context.
 * Usually, this applies to payments to be done on the pay-for-order page.
 * However, since LISTs are transferred to orders during checkout, you will also see this context
 * in use during regular checkout.
 */
class PaymentContext extends AbstractContext
{
    /**
     * @var \WC_Order
     */
    private $order;
    public function __construct(\WC_Order $order)
    {
        $this->order = $order;
    }
    public function getOrder(): \WC_Order
    {
        return $this->order;
    }
}
