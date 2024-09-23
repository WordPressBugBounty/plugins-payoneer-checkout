<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Checkout\PaymentProcessor;

use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentGateway;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\MerchantInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\PaymentProcessor\PaymentProcessorInterface;
use WC_Order;

class MetadataSavingProcessorDecorator implements PaymentProcessorInterface
{
    /**
     * @var PaymentProcessorInterface
     */
    protected $inner;
    /**
     * @var PaymentGateway
     */
    protected $paymentGateway;
    /**
     * @var MerchantInterface
     */
    protected $merchant;
    /**
     * @var string
     */
    protected $merchantIdFieldName;
    /**
     * @var string
     */
    protected $securityHeaderFieldName;
    /**
     * @var string
     */
    protected $securityToken;

    public function __construct(
        PaymentProcessorInterface $inner,
        PaymentGateway $paymentGateway,
        MerchantInterface $merchant,
        string $merchantIdFieldName,
        string $securityHeaderFieldName,
        string $securityToken
    ) {

        $this->inner = $inner;
        $this->paymentGateway = $paymentGateway;
        $this->merchant = $merchant;
        $this->merchantIdFieldName = $merchantIdFieldName;
        $this->securityHeaderFieldName = $securityHeaderFieldName;
        $this->securityToken = $securityToken;
    }

    public function processPayment(WC_Order $order): array
    {
        $this->addMetaDataToOrder($order);

        return $this->inner->processPayment($order);
    }

    /**
     * Add meta fields to order.
     *
     * @param WC_Order $order Order to add meta fields to.
     */
    protected function addMetaDataToOrder(WC_Order $order): void
    {
        /**
         * Store Merchant ID
         */
        $merchantId = $this->merchant->getId();
        $order->update_meta_data($this->merchantIdFieldName, (string)$merchantId);

        /**
         * Store LIST security token (required for webhooks)
         */
        $order->update_meta_data(
            $this->securityHeaderFieldName,
            $this->securityToken
        );

        /**
         * Store transaction ID
         */
        $transactionUrlTemplate = $this->merchant->getTransactionUrlTemplate();
        $order->update_meta_data(
            $this->paymentGateway->getTransactionUrlFieldName(),
            $transactionUrlTemplate
        );

        $order->save();
    }
}
