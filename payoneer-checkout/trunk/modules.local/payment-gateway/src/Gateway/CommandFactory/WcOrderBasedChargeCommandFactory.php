<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\CommandFactory;

use Inpsyde\PayoneerForWoocommerce\Checkout\CheckoutExceptionInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\WcOrderListSessionProvider;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\Factory\Product\WcOrderBasedProductsFactoryInterface;
use Inpsyde\PayoneerSdk\Api\ApiExceptionInterface;
use Inpsyde\PayoneerSdk\Api\Command\ChargeCommandInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Payment\PaymentFactoryInterface;
use Psr\Http\Message\UriInterface;
use WC_Order;

/**
 * Service taking care about processing payment and updating an order.
 */
class WcOrderBasedChargeCommandFactory extends AbstractCommandFactory implements WcOrderBasedChargeCommandFactoryInterface
{
    /**
     * Command to make CHARGE request finalizing transaction.
     *
     * @var ChargeCommandInterface
     */
    protected $chargeCommand;

    /**
     * Service storing list session in WC_Order.
     *
     * @var WcOrderListSessionProvider
     */
    protected $listSessionProvider;
    /**
     * @var WcOrderBasedProductsFactoryInterface
     */
    protected $orderBasedProductsFactory;

    /**
     * @param ChargeCommandInterface $chargeCommand Command to make CHARGE request finalizing transaction.
     * @param PaymentFactoryInterface $paymentFactory Service able to create Payment instance.
     * @param WcOrderListSessionProvider $listSessionProvider Service storing list session in WC_Order.
     * @param WcOrderBasedProductsFactoryInterface $orderBasedProductsFactory
     * @param UriInterface $shopUrl
     */
    public function __construct(
        ChargeCommandInterface $chargeCommand,
        PaymentFactoryInterface $paymentFactory,
        WcOrderListSessionProvider $listSessionProvider,
        WcOrderBasedProductsFactoryInterface $orderBasedProductsFactory,
        UriInterface $shopUrl
    ) {

        parent::__construct($paymentFactory, $shopUrl);

        $this->chargeCommand = $chargeCommand;
        $this->listSessionProvider = $listSessionProvider;
        $this->orderBasedProductsFactory = $orderBasedProductsFactory;
    }

    /**
     * @inheritDoc
     */
    public function createChargeCommand(WC_Order $order): ChargeCommandInterface
    {
        try {
            $listSession = $this->listSessionProvider->withOrder($order)->provide();
        } catch (CheckoutExceptionInterface $exception) {
            throw new CommandFactoryException(
                sprintf(
                    'Failed to get LIST session from order. Exception caught: %1$s',
                    $exception->getMessage()
                )
            );
        }

        try {
            $payment = $this->preparePaymentForOrder($order);
        } catch (ApiExceptionInterface $exception) {
            throw new CommandFactoryException(
                sprintf(
                    'Failed to prepare payment request. Exception caught: %1$s',
                    $exception->getMessage()
                ),
                0,
                $exception
            );
        }

        $identification = $listSession->getIdentification();

        $products = $this->orderBasedProductsFactory
            ->createProductsFromWcOrder($order);

        return $this->chargeCommand
            ->withPayment($payment)
            ->withTransactionId($identification->getTransactionId())
            ->withLongId($identification->getLongId())
            ->withProducts($products);
    }
}
