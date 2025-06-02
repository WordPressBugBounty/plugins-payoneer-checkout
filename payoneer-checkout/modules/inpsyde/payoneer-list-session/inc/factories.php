<?php

declare (strict_types=1);
namespace Syde\Vendor;

use Syde\Vendor\Psr\Container\ContainerInterface;
return static function (): array {
    return [
        /**
         * Maintaining the state of a transaction (with constantly updating cart/billing data)
         * requires a "storage backend" to keep track of transaction data across multiple requests.
         * Therefore, we use this service to determine whether
         * we have a WC_Session or WC_Order to write to.
         */
        'list_session.can_persist' => static function (ContainerInterface $container): bool {
            $isPaymentPage = (bool) $container->get('wc.is_checkout_pay_page');
            if ($isPaymentPage) {
                return \true;
            }
            return (bool) $container->get('wc.session.is-available');
        },
        'list_session.can_create' => static function (ContainerInterface $container): bool {
            $orderUnderPayment = $container->get('wc.order_under_payment');
            if ($orderUnderPayment) {
                return \true;
            }
            if (!\did_action('woocommerce_init')) {
                return \false;
            }
            if (!$container->get('wc.cart.is-available')) {
                return \false;
            }
            $cart = $container->get('wc.cart');
            \assert($cart instanceof \WC_Cart);
            return (float) $cart->get_total('') > 0;
        },
        'list_session.can_try_create_list' => static fn(ContainerInterface $container) => $container->get('list_session.can_persist') && $container->get('list_session.can_create'),
    ];
};
