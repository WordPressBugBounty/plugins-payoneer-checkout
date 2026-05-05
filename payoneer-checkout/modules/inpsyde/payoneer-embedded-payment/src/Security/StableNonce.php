<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\Security;

/**
 * Auth-state-independent nonce for the payment-unsuccessful endpoint.
 *
 * Our gateway keeps the customer on the checkout page for 3DS authentication
 * after process_checkout returns success — a flow WC doesn't anticipate.
 * If "create an account" was ticked, WC logs the user in during that same
 * request, making all uid-bound nonces on the page stale. The follow-up
 * AJAX (e.g. payment-unsuccessful after a failed 3DS challenge) then runs
 * with the new user's cookies and the original nonce is rejected.
 *
 * These helpers always mint and verify with uid=0 and an empty session token,
 * so the nonce survives the guest-to-logged-in transition. The nonce action
 * and tick window still limit it to a short lifetime and a specific endpoint;
 * user binding is unnecessary because the endpoint authorizes via the order's
 * longId/transaction ID carried in the request body.
 */
final class StableNonce
{
    public static function create(string $action): string
    {
        return self::compute(self::tick($action), $action);
    }
    public static function verify(string $nonce, string $action): bool
    {
        if ($nonce === '') {
            return \false;
        }
        $tick = self::tick($action);
        if (hash_equals(self::compute($tick, $action), $nonce)) {
            return \true;
        }
        return hash_equals(self::compute($tick - 1, $action), $nonce);
    }
    private static function compute(float $tick, string $action): string
    {
        return substr(wp_hash($tick . '|' . $action . '|0|', 'nonce'), -12, 10);
    }
    private static function tick(string $action): float
    {
        return (float) wp_nonce_tick($action);
    }
}
