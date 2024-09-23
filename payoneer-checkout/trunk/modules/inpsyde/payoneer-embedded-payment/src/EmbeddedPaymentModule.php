<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\EmbeddedPayment;

use Syde\Vendor\Dhii\Services\Factories\FuncService;
use Syde\Vendor\Inpsyde\Assets\Asset;
use Syde\Vendor\Inpsyde\Assets\AssetManager;
use Syde\Vendor\Inpsyde\Modularity\Module\ExecutableModule;
use Syde\Vendor\Inpsyde\Modularity\Module\ExtendingModule;
use Syde\Vendor\Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Syde\Vendor\Inpsyde\Modularity\Module\ServiceModule;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\AjaxOrderPay\AjaxPayAction;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\AjaxOrderPay\OrderPayload;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\CheckoutContext;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionManager;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionPersistor;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\PaymentContext;
use Syde\Vendor\Psr\Container\ContainerInterface;
use WC_Data_Exception;
use WC_Order;
/**
 * phpcs:disable Inpsyde.CodeQuality.NestingLevel.High
 * phpcs:disable Inpsyde.CodeQuality.FunctionLength.TooLong
 * phpcs:disable WordPress.WP.I18n.TextDomainMismatch
 */
class EmbeddedPaymentModule implements ExecutableModule, ServiceModule, ExtendingModule
{
    use ModuleClassNameIdTrait;
    /**
     * @inheritDoc
     */
    public function run(ContainerInterface $container): bool
    {
        $isEnabled = (bool) $container->get('embedded_payment.is_enabled');
        if (!$isEnabled) {
            return \true;
        }
        add_action('payoneer-checkout.init_checkout', function () use ($container): void {
            $this->setupModuleActions($container);
        });
        return \true;
    }
    /**
     * @param ContainerInterface $container
     *
     * @return void
     * @throws WC_Data_Exception
     */
    protected function setupModuleActions(ContainerInterface $container): void
    {
        $isFrontendRequest = $container->get('wp.is_frontend_request');
        if ($isFrontendRequest) {
            $this->registerAssets($container);
        }
        $this->registerSessionHandling($container);
        $this->registerAjaxOrderPay($container);
    }
    /**
     * Setup module assets registration.
     *
     * @param ContainerInterface $container
     *
     * @return void
     */
    public function registerAssets(ContainerInterface $container): void
    {
        add_action(AssetManager::ACTION_SETUP, static function (AssetManager $assetManager) use ($container) {
            /** @var Asset[] $assets */
            $assets = $container->get('embedded_payment.assets');
            $assetManager->register(...$assets);
        });
    }
    /**
     * Recover from catastrophic failure during the payment process
     *
     * @param WC_Order $order
     * @param ListSessionManager $listSessionManager
     * @param string $onBeforeServerErrorFlag
     *
     * @return void
     */
    public function beforeOrderPay(WC_Order $order, ListSessionManager $listSessionManager, string $onBeforeServerErrorFlag): void
    {
        $interactionCode = filter_input(\INPUT_GET, 'interactionCode', \FILTER_CALLBACK, ['options' => 'sanitize_text_field']);
        $onBeforeServerError = filter_input(\INPUT_GET, $onBeforeServerErrorFlag, \FILTER_CALLBACK, ['options' => 'sanitize_text_field']);
        if ($onBeforeServerError) {
            $listSessionManager->persist(null, new PaymentContext($order));
            /**
             * Safely redirect without the $onBeforeServerError flag.
             */
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }
        if (!$interactionCode || $order->is_paid()) {
            return;
        }
        $listSessionManager->persist(null, new CheckoutContext());
        $listSessionManager->persist(null, new PaymentContext($order));
        if (!in_array($interactionCode, ['RETRY', 'ABORT'], \true)) {
            return;
        }
        /**
         * Since we went here directly from the checkout page (redirect during client-side CHARGE),
         * WooCommerce did not have  the chance to clear the cart/session yet.
         * We'll do this explicitly here,
         * so that visiting the checkout page does not display a stale session
         */
        WC()->cart->empty_cart();
        /**
         * We redirect to the payment URL sans OPG parameters for 3 reasons:
         * 1. It's cleaner
         * 2. The WebSDK appears to pick up the URL parameters and attempt to re-use the aborted session
         * 3. We can make sure we start fresh on a new HTTP request
         */
        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }
    public function onBeforeServerError(ContainerInterface $container): void
    {
        //if there is the refresh flag, we need to create a new LIST session
        $onErrorFlag = $container->get('checkout.is_on_error_refresh_fragment_flag');
        $orderId = $container->get('wc.order_under_payment');
        if ($onErrorFlag) {
            $listSessionPersistor = $container->get('list_session.manager');
            assert($listSessionPersistor instanceof ListSessionPersistor);
            $listSessionPersistor->persist(null, new CheckoutContext());
            $wcOrder = wc_get_order($orderId);
            if ($wcOrder instanceof WC_Order) {
                /**
                 * The LIST has already been transferred to order meta, so for this case,
                 * we explicitly want to clear the persisted LIST as well
                 */
                $listSessionPersistor->persist(null, new PaymentContext($wcOrder));
            }
        }
    }
    /**
     * For embedded flow, we need to create a LIST session ahead of time.
     * Based on customer and Cart data, a LIST object will be serialized into the
     * checkout session and kept updated if relevant data changes
     *
     * @param ContainerInterface $container
     *
     * @return void
     */
    public function registerSessionHandling(ContainerInterface $container): void
    {
        /**
         * onBeforeServerError: Back-end-side handling
         */
        add_action('woocommerce_checkout_update_order_review', function () use ($container) {
            $this->onBeforeServerError($container);
        }, 10);
        add_action('wp', function () use ($container) {
            if (!$container->get('wc.is_checkout_pay_page')) {
                return;
            }
            $orderId = get_query_var('order-pay');
            $wcOrder = wc_get_order($orderId);
            if (!$wcOrder instanceof WC_Order) {
                return;
            }
            $listSessionManager = $container->get('list_session.manager');
            assert($listSessionManager instanceof ListSessionManager);
            $onBeforeServerErrorFlag = (string) $container->get('embedded_payment.pay_order_error_flag');
            $this->beforeOrderPay($wcOrder, $listSessionManager, $onBeforeServerErrorFlag);
        }, 0);
    }
    /**
     * Client-side CHARGE requires us to validate & create the order *before* attempting payment.
     * The payment page is a classic form submission, followed by hard redirect & exit handling by WooCommerce.
     * This unfortunately means we cannot just AJAXify that POST request.
     * \WC_Form_Handler does not provide us with any decoupled subset of functionality that we could use.
     * So here, we practically re-implement order-pay as an AJAX call.
     *
     * @see \WC_Form_Handler::pay_action()
     * @param ContainerInterface $container
     *
     * @return void
     */
    private function registerAjaxOrderPay(ContainerInterface $container): void
    {
        $onAjaxOrderPay = function () use ($container) {
            try {
                $delegate = new FuncService(['embedded_payment.ajax_order_pay.checkout_payload', 'embedded_payment.ajax_order_pay.payment_action'], \Closure::fromCallable([$this, 'onAjaxOrderPay']));
                /** @psalm-suppress MixedFunctionCall */
                $delegate($container)();
            } catch (\Throwable $exception) {
                wc_add_notice($exception->getMessage(), 'error');
                wp_send_json_error(['result' => 'failure'], 500);
            }
        };
        add_action('wp_ajax_payoneer_order_pay', $onAjaxOrderPay);
        add_action('wp_ajax_nopriv_payoneer_order_pay', $onAjaxOrderPay);
    }
    /**
     * @param OrderPayload $payload
     * @param AjaxPayAction $payAction
     *
     * @return void
     */
    protected function onAjaxOrderPay(OrderPayload $payload, AjaxPayAction $payAction)
    {
        if ($payAction($payload->getOrder(), $payload->getCustomer(), $payload->getFormData())) {
            wp_send_json_success(['result' => 'success'], 200);
        }
        wp_send_json_error(['result' => 'failure'], 500);
    }
    /**
     * @inheritDoc
     */
    public function services(): array
    {
        static $services;
        if ($services === null) {
            $services = require_once dirname(__DIR__) . '/inc/services.php';
        }
        /** @var callable(): array<string, callable(ContainerInterface $container):mixed> $services */
        return $services();
    }
    /**
     * @inheritDoc
     */
    public function extensions(): array
    {
        static $extensions;
        if ($extensions === null) {
            $extensions = require_once dirname(__DIR__) . '/inc/extensions.php';
        }
        /** @var callable(): array<string, callable(mixed $service, \Psr\Container\ContainerInterface $container):mixed> $extensions */
        return $extensions();
    }
}
