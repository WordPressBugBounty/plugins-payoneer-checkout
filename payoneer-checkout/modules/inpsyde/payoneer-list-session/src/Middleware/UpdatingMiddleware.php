<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Middleware;

use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Api\Gateway\CommandFactory\WcOrderBasedUpdateCommandFactoryInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\HashProvider\HashProviderInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\ListSession\WcBasedUpdateCommandFactoryInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\CheckoutContext;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ContextInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionPersistor;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionProvider;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionProviderMiddleware;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\PaymentContext;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\UpdateListCommandInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
class UpdatingMiddleware implements ListSessionProviderMiddleware
{
    /**
     * @var ListSessionPersistor
     */
    private $persistor;
    /**
     * @var WcBasedUpdateCommandFactoryInterface
     */
    protected $wcBasedListSessionFactory;
    /**
     * @var HashProviderInterface
     */
    private $hashProvider;
    /**
     * @var string
     */
    private $sessionHashKey;
    protected WcOrderBasedUpdateCommandFactoryInterface $orderBasedUpdateCommandFactory;
    public function __construct(ListSessionPersistor $persistor, WcBasedUpdateCommandFactoryInterface $wcBasedListSessionFactory, HashProviderInterface $hashProvider, string $sessionHashKey, WcOrderBasedUpdateCommandFactoryInterface $orderBasedUpdateCommandFactory)
    {
        $this->persistor = $persistor;
        $this->wcBasedListSessionFactory = $wcBasedListSessionFactory;
        $this->hashProvider = $hashProvider;
        $this->sessionHashKey = $sessionHashKey;
        $this->orderBasedUpdateCommandFactory = $orderBasedUpdateCommandFactory;
    }
    public function provide(ContextInterface $context, ListSessionProvider $next): ListInterface
    {
        /**
         * If we are already at the payment stage,
         * we will let the gateway deal with final updates
         */
        if ($this->isProcessing($context)) {
            return $next->provide($context);
        }
        if ($context instanceof PaymentContext) {
            return $this->provideForPaymentContext($context, $next);
        }
        if ($context instanceof CheckoutContext) {
            return $this->provideForCheckoutContext($context, $next);
        }
        //If we ever add a new type of context, we must be handling it properly.
        throw new \UnexpectedValueException('Unexpected context type.');
    }
    /**
     * During payment processing, the final UPDATE is carried out by individual PaymentProcessors.
     * Therefore, we want to be able to skip redundant UPDATE calls in that stage of the journey.
     *
     * @param ContextInterface $context
     *
     * @return bool
     */
    protected function isProcessing(ContextInterface $context): bool
    {
        /**
         * During the checkout journey, the LIST is transferred from the WC_Session
         * to the WC_Order. So we can perform this check for ANY context:
         */
        if (did_action('woocommerce_before_checkout_process') > 0) {
            return \true;
        }
        /**
         * Now check specifically for PaymentContext
         */
        return $context instanceof PaymentContext && did_action('woocommerce_before_pay_action') > 0;
    }
    protected function provideForPaymentContext(PaymentContext $context, ListSessionProvider $provider): ListInterface
    {
        $list = $provider->provide($context);
        if ($context->offsetExists('list_just_created')) {
            //It is a fresh list, nothing to do with it.
            return $list;
        }
        try {
            $command = $this->orderBasedUpdateCommandFactory->createUpdateCommand($context->getOrder(), $list);
            $updatedList = $this->updateList($command, $list);
            $this->persistor->persist($updatedList, $context);
            return $updatedList;
        } catch (\Throwable $throwable) {
            $this->persistor->persist(null, $context);
            return $provider->provide($context);
        }
    }
    protected function provideForCheckoutContext(CheckoutContext $context, ListSessionProvider $provider): ListInterface
    {
        $list = $provider->provide($context);
        /**
         * We don't want to update List before this hook. It is fired after cart totals is
         * calculated. Before this moment, cart returns 0 for totals and List update will obviously
         * get 'ABORT' because no payment networks support 0 amount.
         */
        if (!did_action('woocommerce_after_calculate_totals')) {
            return $list;
        }
        /**
         * Grab the cart hash to check if there have been changes that require an update
         */
        $currentHash = $this->hashProvider->provideHash();
        /**
         * No need to update List if it was created on current request with current context.
         * We write the current hash to prevent an unneeded update next time the LIST is requested
         */
        if ($context->offsetExists('list_just_created')) {
            $context->getSession()->set($this->sessionHashKey, $currentHash);
            return $list;
        }
        /**
         * Compare the cart hash.
         * If it has not changed, return the existing LIST
         */
        $storedHash = $context->getSession()->get($this->sessionHashKey);
        if ($storedHash === $currentHash) {
            return $list;
        }
        try {
            $command = $this->wcBasedListSessionFactory->createUpdateCommand($list->getIdentification(), $context->getCustomer(), $context->getCart());
            $updated = $this->updateList($command, $list);
        } catch (\Throwable $exception) {
            /**
             * Clear any stored LIST downstream. The existing one is no longer usable
             */
            $this->persistor->persist(null, $context);
            /**
             * Re-run the stack.
             * With persisted LISTs now cleared, we should get a fresh one from the API
             */
            return $provider->provide($context);
        }
        /**
         * Update checkout hash since the LIST has now changed
         */
        $context->getSession()->set($this->sessionHashKey, $currentHash);
        /**
         * Store the updated LIST
         */
        $this->persistor->persist($updated, $context);
        return $updated;
    }
    protected function updateList(UpdateListCommandInterface $command, ListInterface $list): ListInterface
    {
        do_action('payoneer-checkout.before_update_list', ['longId' => $list->getIdentification()->getLongId(), 'list' => $list]);
        $updatedList = $command->execute();
        do_action('payoneer-checkout.list_session_updated', ['longId' => $updatedList->getIdentification()->getLongId(), 'list' => $updatedList]);
        return $updatedList;
    }
}
