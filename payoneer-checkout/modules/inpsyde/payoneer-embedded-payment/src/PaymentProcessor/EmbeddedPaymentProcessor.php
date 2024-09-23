<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentProcessor;

use Exception;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\Authentication\TokenGeneratorInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\MisconfigurationDetector\MisconfigurationDetectorInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\PaymentProcessor\AbstractPaymentProcessor;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionManager;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionPersistor;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionProvider;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\PaymentContext;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Api\Gateway\CommandFactory\WcOrderBasedUpdateCommandFactoryInterface;
use Syde\Vendor\Inpsyde\PaymentGateway\PaymentGateway;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\Exception\CommandExceptionInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\Exception\InteractionExceptionInterface;
use WC_Order;
/**
 * @psalm-import-type PaymentResult from AbstractPaymentProcessor
 */
class EmbeddedPaymentProcessor extends AbstractPaymentProcessor
{
    /**
     * @var string
     */
    protected $hostedModeOverrideFlag;
    public function __construct(WcOrderBasedUpdateCommandFactoryInterface $updateCommandFactory, ListSessionProvider $sessionProvider, ListSessionPersistor $sessionPersistor, TokenGeneratorInterface $tokenGenerator, string $tokenKey, string $transactionIdFieldName, string $hostedModeOverrideFlag, MisconfigurationDetectorInterface $misconfigurationDetector, string $checkoutSessionHashKey)
    {
        parent::__construct($misconfigurationDetector, $sessionProvider, $sessionPersistor, $updateCommandFactory, $tokenGenerator, $tokenKey, $transactionIdFieldName, $checkoutSessionHashKey);
        $this->hostedModeOverrideFlag = $hostedModeOverrideFlag;
    }
    public function processPayment(WC_Order $order, PaymentGateway $gateway): array
    {
        /**
         * Transfer the checkout-based LIST to the WC_Order.
         * From there, the parent AbstractPaymentProcessor can take over.
         */
        $list = $this->sessionProvider->provide(ListSessionManager::determineContextFromGlobals($order));
        $this->sessionPersistor->persist($list, new PaymentContext($order));
        try {
            $result = parent::processPayment($order, $gateway);
        } catch (InteractionExceptionInterface $exception) {
            return $this->handleInteractionException($order, $exception);
        } catch (CommandExceptionInterface $exception) {
            $exceptionWrapper = new Exception(
                /* translators: An unexpected error during the final List UPDATE before the CHARGE */
                __('Payment failed. Please attempt the payment again or contact the shop admin. This issue has been logged.', 'payoneer-checkout'),
                $exception->getCode(),
                $exception
            );
            return $this->handleFailedPaymentProcessing($order, $exceptionWrapper);
        }
        $result['redirect'] = add_query_arg([$this->hostedModeOverrideFlag => \true], $order->get_checkout_payment_url());
        return $result;
    }
}
