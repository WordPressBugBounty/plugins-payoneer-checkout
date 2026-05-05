<?php

declare (strict_types=1);
namespace Syde\Vendor;

use Syde\Vendor\Dhii\Services\Factories\Constructor;
use Syde\Vendor\Dhii\Services\Factory;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Wp\Checkout\CheckoutContextDetector;
return static fn() => [
    'wc.checkout_context_detector' => new Constructor(CheckoutContextDetector::class),
    'wc.is_checkout' => new Factory(
        ['wc', 'wc.checkout_context_detector', 'wc.is_store_api_request'],
        /**
         * @param mixed $wc Unused; depended on only to force WooCommerce
         *                  bootstrap before is_checkout() runs.
         */
        static function ($wc, CheckoutContextDetector $detector, bool $isStoreApiRequest): bool {
            return $detector->isCheckout($isStoreApiRequest);
        }
    ),
    /**
     * Late-evaluated variant of `wc.is_checkout`. Returns a callable that,
     * each time it is invoked, re-runs the is-checkout decision against the
     * current WP state.
     *
     * Required by consumers injected into singleton services that may be
     * constructed before the `wp` action fires — otherwise the injected bool
     * would cache an incorrect `false`.
     */
    'wc.is_checkout.callable' => new Factory(
        ['wc.checkout_context_detector', 'wc.is_store_api_request'],
        /**
         * `wc.is_store_api_request` is stable per HTTP request (derived from
         * REQUEST_URI), so capturing it at factory resolution time is fine.
         * Only the WP page-context calls inside the detector need late binding.
         */
        static fn(CheckoutContextDetector $detector, bool $isStoreApiRequest): callable => static fn(): bool => $detector->isCheckout($isStoreApiRequest)
    ),
    'wc.is_block_cart' => new Factory(['wc.checkout_context_detector', 'wc.is_store_api_request'], static function (CheckoutContextDetector $detector, bool $isStoreApiRequest): bool {
        return $detector->isBlockCart($isStoreApiRequest);
    }),
    /**
     * Late-evaluated variant of `wc.is_block_cart`. See `wc.is_checkout.callable`.
     */
    'wc.is_block_cart.callable' => new Factory(['wc.checkout_context_detector', 'wc.is_store_api_request'], static fn(CheckoutContextDetector $detector, bool $isStoreApiRequest): callable => static fn(): bool => $detector->isBlockCart($isStoreApiRequest)),
    'wp.is_rest_api_request' => new Factory(['wc'], static function (\WooCommerce $wooCommerce) {
        global $wp_rewrite;
        \assert($wp_rewrite instanceof \WP_Rewrite);
        if ($wp_rewrite->using_permalinks()) {
            return $wooCommerce->is_rest_api_request();
        }
        /**
         * We really really wish to access raw data here.
         * Wea re also doing only string comparisons and will not use the data
         * for processing. Hence:
         * phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
         * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
         */
        return \preg_match('/\/index\.php\?rest_route=/', isset($_SERVER['REQUEST_URI']) ? \urldecode($_SERVER['REQUEST_URI']) : '') === 1;
        /**
         * phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
         * phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
         */
    }),
    'wc.session.is-available' => new Factory(['wc', 'wp.is_admin', 'wp.is_ajax'], static function (\WooCommerce $wooCommerce, bool $isAdmin, bool $isAjax): bool {
        if ($isAdmin && !$isAjax) {
            return \false;
        }
        return $wooCommerce->session instanceof \WC_Session;
    }),
    'wc.cart.is-available' => new Factory(['wc'], static fn(\WooCommerce $wooCommerce) => $wooCommerce->cart instanceof \WC_Cart),
    'wc.is_checkout_pay_page' => new Factory(['wc'], static function (): bool {
        return is_checkout_pay_page();
    }),
    'wc.is_order_received_page' => new Factory(['wc'], static function (): bool {
        return is_order_received_page();
    }),
];
