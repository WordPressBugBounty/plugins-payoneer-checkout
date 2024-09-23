<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer;

use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentFieldsRenderer\PaymentFieldsRendererInterface;

/**
 * Render payment fields that should be displayed on checkout.
 */
class WidgetPlaceholderFieldRenderer implements PaymentFieldsRendererInterface
{
    /**
     * ID of the HTML element used as a container for payment fields.
     *
     * @var string
     */
    protected $paymentFieldsContainerId;

    /**
     * @param string $paymentFieldsContainerId ID of the HTML element used as a container for
     *          payment fields.
     */
    public function __construct(
        string $paymentFieldsContainerId
    ) {

        $this->paymentFieldsContainerId = $paymentFieldsContainerId;
    }

    /**
     * @inheritDoc
     */
    public function renderFields(): string
    {
        //We place a <p></p> to differentiate from the <div></div> iframe
        return sprintf(
            '<div id="%1$s"><p>%2$s</p></div>',
            esc_attr($this->paymentFieldsContainerId),
            __('Payment will be done on a dedicated payment page', 'payoneer-checkout')
        );
    }
}
