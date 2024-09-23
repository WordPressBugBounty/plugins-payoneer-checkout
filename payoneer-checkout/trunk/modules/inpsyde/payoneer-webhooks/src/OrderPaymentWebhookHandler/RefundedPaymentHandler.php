<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Webhooks\OrderPaymentWebhookHandler;

use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Webhooks\RefundFinder\RefundFinderInterface;
use WC_Order;
use WC_Order_Refund;
use WP_Error;
use WP_REST_Request;
class RefundedPaymentHandler implements OrderPaymentWebhookHandlerInterface
{
    /**
     * @var string
     */
    protected $payoutIdFieldName;
    /**
     * @var RefundFinderInterface
     */
    protected $refundFinder;
    /**
     * An order field name where CHARGE ID should be saved.
     *
     * @var string
     */
    protected $chargeIdOrderFieldName;
    public function __construct(string $chargeIdOrderFieldName, string $payoutIdFieldName, RefundFinderInterface $refundFinder)
    {
        $this->chargeIdOrderFieldName = $chargeIdOrderFieldName;
        $this->payoutIdFieldName = $payoutIdFieldName;
        $this->refundFinder = $refundFinder;
    }
    /**
     * @inheritDoc
     */
    public function accepts(WP_REST_Request $request, WC_Order $order): bool
    {
        return !$this->isChargeTransaction($request, $order) && $this->isSuccessfulPayout($request);
    }
    /**
     * Handle a notification about payment was refunded.
     */
    public function handlePayment(WP_REST_Request $request, WC_Order $order): void
    {
        $payoutLongId = (string) $request->get_param('longId');
        if ($this->isPayoutProcessed($payoutLongId)) {
            return;
        }
        $amount = (float) $request->get_param('amount');
        $currency = (string) $request->get_param('currency');
        /** @var string $orderTotalAmount */
        $orderTotalAmount = $order->get_total('edit');
        if ($amount > (float) $orderTotalAmount || $currency !== $order->get_currency()) {
            return;
        }
        $refundReason = sprintf(
            /* translators: %1$s is replaced with the actual notification ID. */
            __('Refunded automatically on incoming webhook. Notification id is %1$s.', 'payoneer-checkout'),
            (string) $request->get_param('notificationId')
        );
        $result = wc_create_refund(['amount' => $amount, 'reason' => $refundReason, 'order_id' => $order->get_id(), 'line_items' => $order->get_items(['line_item', 'fee', 'shipping']), 'refund_payment' => \false]);
        if ($result instanceof WP_Error) {
            $order->add_order_note(sprintf(
                /* translators: %1$s is replaced with the actual error message */
                __('Failed to create an order refund on incoming webhook. Error message: %1$s', 'payoneer-checkout'),
                $result->get_error_message()
            ));
            return;
        }
        $this->savePayoutId($result, $payoutLongId);
    }
    /**
     * If charge ID from the order is the same as notification longId, this is a
     * notification about updated CHARGE and NOT about a new PAYOUT.
     *
     * @param WP_REST_Request $request
     * @param WC_Order $wcOrder
     *
     * @return bool
     */
    protected function isChargeTransaction(WP_REST_Request $request, WC_Order $wcOrder): bool
    {
        $longId = (string) $request->get_param('longId');
        $chargeLongId = (string) $wcOrder->get_meta($this->chargeIdOrderFieldName);
        /**
         * We are currently not storing the chargeId for failed synchronous CHARGE calls
         * This leads to an erroneous detection here because there's no ID to compare against
         * As a workaround, we check if we're dealing with a failed payment here.
         */
        if (empty($chargeLongId) && $this->isPaymentFailed($request)) {
            return \true;
        }
        return $longId === $chargeLongId;
    }
    /**
     * Check whether incoming request is about payment failed.
     *
     * @param WP_REST_Request $request Incoming request.
     *
     * @return bool Whether payment was failed.
     */
    protected function isPaymentFailed(WP_REST_Request $request): bool
    {
        $status = (string) $request->get_param('statusCode');
        $failureStatuses = ['failed', 'canceled', 'declined', 'rejected', 'aborted'];
        return in_array($status, $failureStatuses, \true);
    }
    /**
     * Check whether incoming request is about payment paid out.
     *
     * @param WP_REST_Request $request Incoming request.
     *
     * @return bool Whether notification is about payout (refund) for order.
     */
    protected function isSuccessfulPayout(WP_REST_Request $request): bool
    {
        $statusCode = (string) $request->get_param('statusCode');
        $payoutStatuses = ['paid_out', 'paid_out_partial'];
        return in_array($statusCode, $payoutStatuses, \true);
    }
    /**
     * @param WC_Order_Refund $refund
     * @param string $payoutLongId
     *
     * @return void
     */
    protected function savePayoutId(WC_Order_Refund $refund, string $payoutLongId): void
    {
        $refund->add_meta_data($this->payoutIdFieldName, $payoutLongId);
        $refund->save();
    }
    /**
     * Check if this Payout operation was already processed.
     *
     * @param string $payoutLongId
     *
     * @return bool
     */
    protected function isPayoutProcessed(string $payoutLongId): bool
    {
        $found = $this->refundFinder->findRefundByPayoutLongId($payoutLongId);
        return $found !== null;
    }
}
