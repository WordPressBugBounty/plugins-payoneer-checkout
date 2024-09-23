<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession;

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
    public static function determineContextFromGlobals(\WC_Order $order = null): ContextInterface
    {
        if (is_checkout_pay_page() || isset($_POST['action']) && $_POST['action'] === 'payoneer_order_pay') {
            if ($order === null) {
                /**
                 * @var \WC_Order $order
                 */
                $order = wc_get_order((int) get_query_var('order-pay'));
            }
            return new PaymentContext($order);
        }
        return new CheckoutContext();
    }
}
