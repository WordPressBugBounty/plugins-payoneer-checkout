<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\PaymentMethods;

use Exception;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionProvider;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\PaymentContext;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\ApiExceptionInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\PayoutCommandInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Payment\PaymentFactoryInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\PayoneerInterface;
use InvalidArgumentException;
use RuntimeException;
use WC_Order;
use Syde\Vendor\Inpsyde\PaymentGateway\RefundProcessorInterface;
use WC_Order_Refund;
class RefundProcessor implements RefundProcessorInterface
{
    /**
     * @var ListSessionProvider
     */
    protected $listSessionProvider;
    /**
     * @var PaymentFactoryInterface
     */
    protected $paymentFactory;
    /**
     * @var PayoneerInterface
     */
    protected $payoneer;
    /**
     * @var string
     */
    protected $chargeIdFieldName;
    protected string $payoutIdFieldName;
    protected string $refundReasonSuffixTemplate;
    protected array $payoneerPaymentGatewaysIds;
    /**
     * @param PayoneerInterface $payoneer
     * @param ListSessionProvider $listSessionProvider
     * @param PaymentFactoryInterface $paymentFactory
     * @param string $chargeIdFieldName
     * @param string $payoutIdFieldName
     * @param string $refundReasonSuffixTemplate
     */
    public function __construct(PayoneerInterface $payoneer, ListSessionProvider $listSessionProvider, PaymentFactoryInterface $paymentFactory, string $chargeIdFieldName, string $payoutIdFieldName, string $refundReasonSuffixTemplate, array $payoneerPaymentGatewaysIds)
    {
        $this->payoneer = $payoneer;
        $this->listSessionProvider = $listSessionProvider;
        $this->paymentFactory = $paymentFactory;
        $this->chargeIdFieldName = $chargeIdFieldName;
        $this->payoutIdFieldName = $payoutIdFieldName;
        $this->refundReasonSuffixTemplate = $refundReasonSuffixTemplate;
        $this->payoneerPaymentGatewaysIds = $payoneerPaymentGatewaysIds;
    }
    /**
     * @inheritDoc
     */
    public function refundOrderPayment(WC_Order $order, float $amount, string $reason): void
    {
        //API requires non-empty reason
        $reason = $reason !== '' ? $reason : 'No refund reason provided.';
        $payoutCommand = $this->configurePayoutCommand($order, $amount, $reason);
        try {
            $list = $payoutCommand->execute();
            $payoutLongId = $list->getIdentification()->getLongId();
            $this->setupSavingPayoutData($payoutLongId);
        } catch (ApiExceptionInterface $exception) {
            throw new Exception('Failed to refund order payment.', 0, $exception);
        }
    }
    /**
     * @param WC_Order $order
     * @param float $amount
     * @param string $reason
     *
     * @return PayoutCommandInterface
     *
     * @throws InvalidArgumentException If provided order has no associated LIST session.
     * @throws RuntimeException
     */
    protected function configurePayoutCommand(WC_Order $order, float $amount, string $reason): PayoutCommandInterface
    {
        try {
            $listSession = $this->listSessionProvider->provide(new PaymentContext($order));
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException('Failed to process refund: order has no associated LIST session.', 0, $exception);
        }
        $transactionId = $listSession->getIdentification()->getTransactionId();
        try {
            $payment = $this->paymentFactory->createPayment($reason, $amount, 0, $amount, $order->get_currency(), $order->get_order_number());
        } catch (ApiExceptionInterface $exception) {
            throw new RuntimeException('Failed to process refund.', 0, $exception);
        }
        $chargeId = $order->get_meta($this->chargeIdFieldName, \true);
        if (!$chargeId) {
            throw new InvalidArgumentException('Failed to process refund: order has no associated charge ID');
        }
        $payoutCommand = $this->payoneer->getPayoutCommand();
        return $payoutCommand->withLongId((string) $chargeId)->withTransactionId($transactionId)->withPayment($payment);
    }
    /**
     * Save Payout longId when WC_Order_Refund object is created.
     *
     * @param string $payoutLongId
     *
     * @return void
     */
    protected function setupSavingPayoutData(string $payoutLongId): void
    {
        add_action('woocommerce_after_order_refund_object_save', function (WC_Order_Refund $refund) use ($payoutLongId): void {
            if (!$this->isRefundOrderPaidWithPayoneer($refund)) {
                return;
            }
            if ($refund->get_meta($this->payoutIdFieldName)) {
                return;
            }
            if (!$payoutLongId) {
                return;
            }
            $refundReasonSuffix = sprintf($this->refundReasonSuffixTemplate, $payoutLongId);
            $refundReason = sprintf('%1$s%2$s', $refund->get_reason(), $refundReasonSuffix);
            $refund->set_reason($refundReason);
            $refund->add_meta_data($this->payoutIdFieldName, $payoutLongId);
            $refund->save();
        });
    }
    /**
     * Check if the order the given refund is for was paid via this payment gateway.
     *
     * @param WC_Order_Refund $refund Refund to check parent order payment method.
     *
     * @return bool
     */
    protected function isRefundOrderPaidWithPayoneer(WC_Order_Refund $refund): bool
    {
        $parentOrderId = $refund->get_parent_id();
        $parentOrder = wc_get_order($parentOrderId);
        return $parentOrder instanceof WC_Order && in_array($parentOrder->get_payment_method(), $this->payoneerPaymentGatewaysIds, \true);
    }
}
