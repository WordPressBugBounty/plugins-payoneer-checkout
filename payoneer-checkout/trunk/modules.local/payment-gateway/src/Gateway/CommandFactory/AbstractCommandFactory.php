<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\CommandFactory;

use Inpsyde\PayoneerSdk\Api\ApiExceptionInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Payment\PaymentFactoryInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Payment\PaymentInterface;
use Psr\Http\Message\UriInterface;
use WC_Order;

abstract class AbstractCommandFactory
{
    /**
     * @var PaymentFactoryInterface
     */
    protected $paymentFactory;

    /**
     * @var UriInterface
     */
    protected $shopUrl;

    /**
     * @param PaymentFactoryInterface $paymentFactory
     * @param UriInterface $shopUrl
     */
    public function __construct(
        PaymentFactoryInterface $paymentFactory,
        UriInterface $shopUrl
    ) {

        $this->paymentFactory = $paymentFactory;
        $this->shopUrl = $shopUrl;
    }

    /**
     * @param WC_Order $order
     *
     * @return PaymentInterface
     * @throws ApiExceptionInterface
     */
    protected function preparePaymentForOrder(WC_Order $order): PaymentInterface
    {
        $reference = $this->prepareOrderReference($order);

        /**
         * WooCommerce docblock says the return value of Order::get_total() is float,
         * but actually it's a string.
         *
         * @var string $amount
         */
        $amount = $order->get_total();

        $payment = $this->preparePayment(
            $reference,
            (float) $amount,
            $order->get_currency(),
            $order->get_order_number()
        );

        return $payment;
    }

    /**
     * Create a Payment instance.
     *
     * @throws ApiExceptionInterface
     */
    protected function preparePayment(
        string $reference,
        float $total,
        string $currency,
        string $invoiceId
    ): PaymentInterface {

        return $this->paymentFactory->createPayment($reference, $total, $currency, $invoiceId);
    }

    /**
     * Prepare a short order description.
     *
     * @param WC_Order $order
     *
     * @return string
     */
    protected function prepareOrderReference(WC_Order $order): string
    {

        return sprintf(
            'Order #%1$s from %2$s.',
            $order->get_order_number(),
            (string) $this->shopUrl
        );
    }
}
