<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\PaymentMethods\PaymentProcessor;

use Syde\Vendor\Inpsyde\PaymentGateway\PaymentGateway;
use Syde\Vendor\Inpsyde\PaymentGateway\PaymentProcessorInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Api\Gateway\CommandFactory\WcOrderBasedUpdateCommandFactoryInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\Authentication\TokenGeneratorInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\CheckoutExceptionInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\MisconfigurationDetector\MisconfigurationDetectorInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionProvider;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\PaymentContext;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Settings\Merchant\MerchantInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\ApiExceptionInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\Exception\CommandExceptionInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\Exception\InteractionExceptionInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\ResponseValidator\InteractionCodeFailureInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\UpdateListCommandInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Address\AddressInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Customer\CustomerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use WC_Order;
/**
 * @psalm-type PaymentProcessingResult = array{result: 'success'|'failure', longId?: string, messages?: string, redirect?: string}
 */
class PayoneerCommonPaymentProcessor implements PaymentProcessorInterface
{
    private MisconfigurationDetectorInterface $misconfigurationDetector;
    private ListSessionProvider $sessionProvider;
    private WcOrderBasedUpdateCommandFactoryInterface $updateCommandFactory;
    private TokenGeneratorInterface $tokenGenerator;
    private string $tokenKey;
    private string $transactionIdFieldName;
    private string $sessionHashKey;
    private string $transactionUrlTemplateFieldName;
    private string $merchantIdFieldName;
    private MerchantInterface $merchant;
    // Replace with proper merchant interface/class
    private string $awaitingWebhookFieldName;
    private string $sessionLongIdKey;
    public function __construct(MisconfigurationDetectorInterface $misconfigurationDetector, ListSessionProvider $sessionProvider, WcOrderBasedUpdateCommandFactoryInterface $updateCommandFactory, TokenGeneratorInterface $tokenGenerator, string $tokenKey, string $transactionIdFieldName, string $sessionHashKey, string $transactionUrlTemplateFieldName, string $merchantIdFieldName, MerchantInterface $merchant, string $awaitingWebhookFieldName, string $sessionLongIdKey = '_payoneer-long-id')
    {
        $this->misconfigurationDetector = $misconfigurationDetector;
        $this->sessionProvider = $sessionProvider;
        $this->updateCommandFactory = $updateCommandFactory;
        $this->tokenGenerator = $tokenGenerator;
        $this->tokenKey = $tokenKey;
        $this->transactionIdFieldName = $transactionIdFieldName;
        $this->sessionHashKey = $sessionHashKey;
        $this->transactionUrlTemplateFieldName = $transactionUrlTemplateFieldName;
        $this->merchantIdFieldName = $merchantIdFieldName;
        $this->merchant = $merchant;
        $this->awaitingWebhookFieldName = $awaitingWebhookFieldName;
        $this->sessionLongIdKey = $sessionLongIdKey;
    }
    /**
     * @param WC_Order $order
     * @param PaymentGateway $gateway
     *
     * @psalm-return PaymentProcessingResult
     *
     * @throws ApiExceptionInterface
     * @throws CheckoutExceptionInterface
     * @throws CommandExceptionInterface
     * @throws \WC_Data_Exception
     */
    public function processPayment(WC_Order $order, PaymentGateway $gateway): array
    {
        $this->addMetaDataToOrder($order);
        /**
         * Add a unique token that will provide a little extra protection against
         * request forgery during webhook processing
         */
        $order->update_meta_data($this->tokenKey, $this->tokenGenerator->generateToken());
        $order->save();
        $list = $this->sessionProvider->provide(new PaymentContext($order));
        $this->updateOrderWithSessionData($order, $list);
        /**
         * The LIST longId is now persisted on the order (meta + transaction_id).
         * Clear the session copy so that if the customer abandons this payment
         * and retries checkout, the next order will get a fresh LIST instead of
         * reusing this one. Without this, the session longId leaks to new orders
         * via FetchingMiddleware's session fallback, causing duplicate orders
         * linked to the same LIST/transactionId.
         *
         * @see https://app.clickup.com/t/86e0euaqw
         */
        wc()->session->set($this->sessionLongIdKey, null);
        $updateCommand = $this->updateCommandFactory->createUpdateCommand($order, $list);
        $longId = $list->getIdentification()->getLongId();
        /**
         * Mark this LIST as claimed by an order. This prevents concurrent
         * UpdatingMiddleware requests (in separate PHP processes) from
         * overwriting the real order data with session-based dummy data.
         *
         * The transient persists for 5 minutes — long enough to cover
         * process_payment, the WebSDK charge, and webhook delivery.
         * After that it auto-expires; the LIST is no longer updatable anyway.
         *
         * @see UpdatingMiddleware::updateList()
         */
        $claimKey = 'payoneer_claimed_' . md5($longId);
        set_transient($claimKey, '1', 300);
        /**
         * Acquire an advisory lock to serialize LIST UPDATEs.
         * Timeout = 5: wait up to 5 seconds if UpdatingMiddleware currently
         * holds the lock (let it finish first, then send ours last).
         */
        global $wpdb;
        $lockName = 'payoneer_list_' . substr(md5($longId), 0, 20);
        $lockAcquired = (bool) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $lockName));
        try {
            $orderReference = sprintf('Order #%d', $order->get_id());
            do_action('payoneer-checkout.before_update_list', ['longId' => $longId, 'list' => $list, 'source' => 'process_payment', 'hasSecurityToken' => \true, 'reference' => $orderReference, 'orderId' => $order->get_id()]);
            // We have a requirement to log when the List country is not set or different from
            // a billing country.
            $this->validateUpdateCommandCountry($updateCommand);
            $list = $this->updateListSession($updateCommand);
            do_action('payoneer-checkout.list_session_updated', ['longId' => $longId, 'list' => $list, 'source' => 'process_payment', 'orderId' => $order->get_id()]);
        } finally {
            if ($lockAcquired) {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName));
            }
        }
        /**
         * This is a workaround for PN-951. If transaction was started, but not finished
         * (for example, when the page with 3DS popup was reloaded), we want to have checkout
         * hash reset to trigger List update on the next try.
         *
         * This may be removed when the proper error handling will be added to the WebSDK.
         * Now WebSDK just fails to retrieve the List from the API after the page reload and
         * nothing happens.
         */
        wc()->session->set($this->sessionHashKey, null);
        return [
            'result' => 'success',
            'redirect' => '',
            'longId' => $longId,
            /**
             * The custom attribute is recognized by our JS code as a signal that the payment is
             * not completed yet, and the "Pay" button shouldn't be unblocked.
             */
            'messages' => '<div data-payment-state="pending"></div>',
        ];
    }
    /**
     * Add meta fields to order.
     *
     * @param WC_Order $order Order to add meta fields to.
     */
    public function addMetaDataToOrder(WC_Order $order): void
    {
        /**
         * Store Merchant ID
         */
        $merchantId = $this->merchant->getId();
        $order->update_meta_data($this->merchantIdFieldName, (string) $merchantId);
        /**
         * Store transaction ID
         */
        $transactionUrlTemplate = $this->merchant->getTransactionUrlTemplate();
        $order->update_meta_data($this->transactionUrlTemplateFieldName, $transactionUrlTemplate);
        $order->save();
    }
    /**
     * @throws \WC_Data_Exception
     * @throws CheckoutExceptionInterface
     */
    public function updateOrderWithSessionData(WC_Order $order, ListInterface $list): void
    {
        $identification = $list->getIdentification();
        $transactionId = $identification->getTransactionId();
        $order->update_meta_data($this->transactionIdFieldName, $transactionId);
        $order->add_order_note(sprintf(
            /* translators: Transaction ID supplied by WooCommerce plugin */
            __('Initiating payment with transaction ID "%1$s"', 'payoneer-checkout'),
            $transactionId
        ));
        $order->set_transaction_id($identification->getLongId());
        $order->save();
    }
    /**
     * @param UpdateListCommandInterface $updateCommand
     *
     * @return ListInterface
     *
     * @throws CommandExceptionInterface If failed to update.
     */
    public function updateListSession(UpdateListCommandInterface $updateCommand): ListInterface
    {
        try {
            return $updateCommand->execute();
        } catch (CommandExceptionInterface $commandException) {
            do_action('payoneer_for_woocommerce.update_list_session_failed', ['exception' => $commandException]);
            throw $commandException;
        }
    }
    /**
     * Take actions on payment processing failed and return fields expected by WC Payment API.
     *
     * @param WC_Order $order
     * @param \Throwable|\WP_Error|string|null $error
     * phpcs:disable Inpsyde.CodeQuality.ArgumentTypeDeclaration.NoArgumentType
     *
     * @return array
     *
     * @psalm-return PaymentProcessingResult
     */
    public function handleFailedPaymentProcessing(WC_Order $order, $error = null): array
    {
        /**
         * Backend processing error — don't change status; the webhook will set
         * the final status, or the customer can retry from current 'pending' state.
         */
        $this->clearAwaitingWebhookFlag($order);
        $order->save();
        $fallback = __('The payment was not processed. Please try again.', 'payoneer-checkout');
        switch (\true) {
            case $error instanceof \Throwable:
                $error = $this->produceErrorMessageFromException($error, $fallback);
                break;
            case $error instanceof \WP_Error:
                $error = $error->get_error_message();
                break;
            case is_string($error):
                break;
            default:
                $error = $fallback;
        }
        wc_add_notice($error, 'error');
        do_action('payoneer-checkout.payment_processing_failure', ['order' => $order, 'errorMessage' => $error]);
        WC()->session->set('refresh_totals', \true);
        return ['result' => 'failure', 'redirect' => ''];
    }
    /**
     * @param \Throwable $exception
     * @param string $fallback
     *
     * @return string
     * phpcs:disable WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
     * phpcs:disable Inpsyde.CodeQuality.NestingLevel.High
     */
    public function produceErrorMessageFromException(\Throwable $exception, string $fallback): string
    {
        if ($this->misconfigurationDetector->isCausedByMisconfiguration($exception)) {
            /* translators: Used after checking for misconfigured merchant credentials, e.g. when we encounter 401/INVALID_CONFIGURATION*/
            return __('Failed to initialize payment session. Payoneer Checkout is not configured properly.', 'payoneer-checkout');
        }
        $previous = $exception;
        do {
            if ($previous instanceof InteractionCodeFailureInterface) {
                $response = $previous->getSubject();
                $body = $response->getBody();
                $body->rewind();
                $json = json_decode((string) $body, \true);
                if (!$json || !isset($json['resultInfo'])) {
                    return $fallback;
                }
                return (string) $json['resultInfo'];
            }
        } while ($previous = $previous->getPrevious());
        return $fallback;
    }
    /**
     * Inspect the exceptions to carry out appropriate actions based on the given interaction code
     *
     * @param WC_Order $order
     * @param InteractionExceptionInterface $exception
     *
     * @return array
     * @psalm-return PaymentProcessingResult
     */
    public function handleInteractionException(WC_Order $order, InteractionExceptionInterface $exception): array
    {
        do_action('payoneer-checkout.update_list_session_failed', ['exception' => $exception, 'order' => $order]);
        return $this->handleFailedPaymentProcessing($order, $exception);
    }
    public function validateUpdateCommandCountry(UpdateListCommandInterface $updateListCommand): void
    {
        $listCountry = $updateListCommand->getCountry();
        $customer = $updateListCommand->getCustomer();
        try {
            if ($listCountry && $customer instanceof CustomerInterface) {
                $billingAddress = $customer->getAddresses()['billing'] ?? null;
                if ($billingAddress instanceof AddressInterface) {
                    $countryValid = $listCountry === $billingAddress->getCountry();
                }
            }
        } catch (ApiExceptionInterface $exception) {
            //do nothing here.
        }
        if (!isset($countryValid) || !$countryValid) {
            do_action('payoneer_checkout.invalid_country_after_final_update', ['country' => $listCountry, 'longId' => $updateListCommand->getLongId(), 'customer' => $customer]);
        }
    }
    /**
     * Set the order to "pending" while awaiting asynchronous payment confirmation
     * and mark it with the awaiting_webhook flag to prevent double-payment.
     *
     * Previously this method set the order to "on-hold", but that status is commonly
     * associated with problematic orders and caused confusion for merchants.
     * Double-payment prevention is now handled by the _payoneer_awaiting_webhook
     * meta flag combined with a woocommerce_order_needs_payment filter, rather than
     * relying on the order status itself.
     *
     * This is the single place the awaiting_webhook flag is set. Both embedded and
     * hosted flows call this method after successful LIST creation/update, ensuring
     * the flag is always present when the order enters "awaiting payment" state.
     * The flag is cleared by webhook handlers (charged/failed) or on synchronous
     * payment failure redirects.
     *
     * @see self::clearAwaitingWebhookFlag()
     */
    public function putOrderAwaitingPayment(WC_Order $order, string $note): void
    {
        $order->update_meta_data($this->awaitingWebhookFieldName, 'yes');
        $order->update_status('pending', $note);
        $order->save();
    }
    /**
     * Remove the awaiting_webhook meta flag from the order.
     *
     * This is the symmetric counterpart to {@see self::putOrderAwaitingPayment()}.
     * It only deletes the meta key — callers must persist the order themselves
     * (via payment_complete(), update_status(), or save()) because the
     * appropriate persistence method varies by context.
     *
     * @param WC_Order $order The order to clear the flag from.
     */
    public function clearAwaitingWebhookFlag(WC_Order $order): void
    {
        if ($this->awaitingWebhookFieldName) {
            $order->delete_meta_data($this->awaitingWebhookFieldName);
        }
    }
}
