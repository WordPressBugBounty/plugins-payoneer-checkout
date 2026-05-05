<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Middleware;

use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Api\Gateway\CommandFactory\WcOrderBasedUpdateCommandFactoryInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\CheckoutExceptionInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\HashProvider\HashProviderInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\ListSession\WcBasedUpdateCommandFactoryInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ContextInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionProvider;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionProviderMiddleware;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\ApiExceptionInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\Exception\CommandExceptionInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\UpdateListCommandInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
class UpdatingMiddleware implements ListSessionProviderMiddleware
{
    use IsProcessingTrait;
    protected bool $isRestRequest;
    /**
     * @var WcBasedUpdateCommandFactoryInterface
     */
    protected $wcBasedListSessionFactory;
    private ListCacheInterface $listCache;
    /**
     * @var HashProviderInterface
     */
    private $hashProvider;
    /**
     * @var string
     */
    private $sessionHashKey;
    protected WcOrderBasedUpdateCommandFactoryInterface $orderBasedUpdateCommandFactory;
    public function __construct(WcBasedUpdateCommandFactoryInterface $wcBasedListSessionFactory, HashProviderInterface $hashProvider, string $sessionHashKey, WcOrderBasedUpdateCommandFactoryInterface $orderBasedUpdateCommandFactory, bool $isRestRequest, ListCacheInterface $listCache)
    {
        $this->wcBasedListSessionFactory = $wcBasedListSessionFactory;
        $this->hashProvider = $hashProvider;
        $this->sessionHashKey = $sessionHashKey;
        $this->orderBasedUpdateCommandFactory = $orderBasedUpdateCommandFactory;
        $this->isRestRequest = $isRestRequest;
        $this->listCache = $listCache;
    }
    public function provide(ContextInterface $context, ListSessionProvider $next): ListInterface
    {
        $list = $next->provide($context);
        $longId = $list->getIdentification()->getLongId();
        /**
         * We cache here already to avoid multiple UPDATE calls in
         * case of recursion.
         * After the UPDATE,
         * we will cache again to make sure we cache the updated list.
         */
        $this->listCache->cacheList($list);
        /**
         * If we are already at the payment stage,
         * we will let the gateway deal with final updates
         */
        if ($this->isProcessing()) {
            return $list;
        }
        if ($context->offsetExists('pristine')) {
            //It is a fresh list, nothing to do with it.
            return $list;
        }
        try {
            $order = $context->getOrder();
            if ($order !== null) {
                $list = $this->updateBasedOnOrder($list, $order);
                $this->listCache->cacheList($list);
                return $this->listCache->getCachedListByLongId($longId);
            }
            $customer = $context->getCustomer();
            $session = $context->getSession();
            $cart = $context->getCart();
            if ($session !== null && $cart !== null && $customer !== null) {
                $list = $this->updateBasedOnSession($list, $session, $customer, $cart, $context->offsetExists('pristine'));
            }
        } catch (\Throwable $exception) {
            //TODO Log errors during UPDATE
            $list = $next->provide($context);
        }
        $this->listCache->cacheList($list);
        return $this->listCache->getCachedListByLongId($longId);
    }
    /**
     * @param ListInterface $list
     * @param \WC_Order $order
     *
     * @return ListInterface
     * @throws ApiExceptionInterface
     * @throws CheckoutExceptionInterface
     * @throws CommandExceptionInterface
     */
    protected function updateBasedOnOrder(ListInterface $list, \WC_Order $order): ListInterface
    {
        /**
         * Mirror of the session-based hash dedup. Order-pay renders iterate every
         * gateway (icons, availability, fields) and each one calls provide() with
         * a fresh PaymentContext, so without this guard the same LIST gets PUT to
         * the API once per gateway. Storing the hash on order meta also dedups
         * across page reloads when the order data hasn't changed.
         */
        $currentHash = $this->provideOrderHash($order);
        $storedHash = (string) $order->get_meta($this->sessionHashKey, \true);
        if ($storedHash === $currentHash) {
            return $list;
        }
        $command = $this->orderBasedUpdateCommandFactory->createUpdateCommand($order, $list);
        $updated = $this->updateList($command, $list);
        $order->update_meta_data($this->sessionHashKey, $currentHash);
        $order->save();
        return $updated;
    }
    /**
     * Hash of the order data that the LIST UPDATE depends on. Mirrors the cart
     * fields hashed by CheckoutHashProvider.
     */
    protected function provideOrderHash(\WC_Order $order): string
    {
        return md5(serialize([$order->get_total('edit'), $order->get_currency(), $order->get_billing_country(), $order->get_shipping_country()]));
    }
    /**
     * @throws \Throwable
     */
    protected function updateBasedOnSession(ListInterface $list, \WC_Session $session, \WC_Customer $customer, \WC_Cart $cart, bool $pristine): ListInterface
    {
        /**
         * We don't want to update List before this hook. It is fired after cart totals is
         * calculated. Before this moment, cart returns 0 for totals and List update will obviously
         * get 'ABORT' because no payment networks support 0 amount.
         */
        if (!$this->isRestRequest && !did_action('woocommerce_after_calculate_totals')) {
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
        if ($pristine) {
            $session->set($this->sessionHashKey, $currentHash);
            return $list;
        }
        /**
         * Compare the cart hash.
         * If it has not changed, return the existing LIST
         */
        $storedHash = $session->get($this->sessionHashKey);
        if ($storedHash === $currentHash) {
            return $list;
        }
        $command = $this->wcBasedListSessionFactory->createUpdateCommand($list->getIdentification(), $customer, $cart);
        $updated = $this->updateList($command, $list);
        /**
         * Update checkout hash since the LIST has now changed
         */
        $session->set($this->sessionHashKey, $currentHash);
        return $updated;
    }
    /**
     * @throws CommandExceptionInterface
     */
    protected function updateList(UpdateListCommandInterface $command, ListInterface $list): ListInterface
    {
        $longId = $list->getIdentification()->getLongId();
        /**
         * If process_payment has already claimed this LIST (set a transient),
         * skip this session-based UPDATE to prevent overwriting the real order
         * data (reference, invoiceId, security token) with dummy session data.
         *
         * @see PayoneerCommonPaymentProcessor::processPayment()
         */
        $claimKey = 'payoneer_claimed_' . md5($longId);
        if (get_transient($claimKey)) {
            do_action('payoneer-checkout.list_update_skipped', ['longId' => $longId, 'reason' => 'list_claimed_by_order']);
            return $list;
        }
        /**
         * Try to acquire an advisory lock to prevent concurrent LIST UPDATEs.
         * If process_payment currently holds the lock, skip this UPDATE.
         * Timeout = 0 means non-blocking: return immediately if lock is unavailable.
         */
        global $wpdb;
        $lockName = 'payoneer_list_' . substr(md5($longId), 0, 20);
        $acquired = (bool) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 0)", $lockName));
        if (!$acquired) {
            do_action('payoneer-checkout.list_update_skipped', ['longId' => $longId, 'reason' => 'concurrent_lock_held']);
            return $list;
        }
        try {
            do_action('payoneer-checkout.before_update_list', ['longId' => $longId, 'list' => $list, 'source' => 'UpdatingMiddleware', 'hasSecurityToken' => \false, 'reference' => 'Checkout payment']);
            $updatedList = $command->execute();
            do_action('payoneer-checkout.list_session_updated', ['longId' => $updatedList->getIdentification()->getLongId(), 'list' => $updatedList, 'source' => 'UpdatingMiddleware']);
            return $updatedList;
        } finally {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName));
        }
    }
}
