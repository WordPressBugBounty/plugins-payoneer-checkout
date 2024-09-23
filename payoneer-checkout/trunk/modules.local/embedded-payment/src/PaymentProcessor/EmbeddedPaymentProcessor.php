<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentProcessor;

use Automattic\WooCommerce\Blocks\Payments\PaymentResult;
use Exception;
use Inpsyde\PayoneerForWoocommerce\Checkout\CheckoutExceptionInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\OrderAwareListSessionPersistor;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\OrderAwareListSessionProvider;
use Inpsyde\PayoneerForWoocommerce\Checkout\MisconfigurationDetector\MisconfigurationDetectorInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\PaymentProcessor\AbstractPaymentProcessor;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\CommandFactory\WcOrderBasedUpdateCommandFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentGateway;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\PaymentProcessor\PaymentProcessorInterface;
use Inpsyde\PayoneerSdk\Api\Command\Exception\CommandExceptionInterface;
use Inpsyde\PayoneerSdk\Api\Command\Exception\InteractionExceptionInterface;
use Inpsyde\PayoneerSdk\Api\Command\UpdateListCommandInterface;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use WC_Order;

/**
 * @psalm-import-type PaymentResult from PaymentProcessorInterface
 */
class EmbeddedPaymentProcessor extends AbstractPaymentProcessor
{
    /**
     * @var PaymentGateway
     */
    protected $paymentGateway;
    /**
     * @var WcOrderBasedUpdateCommandFactoryInterface
     */
    protected $updateCommandFactory;
    /**
     * @var OrderAwareListSessionProvider
     */
    protected $sessionProvider;
    /**
     * @var OrderAwareListSessionPersistor
     */
    protected $sessionPersistor;
    /**
     * @var string
     */
    protected $chargeIdFieldName;
    /**
     * @var string
     */
    protected $transactionIdFieldName;
    /**
     * @var string
     */
    protected $hostedModeOverrideFlag;

    public function __construct(
        PaymentGateway $paymentGateway,
        WcOrderBasedUpdateCommandFactoryInterface $updateCommandFactory,
        OrderAwareListSessionProvider $sessionProvider,
        OrderAwareListSessionPersistor $sessionPersistor,
        string $chargeIdFieldName,
        string $transactionIdFieldName,
        string $hostedModeOverrideFlag,
        MisconfigurationDetectorInterface $misconfigurationDetector
    ) {

        parent::__construct($misconfigurationDetector);

        $this->paymentGateway = $paymentGateway;
        $this->updateCommandFactory = $updateCommandFactory;
        $this->sessionProvider = $sessionProvider;
        $this->sessionPersistor = $sessionPersistor;
        $this->chargeIdFieldName = $chargeIdFieldName;
        $this->transactionIdFieldName = $transactionIdFieldName;
        $this->hostedModeOverrideFlag = $hostedModeOverrideFlag;
    }

    public function processPayment(WC_Order $order): array
    {
        $list = $this->sessionProvider->withOrder($order)->provide();
        $this->updateOrderWithSessionData($order, $list);
        $updateCommand = $this->updateCommandFactory->createUpdateCommand($order, $list);

        /**
         * Attempt the update call via Payoneer SDK
         */
        try {
            $this->updateListSession($updateCommand);
        } catch (InteractionExceptionInterface $exception) {
            return $this->handleInteractionException($order, $exception);
        } catch (CommandExceptionInterface $exception) {
            $exceptionWrapper = new Exception(
                __(
                    'API call failed. Please try again or contact the shop admin. Error details can be found in logs.',
                    'payoneer-checkout'
                ),
                $exception->getCode(),
                $exception
            );
            return $this->handleFailedPaymentProcessing($order, $exceptionWrapper);
        }

        /**
         * We always signal success: The actual payment is supposed to be handled by the JS WebSDK
         * in the customer's browser session. Our 'redirect' URL is only a fallback in case our JS
         * is somehow broken. For this reason, we also add the flag to force hosted mode.
         * The WebSDK is taking care of redirecting to 'thank-you' after finishing the transaction.
         * If this somehow does not happen, we still instruct WC to move to the payment page
         */
        return [
            'result' => 'success',
            'redirect' => add_query_arg(
                [
                    $this->hostedModeOverrideFlag => true,
                ],
                $order->get_checkout_payment_url()
            ),
            'messages' => '<div></div>',
        ];
    }

    /**
     * @throws \WC_Data_Exception
     * @throws CheckoutExceptionInterface
     */
    protected function updateOrderWithSessionData(WC_Order $order, ListInterface $list): void
    {
        $this->sessionPersistor->withOrder($order)->persist($list);

        $identification = $list->getIdentification();

        $transactionId = $identification->getTransactionId();
        $order->update_meta_data(
            $this->transactionIdFieldName,
            $transactionId
        );
        $order->add_order_note(sprintf(
            /* translators: Transaction ID supplied by WooCommerce plugin */
            __('Initiating payment with transaction ID "%1$s"', 'payoneer-checkout'),
            $transactionId
        ));
        $order->set_transaction_id($identification->getLongId());
        $order->save();
    }

    /**
     * Inspect the exceptions to carry out appropriate actions based on the given interaction code
     *
     * @param WC_Order $order
     * @param InteractionExceptionInterface $exception
     *
     * @return PaymentResult
     */
    protected function handleInteractionException(
        WC_Order $order,
        InteractionExceptionInterface $exception
    ): array {

        do_action(
            'payoneer-checkout.update_list_session_failed',
            ['exception' => $exception]
        );

        return $this->handleFailedPaymentProcessing($order, $exception);
    }
    /**
     * Take actions on payment processing failed and return fields expected by WC Payment API.
     *
     * @param \Throwable|\WP_Error|string|null $error
     * phpcs:disable Inpsyde.CodeQuality.ArgumentTypeDeclaration.NoArgumentType
     *
     * @return PaymentResult
     */
    protected function handleFailedPaymentProcessing(WC_Order $order, $error = null): array
    {
        $fallback = __('The payment was not processed. Please try again.', 'payoneer-checkout');

        switch (true) {
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
        do_action(
            'payoneer-checkout.payment_processing_failure',
            [
                'order' => $order,
                'exception' => $error,
            ]
        );

        /**
         * Force a fragment refresh. This re-initializes the checkout widget
         * and thereby updates the list of payment options
         */
        WC()->session->set('refresh_totals', true);

        return [
            'result' => 'failure',
            'redirect' => '',
        ];
    }

    /**
     * @param UpdateListCommandInterface $updateCommand
     *
     * @throws CommandExceptionInterface If failed to update.
     */
    protected function updateListSession(UpdateListCommandInterface $updateCommand): void
    {

        try {
            $updateCommand->execute();
        } catch (CommandExceptionInterface $commandException) {
            do_action(
                'payoneer_for_woocommerce.update_list_session_failed',
                ['exception' => $commandException]
            );

            throw $commandException;
        }
    }
}
