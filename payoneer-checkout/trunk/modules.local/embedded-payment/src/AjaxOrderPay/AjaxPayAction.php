<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\AjaxOrderPay;

use WC_Payment_Gateway;

class AjaxPayAction
{
    /**
     * @var WC_Payment_Gateway
     */
    protected $paymentGateway;

    public function __construct(\WC_Payment_Gateway $paymentGateway)
    {
        $this->paymentGateway = $paymentGateway;
    }

    /**
     * phpcs:disable WordPress.Security.NonceVerification.Missing
     * phpcs:disable Inpsyde.CodeQuality.NoElse.ElseFound
     * phpcs:disable WordPress.WP.I18n.TextDomainMismatch
     * @see \WC_Form_Handler::pay_action()
     * @param \WC_Order $order
     * @param \WC_Customer $customer
     * @param array $data form POST data
     *
     * @return bool
     */
    public function __invoke(\WC_Order $order, \WC_Customer $customer, array $data): bool
    {
        do_action('woocommerce_before_pay_action', $order);

        $customer->set_props(
            [
                'billing_country' => $order->get_billing_country()
                    ? $order->get_billing_country() : null,
                'billing_state' => $order->get_billing_state()
                    ? $order->get_billing_state() : null,
                'billing_postcode' => $order->get_billing_postcode()
                    ? $order->get_billing_postcode() : null,
                'billing_city' => $order->get_billing_city()
                    ? $order->get_billing_city() : null,
            ]
        );
        $customer->save();

        if (! empty($data['terms-field']) && empty($data['terms'])) {
            wc_add_notice(
                __(
                    'Please read and accept the terms and conditions to proceed with your order.',
                    'woocommerce'
                ),
                'error'
            );

            return false;
        }

        // Update payment method.
        if ($order->needs_payment()) {
            try {
                $order->set_payment_method($this->paymentGateway->id);
                $order->set_payment_method_title($this->paymentGateway->get_title());
                $order->save();

                $this->paymentGateway->validate_fields();

                if (0 === wc_notice_count('error')) {
                    $orderId = $order->get_id();

                    $result = $this->paymentGateway->process_payment($orderId);

                    // Redirect to success/confirmation/payment page.
                    if (isset($result['result']) && 'success' === $result['result']) {
                        return true;
                    }
                }
            } catch (\Exception $exception) {
                wc_add_notice($exception->getMessage(), 'error');
                return false;
            }
        } else {
            // No payment was required for order.
            $order->payment_complete();

            return true;
        }

        do_action('woocommerce_after_pay_action', $order);

        return false;
    }
}
