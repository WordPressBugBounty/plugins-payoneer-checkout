<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Webhooks\OrderFinder;

use WC_Order;

class OrderFinder implements OrderFinderInterface
{
    /**
     * @var string
     */
    protected $transactionIdOrderFieldName;

    /**
     * @param string $transactionIdOrderFieldName
     */
    public function __construct(
        string $transactionIdOrderFieldName
    ) {

        $this->transactionIdOrderFieldName = $transactionIdOrderFieldName;
    }

    /**
     * @inheritDoc
     */
    public function findOrderByTransactionId(string $transactionId): ?WC_Order
    {
        /** @var WC_Order[] $orders */
        $orders = wc_get_orders(
            [
                'limit' => 1,
                $this->transactionIdOrderFieldName => $transactionId,
            ]
        );

        return $orders[0] ?? null;
    }
}
