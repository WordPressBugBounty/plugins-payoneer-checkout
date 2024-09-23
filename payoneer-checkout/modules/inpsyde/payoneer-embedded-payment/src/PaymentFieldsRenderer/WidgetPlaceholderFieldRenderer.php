<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer;

use Syde\Vendor\Inpsyde\PaymentGateway\PaymentFieldsRendererInterface;
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
     * @var string
     */
    protected $paymentFieldsDropInComponentAttribute;
    /**
     * @var string
     */
    protected $paymentFieldsDropInComponent;
    /**
     * @param string $paymentFieldsContainerId ID of the HTML element used as a container for
     *          payment fields.
     */
    public function __construct(string $paymentFieldsContainerId, string $paymentFieldsDropInComponentAttribute, string $paymentFieldsDropInComponent)
    {
        $this->paymentFieldsContainerId = $paymentFieldsContainerId;
        $this->paymentFieldsDropInComponentAttribute = $paymentFieldsDropInComponentAttribute;
        $this->paymentFieldsDropInComponent = $paymentFieldsDropInComponent;
    }
    /**
     * @inheritDoc
     */
    public function renderFields(): string
    {
        //We place a <p></p> to differentiate from the <div></div> iframe
        return sprintf(
            '<div class="%1$s" %3$s="%4$s"><p>%2$s</p></div>',
            esc_attr($this->paymentFieldsContainerId),
            //TODO Reuse another message here. The actual HPP flow uses a merchant-configurable string
            /* translators: Text used for the hosted-payment-page-fallback in embedded mode. */
            __('Payment will be done on a dedicated payment page', 'payoneer-checkout'),
            $this->paymentFieldsDropInComponentAttribute,
            $this->paymentFieldsDropInComponent
        );
    }
}
