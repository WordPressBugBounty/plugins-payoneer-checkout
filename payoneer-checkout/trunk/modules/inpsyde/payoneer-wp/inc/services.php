<?php

declare (strict_types=1);
namespace Syde\Vendor;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Syde\Vendor\Dhii\Collection\MutableContainerInterface;
use Syde\Vendor\Dhii\Services\Factories\Alias;
use Syde\Vendor\Dhii\Services\Factories\Value;
use Syde\Vendor\Dhii\Services\Factory;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Core\Exception\PayoneerException;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Environment\WpEnvironmentInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Wp\NormalizingLocaleProviderISO639ISO3166;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Wp\LocaleProviderInterface;
use Syde\Vendor\WpOop\Containers\Options\BlogOptions;
use Syde\Vendor\WpOop\Containers\Options\SiteMeta;
return static function (): array {
    return ['wp.random_seed' => new Factory([], static function (): string {
        return \uniqid('', \true);
    }), 'wp.current_locale.wp' => new Factory([], static function (): string {
        return \determine_locale();
    }), 'wp.site.title' => new Factory([], static function (): string {
        return \get_bloginfo('name', 'display');
    }), 'wp.current_locale.fallback' => new Value(''), 'wp.current_locale.provider' => new Factory(['wp.current_locale.wp', 'wp.current_locale.fallback'], static function (string $internalLocale, string $defaultLocale): LocaleProviderInterface {
        return new NormalizingLocaleProviderISO639ISO3166($internalLocale, $defaultLocale);
    }), 'wp.current_locale.normalized' => new Factory(['wp.current_locale.provider'], static function (LocaleProviderInterface $localeProvider): string {
        return $localeProvider->provideLocale();
    }), 'wp.site_options.not_found_token' => new Alias('wp.random_seed'), 'wp.sites.current.id' => new Factory([], static function (): ?int {
        return \is_multisite() ? \get_current_blog_id() : null;
    }), 'wp.sites.current.options' => new Factory(['wp.sites.current.id', 'wp.site_options.not_found_token'], static function (?int $siteId, string $defaultToken): MutableContainerInterface {
        $product = new BlogOptions($siteId, $defaultToken);
        return $product;
    }), 'wp.sites.current.meta' => new Factory(['wp.sites.current.id'], static function (?int $siteId): MutableContainerInterface {
        $product = new SiteMeta($siteId);
        return $product;
    }), 'wp.http.wp_http_object' => new Factory([], static function (): \WP_Http {
        return \_wp_http_get_object();
    }), 'wp.admin_url' => new Factory([], static function (): string {
        return \admin_url();
    }), 'wp.is_admin' => new Factory([], static function (): bool {
        return \is_admin();
    }), 'wp.is_ajax' => new Factory([], static function (): bool {
        return \defined('DOING_AJAX') && \DOING_AJAX;
    }), 'wp.is_frontend_request' => new Factory(['wc'], static function (\WooCommerce $wooCommerce): bool {
        return (!\is_admin() || \defined('DOING_AJAX')) && !\defined('DOING_CRON') && !\defined('REST_REQUEST') && !$wooCommerce->is_rest_api_request();
    }), 'wp.site_url' => new Factory([], static function (): string {
        return \get_site_url(\get_current_blog_id());
    }), 'wp.is_debug' => new Value(\defined('WP_DEBUG') && \WP_DEBUG), 'wp.is_script_debug' => new Value(\defined('SCRIPT_DEBUG') && \SCRIPT_DEBUG), 'wp.user_id' => new Factory([], static function (): string {
        return (string) \get_current_user_id();
    }), 'wc' => new Factory([], static function (): \WooCommerce {
        if (!\did_action('woocommerce_init')) {
            throw new \RuntimeException('"wc" service was accessed before the "woocommerce_init" hook');
        }
        return \WC();
    }), 'wc.version' => new Factory(['core.wp_environment'], static function (WpEnvironmentInterface $wpEnvironment): string {
        return $wpEnvironment->getWcVersion();
    }), 'wc.session' => new Factory(['wc', 'wp.is_admin', 'wp.is_ajax'], static function (\WooCommerce $wooCommerce, bool $isAdmin, bool $isAjax): \WC_Session {
        if ($isAdmin && !$isAjax || !$wooCommerce->session instanceof \WC_Session) {
            throw new PayoneerException('WooCommerce session is not available.');
        }
        return $wooCommerce->session;
    }), 'wc.customer' => new Factory(['wc'], static function (\WooCommerce $wooCommerce): \WC_Customer {
        return $wooCommerce->customer;
    }), 'wc.cart' => new Factory(['wc'], static function (\WooCommerce $wooCommerce): \WC_Cart {
        return $wooCommerce->cart;
    }), 'wc.currency' => new Factory(['wc'], static function (): string {
        return get_woocommerce_currency();
    }), 'wc.price_decimals' => new Factory(['wc'], static function (): int {
        return \wc_get_price_decimals();
    }), 'wc.settings.price_include_tax' => new Factory(['wc'], static function (): bool {
        return \wc_prices_include_tax();
    }), 'wc.is_fragment_update' => new Factory([], static function (): bool {
        $wcAjaxAction = \filter_input(\INPUT_GET, 'wc-ajax', \FILTER_CALLBACK, ['options' => 'sanitize_text_field']);
        return $wcAjaxAction === 'update_order_review' || $wcAjaxAction === 'update_checkout';
    }), 'wc.is_checkout_pay_page' => new Factory(['wc'], static function (): bool {
        return is_checkout_pay_page();
    }), 'wc.is_order_received_page' => new Factory(['wc'], static function (): bool {
        return is_order_received_page();
    }), 'wc.order_under_payment' => new Factory(['wc.order_awaiting_payment', 'wc.pay_for_order_id'], static function (int $orderAwaitingPayment, int $payForOrderId): int {
        if ($payForOrderId) {
            return $payForOrderId;
        }
        return $orderAwaitingPayment;
    }), 'wc.order_awaiting_payment' => new Factory(['wc.session'], static function (\WC_Session $session): int {
        /** @var int|false $orderAwaitingPayment */
        $orderAwaitingPayment = $session->get('order_awaiting_payment');
        return (int) $orderAwaitingPayment;
    }), 'wc.pay_for_order_id' => new Factory(['wc'], static function (): int {
        return (int) \get_query_var('order-pay');
    }), 'wc.order_item_types_for_product' => new Factory([], static function (): array {
        return ['line_item', 'shipping', 'fee', 'coupon'];
    }), 'wc.ajax_url' => new Factory(['wc'], static function (\WooCommerce $wooCommerce): string {
        return $wooCommerce->ajax_url();
    }), 'wc.countries' => new Factory(['wc'], static function (\WooCommerce $wooCommerce): \WC_Countries {
        return $wooCommerce->countries;
    }), 'wc.shop_url' => new Factory([], static function (): string {
        return (string) \get_permalink(\wc_get_page_id('shop'));
    }), 'wc.admin_permission' => new Value('manage_woocommerce'), 'wc.hpos.is_enabled' => static function (): bool {
        if (!\method_exists(OrderUtil::class, 'custom_orders_table_usage_is_enabled')) {
            return \false;
        }
        /**
         * @psalm-var mixed $enabled
         */
        $enabled = OrderUtil::custom_orders_table_usage_is_enabled();
        //WooCommerce return types sometimes incorrect, better to make sure.
        return \is_bool($enabled) ? $enabled : \wc_string_to_bool((string) $enabled);
    }];
};
