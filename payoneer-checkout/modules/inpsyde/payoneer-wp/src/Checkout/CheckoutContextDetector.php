<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Wp\Checkout;

use WC_Blocks_Utils;
/**
 * Decides whether the current request is running in a checkout or block-cart
 * context.
 *
 * Encapsulates the page-ID resolution + block detection logic used by the
 * `wc.is_checkout` and `wc.is_block_cart` DI services (both bool and callable
 * variants). Callers that need late evaluation pass the detector's methods
 * as callables so the decision is re-run against current WP state on every
 * invocation.
 */
class CheckoutContextDetector
{
    /**
     * Returns true when the current request should be treated as a checkout.
     *
     * Matches on any of:
     *  - WooCommerce's native `is_checkout()` (classic/block page render).
     *  - `payoneer_order_pay` POST action (our custom order-pay flow).
     *  - Current (or referer-resolved) page equals the configured checkout page.
     *  - Current (or referer-resolved) page contains the `woocommerce/checkout` block.
     */
    public function isCheckout(bool $isStoreApiRequest): bool
    {
        //phpcs:disable WordPress.Security.NonceVerification.Missing
        if (is_checkout()) {
            return \true;
        }
        /**
         * Our custom order_pay logic isn't detected by WooCommerce as a
         * checkout request. Historical note retained from the pre-REST
         * factory implementation.
         */
        if (isset($_POST['action']) && $_POST['action'] === 'payoneer_order_pay') {
            return \true;
        }
        $currentPageId = $this->resolveCurrentPageId($isStoreApiRequest);
        if ($currentPageId <= 0) {
            return \false;
        }
        if ($currentPageId === (int) wc_get_page_id('checkout')) {
            return \true;
        }
        return $this->hasBlockInPage($currentPageId, 'woocommerce/checkout');
    }
    /**
     * Returns true when the current (or referer-resolved) page contains the
     * `woocommerce/cart` block.
     */
    public function isBlockCart(bool $isStoreApiRequest): bool
    {
        $currentPageId = $this->resolveCurrentPageId($isStoreApiRequest);
        if ($currentPageId <= 0) {
            return \false;
        }
        return $this->hasBlockInPage($currentPageId, 'woocommerce/cart');
    }
    /**
     * Resolve the current page ID.
     *
     * During a Store API REST request WP page context is not populated
     * (get_the_ID() returns 0 and is_page() returns false). We fall back to
     * url_to_postid( wp_get_referer() ) so we can still determine whether the
     * current request originated from a checkout/cart page.
     *
     * If the referer is missing (stripped by proxy, aggressive Referrer-Policy,
     * direct REST hit, or a permalink structure that `url_to_postid()` can't
     * parse), we return 0 and callers stay with today's behavior.
     */
    private function resolveCurrentPageId(bool $isStoreApiRequest): int
    {
        $pageId = (int) get_the_ID();
        if ($pageId > 0) {
            return $pageId;
        }
        if (!$isStoreApiRequest) {
            return 0;
        }
        $referer = wp_get_referer();
        if (!$referer) {
            return 0;
        }
        return (int) url_to_postid($referer);
    }
    /**
     * Thin wrapper around `WC_Blocks_Utils::has_block_in_page()` that also
     * handles pre-blocks WooCommerce versions (method may not exist).
     */
    private function hasBlockInPage(int $pageId, string $blockName): bool
    {
        return method_exists(WC_Blocks_Utils::class, 'has_block_in_page') && WC_Blocks_Utils::has_block_in_page($pageId, $blockName);
    }
}
