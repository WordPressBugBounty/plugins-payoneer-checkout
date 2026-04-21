<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Webhooks\OrderPaymentWebhookHandler;

use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\RefundContext;
use WC_Order;
use WP_REST_Request;
class FailedPaymentHandler implements OrderPaymentWebhookHandlerInterface
{
    /**
     * @var string
     */
    protected $awaitingWebhookFieldName;
    public function __construct(string $awaitingWebhookFieldName)
    {
        $this->awaitingWebhookFieldName = $awaitingWebhookFieldName;
    }
    /**
     * @inheritDoc
     */
    public function accepts(WP_REST_Request $request, WC_Order $order): bool
    {
        $refundContext = RefundContext::fromRestRequest($request);
        if ($refundContext->hasRefundReason()) {
            // Ignore "failed refund" notifications.
            return \false;
        }
        $status = (string) $request->get_param('statusCode');
        $failureStatuses = ['failed', 'canceled', 'declined', 'rejected', 'aborted'];
        return in_array($status, $failureStatuses, \true);
    }
    /**
     * Handle a notification about payment failed.
     *
     * @param WP_REST_Request $request Incoming request.
     * @param WC_Order $order The order payment is failed for.
     */
    public function handlePayment(WP_REST_Request $request, WC_Order $order): void
    {
        $notificationId = (string) $request->get_param('notificationId');
        $resultInfo = (string) $request->get_param('resultInfo');
        do_action('payoneer-checkout.payment_processing_failure', ['errorMessage' => $resultInfo, 'orderId' => $order->get_id(), 'longId' => (string) $request->get_param('longId'), 'network' => (string) $request->get_param('network')]);
        $order->add_order_note(sprintf('Failure message is %1$s. Notification ID is %2$s', $resultInfo, $notificationId));
        /**
         * Webhook confirmed payment failure — set 'failed' so the customer can
         * retry. Skipped if already failed/cancelled (e.g. sync redirect arrived first).
         */
        if ($this->awaitingWebhookFieldName) {
            $order->delete_meta_data($this->awaitingWebhookFieldName);
        }
        /**
         * The order could already be failed.
         * Or if a redirect to the cancelUrl has already taken place, it could
         * be cancelled as well
         */
        if (!$order->has_status(['failed', 'cancelled'])) {
            $order->update_status('failed');
        }
        $order->save();
    }
}
