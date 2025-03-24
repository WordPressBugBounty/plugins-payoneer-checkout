<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession;

use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\RequestHeaderUtil;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
class ListSessionManager implements ListSessionProvider, ListSessionPersistor
{
    /**
     * @var ListSessionMiddleware[]|ListSessionProvider[]|ListSessionPersistor[]
     */
    private $middlewares;
    /**
     * @param ListSessionMiddleware[]|ListSessionProvider[]|ListSessionPersistor[] $middlewares
     */
    public function __construct(array $middlewares)
    {
        $this->middlewares = $middlewares;
    }
    public function persist(?ListInterface $list, ContextInterface $context): bool
    {
        reset($this->middlewares);
        $runner = new Runner($this->middlewares);
        return $runner->persist($list, $context);
    }
    public function provide(ContextInterface $context): ListInterface
    {
        reset($this->middlewares);
        $runner = new Runner($this->middlewares);
        return $runner->provide($context);
    }
    /**
     * Decide whether to use PaymentContext (order-based) or CheckoutContext (cart-based)
     * by inspecting request data.
     *
     * TODO: turn this global method into a service so that we can use proper dependencies from our container
     *
     * @param \WC_Order|null $order
     *
     * @return ContextInterface
     */
    public static function determineContextFromGlobals(\WC_Order $order = null): ContextInterface
    {
        /**
         * When using the Store API we currently rely on custom HHT header to pass information
         * about the current checkout attempt.
         */
        $headerUtil = new RequestHeaderUtil();
        $paymentCheckoutHeader = 'x-payoneer-is-payment-checkout';
        $orderKey = $headerUtil->getHeader($paymentCheckoutHeader);
        if (!empty($orderKey)) {
            $orderId = wc_get_order_id_by_order_key($orderKey);
            $order = wc_get_order($orderId);
            if ($order instanceof \WC_Order) {
                return new PaymentContext($order);
            }
        }
        /**
         * For initial page loads, we can use classic WP/WC functions to inspect the current request
         */
        if (is_checkout_pay_page() || isset($_POST['action']) && $_POST['action'] === 'payoneer_order_pay') {
            if ($order === null) {
                $orderId = get_query_var('order-pay');
                $order = wc_get_order((int) $orderId);
            }
            // Ensure the order is of type WC_Order
            if ($order instanceof \WC_Order) {
                return new PaymentContext($order);
            }
        }
        /**
         * When in doubt, return the CheckoutContext which covers classic & block checkout
         */
        return new CheckoutContext();
    }
}
