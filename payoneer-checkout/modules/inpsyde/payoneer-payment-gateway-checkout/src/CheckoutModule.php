<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout;

use Exception;
use Syde\Vendor\Inpsyde\Modularity\Module\ExecutableModule;
use Syde\Vendor\Inpsyde\Modularity\Module\ExtendingModule;
use Syde\Vendor\Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Syde\Vendor\Inpsyde\Modularity\Module\ServiceModule;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\MisconfigurationDetector\MisconfigurationDetectorInterface;
use Syde\Vendor\Psr\Container\ContainerInterface;
use RuntimeException;
use WC_Order;
class CheckoutModule implements ServiceModule, ExecutableModule, ExtendingModule
{
    use ModuleClassNameIdTrait;
    /**
     * Interaction codes signalizing payment failure.
     */
    protected const FAILED_PAYMENT_INTERACTION_CODES = ['RETRY', 'ABORT', 'TRY_OTHER_ACCOUNT', 'TRY_OTHER_NETWORK'];
    /**
     * @var array<string, callable>
     * @psalm-var array<string, callable(ContainerInterface): mixed>
     */
    protected $services;
    /**
     * @var array<string, callable>
     * @psalm-var array<string, callable(mixed $service, \Psr\Container\ContainerInterface
     *     $container):mixed>
     */
    protected $extensions;
    public function __construct()
    {
        $moduleRootDir = dirname(__FILE__, 2);
        $this->services = (require "{$moduleRootDir}/inc/services.php")();
        $this->extensions = (require "{$moduleRootDir}/inc/extensions.php")();
    }
    /**
     * @inheritDoc
     * @psalm-suppress MixedReturnTypeCoercion
     */
    public function services(): array
    {
        return $this->services;
    }
    /**
     * phpcs:disable Inpsyde.CodeQuality.FunctionLength.TooLong
     * @inheritDoc
     */
    public function run(ContainerInterface $container): bool
    {
        $this->registerCheckoutSetup($container);
        $this->registerCacheSaltUpdating($container);
        $this->setupFiringPaymentCompleteAction($container);
        $this->registerAddingLiveModeNotice($container);
        $notificationReceivedOptionName = (string) $container->get('checkout.notification_received.option_name');
        $this->addIncomingWebhookListener($notificationReceivedOptionName);
        $this->addCreateListSessionFailedListener($container);
        return \true;
    }
    protected function registerCheckoutSetup(ContainerInterface $container): void
    {
        add_action('woocommerce_init', function () use ($container) {
            $gatewayEnabled = (bool) $container->get('checkout.payment_gateway.is_enabled');
            if ($gatewayEnabled) {
                $this->setupCheckoutActions($container);
                do_action('payoneer-checkout.init_checkout');
            }
        });
    }
    protected function registerCacheSaltUpdating(ContainerInterface $container): void
    {
        $saltOptionName = $container->get('checkout.list_session_manager.cache_key.salt.option_name');
        $eventsToUpdateSaltOn = $container->get('checkout.list_session_manager.cache_key.salt.update_on_events');
        assert(is_string($saltOptionName));
        assert(is_array($eventsToUpdateSaltOn));
        /** @psalm-var string[] $eventsToUpdateSaltOn */
        foreach ($eventsToUpdateSaltOn as $event) {
            add_action($event, static function () use ($saltOptionName): void {
                delete_option($saltOptionName);
            });
        }
    }
    /**
     * Store the CHARGE longId and recover order status on the thank-you page.
     *
     * The customer arrives here because Payoneer redirected to the successUrl
     * after a successful CHARGE (interaction code PROCEED). By this time the
     * webhook may or may not have arrived, so the order could be:
     * - 'pending'    — webhook not yet received
     * - 'processing' or 'completed' — charged webhook already arrived
     * - 'failed'     — a decline webhook from an earlier card attempt arrived
     *                   before the charge webhook (race condition)
     *
     * In the 'failed' case, we verify with Payoneer's API that the LIST is
     * actually charged, then call payment_complete() to recover the order.
     * A per-order MySQL lock serializes this with the webhook handler to
     * prevent duplicate payment_complete() calls and double emails.
     *
     * @param WC_Order $order
     * @param string $chargeIdMetaKey
     * @param \Psr\Container\ContainerInterface $container
     *
     * @return void
     */
    protected function onThankYouPage(WC_Order $order, string $chargeIdMetaKey, \Syde\Vendor\Psr\Container\ContainerInterface $container)
    {
        $chargeLongId = filter_input(\INPUT_GET, 'longId', \FILTER_CALLBACK, ['options' => 'sanitize_text_field']);
        if ($chargeLongId && !$order->meta_exists($chargeIdMetaKey)) {
            $order->update_meta_data($chargeIdMetaKey, (string) $chargeLongId);
            $order->save();
        }
        if (!$order->has_status('failed') || !$chargeLongId) {
            return;
        }
        // Order is failed on the thank-you page. This happens when a decline
        // webhook (from an earlier card attempt) arrived before the charge
        // webhook. Verify with Payoneer that the charge actually succeeded.
        try {
            $fetchChargeCommand = $container->get('payoneer_sdk.commands.fetch_charge');
            $chargeStatus = $fetchChargeCommand->withLongId($chargeLongId)->execute();
        } catch (\Throwable $e) {
            return;
        }
        if ($chargeStatus !== 'charged') {
            return;
        }
        // LIST is charged — recover the order. Use a per-order lock to
        // serialize with the webhook handler and prevent double side-effects.
        global $wpdb;
        $lockName = 'payoneer_order_' . $order->get_id();
        $acquired = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 15)", $lockName));
        if (!$acquired) {
            return;
        }
        try {
            // Re-read order inside the lock to see the latest state.
            $order = wc_get_order($order->get_id());
            if (!$order instanceof WC_Order || !$order->has_status('failed')) {
                return;
                // Webhook already recovered it.
            }
            $order->add_order_note('Recovering order on thank-you page: LIST status is "charged" but ' . 'a decline webhook from an earlier card attempt set the order to failed. ' . 'Completing payment now.');
            $order->payment_complete();
            if ($chargeLongId) {
                $order->update_meta_data($chargeIdMetaKey, (string) $chargeLongId);
            }
            $order->save();
        } finally {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName));
        }
    }
    /**
     * Payoneer might redirect us to the 'cancelUrl' if the 3DS challenge fails.
     * In this case, it is very likely that the webhook informing us about the failed transaction
     * arrives too late: The order will still be 'pending', and the awaiting_webhook flag will
     * still be set (preventing re-payment).
     *
     * So we inspect the GET parameters here to synchronously update the order status
     * and clear the awaiting_webhook flag so the customer can retry.
     *
     * @param WC_Order $order
     * @param string $awaitingWebhookFieldName
     *
     * @return void
     */
    protected function beforeOrderPay(WC_Order $order, string $awaitingWebhookFieldName): void
    {
        $interactionCode = filter_input(\INPUT_GET, 'interactionCode', \FILTER_CALLBACK, ['options' => 'sanitize_text_field']);
        if (!$interactionCode || $order->is_paid()) {
            return;
        }
        if (!in_array($interactionCode, self::FAILED_PAYMENT_INTERACTION_CODES, \true)) {
            return;
        }
        $interactionReason = filter_input(\INPUT_GET, 'interactionReason', \FILTER_CALLBACK, ['options' => 'sanitize_text_field']);
        $isGet = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET';
        if ($isGet) {
            $this->addCustomerNotice((string) $interactionReason);
        }
        if (!$order->has_status('failed')) {
            /**
             * We always need the message, but we do not always need to update the status:
             * The webhook might have arrived already.
             */
            /**
             * We currently do not handle webhooks about 'session' ABORT
             * So for now, let's just add a message here
             */
            if ($interactionCode === 'ABORT') {
                $order->add_order_note(
                    /* translators: When detecting an ABORT interaction code upon redirecting to the cancelUrl */
                    __('Payment has been aborted', 'payoneer-checkout')
                );
                do_action('payoneer-checkout.payment_aborted', $order);
            }
            /**
             * Synchronous redirect from cancelUrl with failure interaction code —
             * set 'failed' immediately so the customer sees the error and can retry.
             * The webhook may arrive later and is idempotent.
             */
            if ($awaitingWebhookFieldName) {
                $order->delete_meta_data($awaitingWebhookFieldName);
            }
            $order->update_status('failed');
            $order->save();
        }
    }
    protected function addCustomerNotice(string $interactionReason): void
    {
        $errorMessage = __('Payment failed. Please try again', 'payoneer-checkout');
        if ($interactionReason === 'CUSTOMER_ABORT') {
            /* translators: Notice when redirecting to cancelUrl (after failed 3DS challenge or customer abort) */
            $errorMessage = __('Payment canceled. Please try again or choose another payment method.', 'payoneer-checkout');
        }
        wc_add_notice($errorMessage, 'error');
    }
    /**
     * @inheritDoc
     */
    public function extensions(): array
    {
        return $this->extensions;
    }
    protected function setupCheckoutActions(ContainerInterface $container): void
    {
        $payoneerGatewayIds = $container->get('payment_gateways');
        assert(is_array($payoneerGatewayIds));
        /**
         * Prevent double-payment on orders awaiting asynchronous webhook confirmation.
         *
         * When a Payoneer payment session is active (indicated by the awaiting_webhook
         * meta flag), the order should not accept another payment attempt. This filter
         * tells WooCommerce that the order does not need payment, which hides "Pay"
         * buttons on My Account and blocks the order-pay endpoint.
         *
         * However, if the charge was never initiated (e.g. the client dropped the
         * connection after process_payment but before Stripe JS fired), the LIST will
         * still be in 'listed' status. In that case, we clear the flag and allow
         * WooCommerce to retry payment on the same order.
         *
         * The LIST status check only fires when the flag is set, so it does not add
         * overhead to normal checkout flows.
         */
        $awaitingWebhookFieldName = (string) $container->get('checkout.order.awaiting_webhook_field_name');
        add_filter('woocommerce_order_needs_payment', static function (bool $needsPayment, WC_Order $order) use ($payoneerGatewayIds, $awaitingWebhookFieldName, $container): bool {
            if (!$needsPayment) {
                return $needsPayment;
            }
            if (!in_array($order->get_payment_method(), $payoneerGatewayIds, \true)) {
                return $needsPayment;
            }
            if ($order->get_meta($awaitingWebhookFieldName, \true) !== 'yes') {
                return $needsPayment;
            }
            // The order is awaiting a webhook. Check whether the charge was
            // actually initiated by fetching the LIST status from Payoneer.
            $longId = $order->get_transaction_id();
            if (empty($longId)) {
                return \false;
            }
            try {
                $fetchListCommand = $container->get('payoneer_sdk.commands.fetch');
                $list = $fetchListCommand->withLongId($longId)->execute();
                $status = $list->getStatus()->getCode();
            } catch (\Throwable $e) {
                return \false;
            }
            if ($status === 'listed') {
                // LIST is still 'listed' — the charge was never initiated.
                // Clear the flag so WC can reuse this order.
                $order->delete_meta_data($awaitingWebhookFieldName);
                $order->save();
                $order->add_order_note(__('Cleared awaiting webhook flag: payment charge was never initiated (LIST still in "listed" status). Order is now retryable.', 'payoneer-checkout'));
                return \true;
            }
            // LIST has moved past 'listed' — webhook should arrive eventually.
            return \false;
        }, 10, 2);
        /**
         * Prevent WooCommerce from auto-cancelling orders awaiting asynchronous
         * webhook confirmation.
         *
         * WooCommerce's wc_cancel_unpaid_orders() runs on a scheduled cron and
         * cancels pending orders older than the "Hold stock" timeout (default 60
         * minutes). For BNPL payment methods (e.g. Klarna ~24h, Afterpay ~12h),
         * the webhook confirming the charge may arrive well after this timeout.
         *
         * When the awaiting_webhook flag is set, the payment session is still
         * active and the order must not be cancelled.
         */
        add_filter('woocommerce_cancel_unpaid_order', static function (bool $cancelOrder, WC_Order $order) use ($payoneerGatewayIds, $awaitingWebhookFieldName): bool {
            if (!$cancelOrder) {
                return $cancelOrder;
            }
            if (!in_array($order->get_payment_method(), $payoneerGatewayIds, \true)) {
                return $cancelOrder;
            }
            if ($order->get_meta($awaitingWebhookFieldName, \true) === 'yes') {
                return \false;
            }
            return $cancelOrder;
        }, 10, 2);
        add_action('wp', function () use ($container, $payoneerGatewayIds) {
            if (!$container->get('wc.is_checkout_pay_page')) {
                return;
            }
            $orderId = get_query_var('order-pay');
            $wcOrder = wc_get_order($orderId);
            if (!$wcOrder instanceof WC_Order) {
                return;
            }
            if (!in_array($wcOrder->get_payment_method(), $payoneerGatewayIds, \true)) {
                return;
            }
            $awaitingWebhookFieldName = (string) $container->get('checkout.order.awaiting_webhook_field_name');
            $this->beforeOrderPay($wcOrder, $awaitingWebhookFieldName);
        }, 0);
        /**
         * Run the thank-you page handler at template_redirect — before the
         * thankyou.php template loads the WC_Order object. This ensures that
         * any status recovery (e.g. failed→processing) is visible to the
         * template's has_status() check and the customer sees the correct
         * message.
         *
         * Using woocommerce_before_thankyou would be too late: the template
         * has already loaded a stale $order instance by then.
         */
        add_action('template_redirect', function () use ($container) {
            if (!is_checkout() || !is_wc_endpoint_url('order-received')) {
                return;
            }
            global $wp;
            $orderId = absint($wp->query_vars['order-received'] ?? 0);
            if (!$orderId) {
                return;
            }
            $wcOrder = wc_get_order($orderId);
            if (!$wcOrder instanceof WC_Order) {
                return;
            }
            $payoneerGatewayIds = $container->get('payment_gateways');
            assert(is_array($payoneerGatewayIds));
            if (!in_array($wcOrder->get_payment_method(), $payoneerGatewayIds, \true)) {
                return;
            }
            $chargeIdFieldName = (string) $container->get('inpsyde_payment_gateway.charge_id_field_name');
            $this->onThankYouPage($wcOrder, $chargeIdFieldName, $container);
        });
        /**
         * This is a temporary solution because we need a little styling for the CC icons.
         * The icons are added by this module so they should be styled by this module
         * TODO supply a proper css file for this. Rework markup into something more responsive
         */
        $paymentMethodsIconsCss = $container->get('checkout.gateway_icon_elements_css');
        assert(is_string($paymentMethodsIconsCss));
        add_action('wp', static function () use ($paymentMethodsIconsCss) {
            if (is_checkout()) {
                $handle = 'payoneer-checkout-base-css';
                wp_register_style($handle, \false, [], '*');
                wp_enqueue_style($handle);
                wp_add_inline_style($handle, $paymentMethodsIconsCss);
            }
        });
    }
    protected function setupFiringPaymentCompleteAction(ContainerInterface $container): void
    {
        add_action('woocommerce_pre_payment_complete', static function ($orderId) use ($container): void {
            $order = wc_get_order($orderId);
            if (!$order instanceof WC_Order) {
                throw new RuntimeException(sprintf('Cannot get order by provided ID %1$s', (string) $orderId));
            }
            if ($order->is_paid()) {
                return;
            }
            $orderPaymentGateway = $order->get_payment_method();
            $payoneerPaymentGateways = $container->get('payment_gateways');
            assert(is_array($payoneerPaymentGateways));
            if (!in_array($orderPaymentGateway, $payoneerPaymentGateways, \true)) {
                return;
            }
            $chargeIdFieldName = $container->get('core.payment_gateway.order.charge_id_field_name');
            assert(is_string($chargeIdFieldName));
            $chargeId = $order->get_meta($chargeIdFieldName, \true);
            do_action($orderPaymentGateway . '_payment_processing_success', ['chargeId' => $chargeId, 'orderId' => $order->get_id()]);
        });
    }
    /**
     * @param string $settingsPageUrl
     *
     * @return void
     */
    protected function registerAddingLiveModeNotice(ContainerInterface $container): void
    {
        add_action('all_admin_notices', static function () use ($container): void {
            $liveMode = (bool) $container->get('inpsyde_payment_gateway.is_live_mode');
            $notificationReceived = (bool) $container->get('checkout.notification_received');
            if ($liveMode || $notificationReceived) {
                return;
            }
            $settingsPageUrl = (string) $container->get('inpsyde_payment_gateway.settings_page_url');
            $class = 'notice notice-warning';
            $aTagOpening = sprintf('<a href="%1$s">', $settingsPageUrl);
            $disableTestMode = sprintf(
                /* translators: %1$s, %2$s and %3$s are replaced with the opening and closing 'a' tags */
                esc_html__('Enter valid Test credentials and Save settings to receive a payment notification and unlock Live mode checkbox. You can %1$srefresh%2$s the page to check if a payment notification has been already received and Live mode checkbox is unlocked.', 'payoneer-checkout'),
                $aTagOpening,
                '</a>',
                '<a href="">'
            );
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), wp_kses($disableTestMode, ['a' => ['href' => []]], ['http', 'https']));
        }, 12);
    }
    /**
     * Set option as a flag when status notification received.
     *
     * @param string $optionName
     */
    protected function addIncomingWebhookListener(string $optionName): void
    {
        add_action('payoneer-checkout.webhook_request', static function () use ($optionName): void {
            update_option($optionName, 'yes');
        });
    }
    /**
     * Add listener hiding payment gateway if failed to create LIST because of authorization issue.
     *
     * @param ContainerInterface $container
     *
     * @return void
     */
    protected function addCreateListSessionFailedListener(ContainerInterface $container): void
    {
        /**
         * Make our payment gateway unavailable if LIST session wasn't created because of incorrect
         * merchant configuration.
         */
        add_action('payoneer-checkout.create_list_session_failed', static function ($arg) use ($container): void {
            if (!$container->get('wp.is_rest_api_request') && !$container->get('checkout.is_frontend_request')) {
                return;
            }
            if (!is_array($arg)) {
                return;
            }
            $exception = $arg['exception'] ?? null;
            if (!$exception instanceof Exception) {
                return;
            }
            $misconfigurationDetector = $container->get('checkout.misconfiguration_detector');
            assert($misconfigurationDetector instanceof MisconfigurationDetectorInterface);
            $exceptionCausedByMisconfiguration = $misconfigurationDetector->isCausedByMisconfiguration($exception);
            if ($exceptionCausedByMisconfiguration) {
                $isHostedFlow = $container->get('checkout.selected_payment_flow') === 'hosted';
                if (!$isHostedFlow) {
                    add_filter('payoneer-checkout.payment_gateway_is_available', '__return_false');
                }
                do_action('payoneer-checkout.payment_gateway_misconfiguration_detected');
            }
        });
    }
    protected function isPayoneerOrderPaymentMethod(ContainerInterface $container, WC_Order $order): bool
    {
        $payoneerGatewayIds = (array) $container->get('payment_gateways');
        return in_array($order->get_payment_method(), $payoneerGatewayIds, \true);
    }
}
