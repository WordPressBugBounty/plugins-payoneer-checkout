<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\HostedPayment\PaymentProcessor;

use Automattic\WooCommerce\Blocks\Payments\PaymentResult;
use Inpsyde\PayoneerForWoocommerce\Checkout\CheckoutExceptionInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\ListSession\OrderBasedListCommandFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\WcOrderListSessionPersistor;
use Inpsyde\PayoneerForWoocommerce\Checkout\MisconfigurationDetector\MisconfigurationDetectorInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\PaymentProcessor\AbstractPaymentProcessor;
use Inpsyde\PayoneerForWoocommerce\Checkout\PaymentProcessor\RedirectUrlCreatorTrait;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\PaymentProcessor\PaymentProcessorInterface;
use Inpsyde\PayoneerSdk\Api\ApiExceptionInterface;
use Inpsyde\PayoneerSdk\Api\Command\Exception\CommandExceptionInterface;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Style\Style;
use Inpsyde\PayoneerSdk\Api\PayoneerIntegrationTypes;
use WC_Order;

/**
 * @psalm-import-type PaymentResult from PaymentProcessorInterface
 */
class HostedPaymentProcessor extends AbstractPaymentProcessor
{
    use RedirectUrlCreatorTrait;

    /**
     * @var OrderBasedListCommandFactoryInterface
     */
    protected $listCommandFactory;
    /**
     * @var WcOrderListSessionPersistor
     */
    protected $listSessionPersistor;
    /**
     * @var string
     */
    protected $transactionIdFieldName;

    public function __construct(
        OrderBasedListCommandFactoryInterface $listCommandFactory,
        WcOrderListSessionPersistor $listSessionPersistor,
        string $transactionIdFieldName,
        MisconfigurationDetectorInterface $misconfigurationDetector
    ) {

        parent::__construct($misconfigurationDetector);

        $this->listCommandFactory = $listCommandFactory;
        $this->transactionIdFieldName = $transactionIdFieldName;
        $this->listSessionPersistor = $listSessionPersistor;
    }

    public function processPayment(WC_Order $order): array
    {
        try {
            /**
             * The style object from OrderBasedListCommandFactoryInterface is not aware
             * of the chose payment flow and I don't think it should be.
             * But since we need to supply the hostedVersion,
             * we are currently forced to re-create it here.
             * TODO come up with a cleaner way to supply this configuration
             */
            $style = new Style(determine_locale());
            $style = $style->withHostedVersion('v4');

            $listCommand = $this->listCommandFactory->createListCommand($order);
            $listCommand = $listCommand->withOperationType('CHARGE')
                                       ->withIntegrationType(PayoneerIntegrationTypes::HOSTED)
                                       ->withStyle($style);
            do_action('payoneer-checkout.before_create_list');
            $list = $listCommand->execute();
            do_action(
                'payoneer-checkout.list_session_created',
                [
                    'longId' => $list->getIdentification()->getLongId(),
                    'list' => $list,
                ]
            );
            $redirectUrl = $this->createRedirectUrl($list);
        } catch (CommandExceptionInterface | ApiExceptionInterface $exception) {
            do_action(
                'payoneer-checkout.create_list_session_failed',
                ['exception' => $exception]
            );
            return $this->handleError($exception);
        }

        try {
            $this->listSessionPersistor->withOrder($order)->persist($list);
        } catch (CheckoutExceptionInterface $exception) {
            /**
             * It is probably very unlikely this ever happens...
             */
            return $this->handleError($exception);
        }

        try {
            $this->storeTransactionId($order, $list);
        } catch (\WC_Data_Exception $exception) {
            /**
             * It is probably very unlikely this ever happens...
             */
            return $this->handleError($exception);
        }

        $note = __(
            'The customer is being redirected to an external payment page.',
            'payoneer-checkout'
        );
        $order->update_status('on-hold', $note);

        return [
            'result' => 'success',
            'redirect' => $redirectUrl,
        ];
    }

    /**
     * @param \Throwable|\WP_Error|string|null $error
     * phpcs:disable Inpsyde.CodeQuality.ArgumentTypeDeclaration.NoArgumentType
     *
     * @return string[]
     * @psalm-return PaymentResult
     */
    protected function handleError($error = null): array
    {
        $fallback = __('An error occurred during payment processing', 'payoneer-checkout');

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

        return [
            'result' => 'failure',
            'redirect' => '',
        ];
    }

    /**
     * Store the LIST's generated trx-ID on the order itself.
     * This is required so we can match the order during webhook processing
     *
     * @throws \WC_Data_Exception
     */
    protected function storeTransactionId(WC_Order $order, ListInterface $list): void
    {
        $identification = $list->getIdentification();

        $order->update_meta_data(
            $this->transactionIdFieldName,
            $identification->getTransactionId()
        );
        $order->set_transaction_id($identification->getLongId());
        $order->save();
    }
}
