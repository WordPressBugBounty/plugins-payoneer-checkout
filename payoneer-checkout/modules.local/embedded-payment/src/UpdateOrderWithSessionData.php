<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\EmbeddedPayment;

use Inpsyde\PayoneerForWoocommerce\Checkout\CheckoutExceptionInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\ListSessionProvider;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\WcOrderListSessionPersistor;
use WC_Order;

/**
 * Copy plugin data from checkout session to the order.
 */
class UpdateOrderWithSessionData
{
    /**
     * @var ListSessionProvider
     */
    protected $listSessionProvider;
    /**
     * @var string
     */
    protected $transactionIdFieldName;
    /**
     * @var WcOrderListSessionPersistor
     */
    protected $listSessionPersistor;

    /**
     * @param ListSessionProvider $listSessionProvider
     * @param WcOrderListSessionPersistor $listSessionPersistor
     * @param string $transactionIdFieldName
     */
    public function __construct(
        ListSessionProvider $listSessionProvider,
        WcOrderListSessionPersistor $listSessionPersistor,
        string $transactionIdFieldName
    ) {

        $this->listSessionProvider = $listSessionProvider;
        $this->listSessionPersistor = $listSessionPersistor;
        $this->transactionIdFieldName = $transactionIdFieldName;
    }

    /**
     * @param WC_Order $order
     *
     * @return void
     * @throws CheckoutExceptionInterface|\WC_Data_Exception
     */
    public function __invoke(
        WC_Order $order
    ): void {

        $listSession = $this->listSessionProvider->provide();

        $this->listSessionPersistor->withOrder($order)->persist($listSession);

        $identification = $listSession->getIdentification();

        $order->update_meta_data(
            $this->transactionIdFieldName,
            $identification->getTransactionId()
        );
        $order->set_transaction_id($identification->getLongId());
        $order->save();
    }
}
