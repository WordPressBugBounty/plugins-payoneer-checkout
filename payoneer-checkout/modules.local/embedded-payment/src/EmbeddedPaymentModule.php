<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\EmbeddedPayment;

use Dhii\Services\Factories\FuncService;
use Dhii\Services\Factory;
use Inpsyde\Assets\Asset;
use Inpsyde\Assets\AssetManager;
use Inpsyde\Modularity\Module\ExecutableModule;
use Inpsyde\Modularity\Module\ExtendingModule;
use Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Inpsyde\Modularity\Module\ServiceModule;
use Inpsyde\PayoneerForWoocommerce\Checkout\CheckoutExceptionInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\Factory\ListSession\WcBasedUpdateCommandFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\HashProvider\HashProviderInterface;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\ListSessionPersistor;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\ListSessionProvider;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\ListSessionRemover;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\OrderAwareListSessionRemover;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\PassThroughListSessionProvider;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\UpdatingListSessionProvider;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\WcSessionListSessionManager;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\AjaxOrderPay\AjaxPayAction;
use Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\AjaxOrderPay\OrderPayload;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use Psr\Container\ContainerInterface;
use WC_Cart;
use WC_Customer;
use WC_Data_Exception;
use WC_Order;
use WC_Payment_Gateway;
use WC_Session;

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
        $isEnabled = (bool)$container->get('embedded_payment.is_enabled');
        if (! $isEnabled) {
            return true;
        }

        add_action('payoneer-checkout.init_checkout', function () use ($container): void {
            $this->setupModuleActions($container);
        });

        /**
         * Add extra styles & customization for the gateway settings page
         */
        add_action('admin_init', function () use ($container) {
            $isSettingsPage = (bool)$container->get('inpsyde_payment_gateway.is_settings_page');
            if ($isSettingsPage) {
                $this->onSettingsPage();
            }
        });

        return true;
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
        $this->registerPlaceOrderButton($container);
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
        add_action(
            AssetManager::ACTION_SETUP,
            static function (AssetManager $assetManager) use ($container) {
                /** @var Asset[] $assets */
                $assets = $container->get('embedded_payment.assets');
                $assetManager->register(...$assets);
            }
        );
    }

    /**
     * We want to supply CodeMirror support independent of gateway/payment configuration
     * @return void
     */
    public function onSettingsPage(): void
    {
        wp_enqueue_code_editor([
            'type' => 'css',
        ]);
    }

    /**
     * Recover from catastrophic failure during the payment process
     *
     * @param WC_Order $order
     * @param ListSessionRemover $checkoutSessionRemover
     * @param ListSessionRemover $orderPaySessionRemover
     * @param ListSessionRemover $wcOrderListSessionRemover
     * @param string $onBeforeServerErrorFlag
     *
     * @return void
     * @throws CheckoutExceptionInterface
     */
    public function beforeOrderPay(
        WC_Order $order,
        ListSessionRemover $checkoutSessionRemover,
        ListSessionRemover $orderPaySessionRemover,
        ListSessionRemover $wcOrderListSessionRemover,
        string $onBeforeServerErrorFlag
    ): void {

        $interactionCode = filter_input(
            INPUT_GET,
            'interactionCode',
            FILTER_CALLBACK,
            ['options' => 'sanitize_text_field']
        );

        $onBeforeServerError = filter_input(
            INPUT_GET,
            $onBeforeServerErrorFlag,
            FILTER_CALLBACK,
            ['options' => 'sanitize_text_field']
        );

        if ($onBeforeServerError) {
            $wcOrderListSessionRemover->clear();
            $orderPaySessionRemover->clear();
            /**
             * Safely redirect without the $onBeforeServerError flag.
             */
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        if (! $interactionCode || $order->is_paid()) {
            return;
        }
        $wcOrderListSessionRemover->clear();
        $checkoutSessionRemover->clear();
        $orderPaySessionRemover->clear();
        if (
            ! in_array($interactionCode, [
                'RETRY',
                'ABORT',
            ], true)
        ) {
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

    /**
     * The Payoneer WebSDK needs full control over the 'Place Order'-button.
     * We cannot give it that, since there are many payment gateways and customers
     * can toggle them any time. But we can create another button and transparently switch
     * them as our gateway is selected
     *
     * @param ContainerInterface $container
     *
     * @return void
     * phpcs:disable Inpsyde.CodeQuality.LineLength.TooLong
     */
    protected function registerPlaceOrderButton(ContainerInterface $container)
    {
        $hook = static function () use ($container) {
            $buttonId = (string)$container->get('embedded_payment.widget.button_id');
            $isOrderPay = (bool)$container->get('wc.is_checkout_pay_page');
            $orderButtonText = $isOrderPay ?
                (string)apply_filters(
                    'woocommerce_pay_order_button_text',
                    __('Pay for order', 'woocommerce')
                ) : (string)apply_filters(
                    'woocommerce_order_button_text',
                    __('Place order', 'woocommerce')
                );
            /**
             * @var string[] $classes
             */
            $classes = apply_filters(
                'payoneer-checkout.place_order_button.classes',
                [
                    'button',
                    'alt',

                    //TODO CheckoutWC compatibility. Move to dedicated module
                    'cfw-primary-btn',
                    'cfw-next-tab validate',
                ]
            );
            $classString = implode(
                ' ',
                $classes
            );
            //phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
            echo (string)apply_filters(
                'woocommerce_order_button_html',
                '<button disabled style="display:none;" type="button" class="' . esc_attr(
                    $classString
                ) . '" name="woocommerce_checkout_place_order" id="' . esc_attr(
                    $buttonId
                ) . '" value="'
                . esc_attr($orderButtonText) . '" data-value="' . esc_attr(
                    $orderButtonText
                ) . '">' . esc_html($orderButtonText) . '</button>'
            );
        };
        //phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

        add_action('woocommerce_pay_order_after_submit', $hook);
        add_action('woocommerce_review_order_after_submit', $hook);
    }

    public function onBeforeServerError(ContainerInterface $container): void
    {
        //if there is the refresh flag, we need to create a new LIST session
        $onErrorFlag =  $container->get('checkout.is_on_error_refresh_fragment_flag');
        $orderId = $container->get('wc.order_under_payment');
        if ($onErrorFlag) {
            $checkoutListSessionRemover = $container->get(
                'checkout.list_session_remover'
            );

            assert($checkoutListSessionRemover instanceof ListSessionRemover);
            $checkoutListSessionRemover->clear();

            $wcOrder = wc_get_order($orderId);
            if ($wcOrder instanceof WC_Order) {
                /**
                 * The LIST has already been transferred to order meta, so for this case,
                 * we explicitly want to clear the persisted LIST as well
                 */
                $wcOrderListSessionRemover = $container->get(
                    'checkout.list_session_remover.wc_order'
                );
                assert($wcOrderListSessionRemover instanceof OrderAwareListSessionRemover);
                $wcOrderListSessionRemover->withOrder($wcOrder)->clear();
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
         * Initial Page load
         */
        add_action('wp', static function () use ($container) {
            if (! is_checkout()) {
                return;
            }
            /**
             * On the order-pay page, notices are rendered only once - before the payment gateways
             * So we don't get to add errors during LIST creation.
             * We create the LIST session early as a workaround. This pre-warms the cache for later
             * and allows us to create error messages in time
             */
            $isOrderPay = (bool)$container->get('wc.is_checkout_pay_page');

            /**
             * The WC core form submission (->POST request) goes through a very similar code path.
             * We cannot afford to generate WC error notices though, since _any_ notice would cause
             * the form submission to fail - even if another payment gateway is used!
             * So we only want to initialize the LIST in GET calls.
             */
            $isPost = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
            if ($isOrderPay && !$isPost) {
                do_action('payoneer-checkout.init_list_session', true);
            }
        });

        /**
         * onBeforeServerError: Back-end-side handling
         */
        add_action('woocommerce_checkout_update_order_review', function () use ($container) {
            $this->onBeforeServerError($container);
        }, 10);

        /**
         * Fragment update: Init/Expiry handling for regular checkout
         */
        add_action('woocommerce_checkout_update_order_review', function () {
            /**
             * The hook above runs too early for us, since the WC_Customer is updated immediately
             * afterwards, while we need to use the updated data. So we hook into the customer save
             * process once to hook our logic
             */
            $this->hookOnce('woocommerce_after_calculate_totals', static function () {
                do_action('payoneer-checkout.init_list_session');
            });
        }, 11);

        add_action(
            'payoneer-checkout.init_list_session',
            function (bool $addNotice = true) use ($container) {
                try {
                    (new Factory([
                        'checkout.list_session_provider',
                        'checkout.list_session_persistor',
                        'checkout.checkout_hash_provider',
                        'checkout.session_hash_key',
                        'wc.session',
                        'embedded_payment.gateway.gateway',
                    ], \Closure::fromCallable([$this, 'initializeListSession'])))(
                        $container
                    );
                } catch (CheckoutExceptionInterface $exception) {
                    $misconfigurationDetected = did_action(
                        'payoneer-checkout.payment_gateway_misconfiguration_detected'
                    );
                    if ($addNotice && ! $misconfigurationDetected) {
                        $message = __(
                            'The selected payment method for your order is not available at the moment. Please select another payment method.',
                            'payoneer-checkout'
                        );
                        wc_add_notice($message, 'error');
                    }
                }
            }
        );
        /**
         * When changes happen during checkout, we need to update the LIST session with the current
         * checkout details.
         */
        add_action(
            'woocommerce_checkout_update_order_review',
            function () use ($container) {
                /**
                 * The 'woocommerce_checkout_update_order_review' hook
                 * runs before the checkout customer is populated with POST data.
                 * It is also too early for updated shipping options (which affect totals)
                 * So we defer to 'woocommerce_after_calculate_totals'
                 * which is the last step before rendering the actual template
                 */
                $this->hookOnce(
                    'woocommerce_after_calculate_totals',
                    function () use ($container) {
                        $delegate = new FuncService([
                            'wc.session',
                            'checkout.checkout_hash_provider',
                            'checkout.session_hash_key',
                            'checkout.list_session_provider',
                        ], \Closure::fromCallable([$this, 'updateListSession']));
                        /** @psalm-suppress MixedFunctionCall */
                        $delegate($container)($container);
                    },
                    11 // Needs to occur AFTER initialization
                );
            },
            12
        );

        add_action('payoneer-checkout.update_list_session_failed', static function () use ($container) {
            $remover = $container->get('checkout.list_session_remover');
            assert($remover instanceof ListSessionRemover);
            /**
             * Clear existing LIST and re-create a new one
             */
            try {
                $remover->clear();
            } catch (CheckoutExceptionInterface $exception) {
                //silence
            }
            do_action('payoneer-checkout.init_list_session', true);
        });

        add_action(
            'before_woocommerce_pay',
            function () use ($container) {
                $orderId = get_query_var('order-pay');
                $wcOrder = wc_get_order($orderId);
                if (! $wcOrder instanceof WC_Order) {
                    return;
                }

                $wcOrderListSessionRemover = $container->get(
                    'checkout.list_session_remover.wc_order'
                );
                assert($wcOrderListSessionRemover instanceof OrderAwareListSessionRemover);
                $wcOrderListSessionRemover = $wcOrderListSessionRemover->withOrder($wcOrder);
                /**
                 * If we just fetch 'checkout.list_session_remover', we will receive an instance
                 * preconfigured for order-pay, but we specifically need to clear the checkout(!)
                 * List session. I wish there was a more declarative way to pull this off,
                 * but for now, we explicitly grab the '*.wc_session' service and supply it with
                 * the desired storage key.
                 */
                $wcSessionManager = $container->get('checkout.list_session_manager.wc_session');
                assert($wcSessionManager instanceof WcSessionListSessionManager);
                $storageKey = (string)$container->get('checkout.list_session_manager.cache_key.checkout');
                $delegate = new FuncService([
                    'checkout.list_session_remover',
                    'embedded_payment.pay_order_error_flag',
                ], \Closure::fromCallable([$this, 'beforeOrderPay']));
                /** @psalm-suppress MixedFunctionCall * */
                $delegate($container)($wcOrder, $wcSessionManager->withKey($storageKey), $wcOrderListSessionRemover);
            },
            0
        );
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
                $delegate = new FuncService([
                    'embedded_payment.ajax_order_pay.checkout_payload',
                    'embedded_payment.ajax_order_pay.payment_action',
                ], \Closure::fromCallable([$this, 'onAjaxOrderPay']));
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
        if (
            $payAction(
                $payload->getOrder(),
                $payload->getCustomer(),
                $payload->getFormData()
            )
        ) {
            wp_send_json_success(['result' => 'success'], 200);
        }
        wp_send_json_error(['result' => 'failure'], 500);
    }

    /**
     * Register a WordPress hook in a way that callback is executed only once.
     *
     * @param string $hookName
     * @param callable $callable
     * @param int $priority
     * @param int $acceptedArgs
     *
     * @return void
     */
    protected function hookOnce(
        string $hookName,
        callable $callable,
        int $priority = 10,
        int $acceptedArgs = 1
    ): void {
        /**
         * @psalm-suppress UnusedVariable
         */
        $once = static function () use (&$once, $hookName, $callable, $priority) {
            static $called = false;
            /** @var callable $once */
            ! $called and $callable(...func_get_args());
            $called = true;
        };
        add_action($hookName, $once, $priority, $acceptedArgs);
    }

    /**
     * Creates a new LIST session by feeding a provider into a persistor.
     * We expect this provider to invoke a factory that creates a new session via API call.
     * If this succeeds, we record the resulting hash and a timestamp
     *
     * @param ListSessionProvider $initializer
     * @param ListSessionPersistor $persistor
     * @param HashProviderInterface $hashProvider
     * @param string $sessionHashKey
     * @param WC_Session $session
     * @param WC_Payment_Gateway $gateway
     *
     * @return void
     * @throws CheckoutExceptionInterface
     */
    protected function initializeListSession(
        ListSessionProvider $initializer,
        ListSessionPersistor $persistor,
        HashProviderInterface $hashProvider,
        string $sessionHashKey,
        WC_Session $session,
        WC_Payment_Gateway $gateway
    ): void {

        if (! $gateway->is_available()) {
            return;
        }

        $list = $initializer->provide();
        $persistor->persist($list);

        if (! $session->get($sessionHashKey)) {
            /**
             * Initialize hash and timestamp if not already set
             */
            $session->set($sessionHashKey, $hashProvider->provideHash());
        }
    }

    /**
     * Check if the checkout session has seen changes that are relevant to the payment process
     * If yes, trigger an update of the LIST via an API call.
     *
     * @param ContainerInterface $container
     * @param WC_Session $session
     * @param HashProviderInterface $hashProvider
     * @param string $sessionHashKey
     * @param ListSessionProvider $provider
     *
     * @return void
     * @throws CheckoutExceptionInterface
     */
    protected function updateListSession(
        ContainerInterface $container,
        WC_Session $session,
        HashProviderInterface $hashProvider,
        string $sessionHashKey,
        ListSessionProvider $provider
    ): void {

        $gateway = $container->get('embedded_payment.gateway.gateway');
        assert($gateway instanceof WC_Payment_Gateway);
        if (! $gateway->is_available()) {
            return;
        }

        $currentHash = $hashProvider->provideHash();
        $storedHash = $session->get($sessionHashKey);
        if ($storedHash === $currentHash) {
            return;
        }

        /**
         * There's no use updating a non-existent LIST.
         * Try to fetch it before passing it into the updater
         */
        try {
            $list = $provider->provide();
        } catch (CheckoutExceptionInterface $exception) {
            do_action(
                'payoneer-checkout.update_list_session_failed',
                ['exception' => $exception]
            );

            return;
        }

        $delegate = new FuncService([
            'checkout.list_session_persistor',
            'checkout.list_session_factory',
            'wc.customer',
            'wc.cart',
            'checkout.security_token',
        ], \Closure::fromCallable([$this, 'doUpdateListSession']));
        /** @psalm-suppress MixedFunctionCall * */
        $delegate($container)($list);

        /**
         * Update checkout hash since the LIST has now changed
         */
        $session->set($sessionHashKey, $currentHash);
    }

    /**
     * Performs a LIST update and relevant error handling by wrapping an existing LIST object
     * and providing it to a persistor
     *
     *
     * @param ListInterface $list
     * @param ListSessionPersistor $persistor
     * @param WcBasedUpdateCommandFactoryInterface $listCommandFactory
     * @param WC_Customer $wcCustomer
     * @param WC_Cart $cart
     * @param string $securityToken
     *
     * @return void
     * @throws CheckoutExceptionInterface
     */
    protected function doUpdateListSession(
        ListInterface $list,
        ListSessionPersistor $persistor,
        WcBasedUpdateCommandFactoryInterface $listCommandFactory,
        WC_Customer $wcCustomer,
        WC_Cart $cart,
        string $securityToken
    ) {
        /**
         * Now attempt to update the existing LIST
         */
        $updater = new UpdatingListSessionProvider(
            $listCommandFactory,
            new PassThroughListSessionProvider($list),
            $wcCustomer,
            $cart,
            $securityToken
        );
        try {
            $persistor->persist($updater->provide());
        } catch (CheckoutExceptionInterface $exception) {
            do_action(
                'payoneer-checkout.update_list_session_failed',
                ['exception' => $exception]
            );
        }
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
