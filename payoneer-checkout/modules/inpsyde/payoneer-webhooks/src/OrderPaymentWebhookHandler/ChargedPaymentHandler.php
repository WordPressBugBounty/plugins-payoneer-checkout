<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Webhooks\OrderPaymentWebhookHandler;

use WC_Order;
use WP_REST_Request;
class ChargedPaymentHandler implements OrderPaymentWebhookHandlerInterface
{
    /**
     * An order field name where CHARGE ID should be saved.
     *
     * @var string
     */
    protected $chargeIdOrderFieldName;
    /**
     * @var string
     */
    protected $awaitingWebhookFieldName;
    public function __construct(string $chargeIdOrderFieldName, string $awaitingWebhookFieldName)
    {
        $this->chargeIdOrderFieldName = $chargeIdOrderFieldName;
        $this->awaitingWebhookFieldName = $awaitingWebhookFieldName;
    }
    /**
     * @inheritDoc
     */
    public function accepts(WP_REST_Request $request, WC_Order $order): bool
    {
        return (string) $request->get_param('statusCode') === 'charged' && (string) $request->get_param('reasonCode') === 'debited';
    }
    /**
     * Handle a notification about payment successfully charged.
     *
     * @param WP_REST_Request $request Incoming request.
     * @param WC_Order $order The order payment is completed for.
     */
    public function handlePayment(WP_REST_Request $request, WC_Order $order): void
    {
        /**
         * Before there was a check for correct data. Amount and currency from the request were
         * compared with the amount and currency from the order.
         *
         * This check was removed because when taxes are enabled and prices include tax, in the
         * request amount is set to netAmount, although from our side amount were different.
         * Most probably, this happens due to the not implemented tax calculation on the Payoneer
         * side. Until it works properly, we cannot return the amount and currency check.
         */
        $chargeId = (string) $request->get_param('longId');
        $order->update_meta_data($this->chargeIdOrderFieldName, $chargeId);
        /**
         * Webhook confirmed successful charge — payment_complete() transitions
         * to 'processing' or 'completed' based on product type.
         */
        if ($this->awaitingWebhookFieldName) {
            $order->delete_meta_data($this->awaitingWebhookFieldName);
        }
        $order->payment_complete();
        $notificationId = (string) $request->get_param('notificationId');
        $order->add_order_note(sprintf('Order marked as paid on incoming webhook. Notification ID is %1$s', $notificationId));
        $order->save();
    }
}
