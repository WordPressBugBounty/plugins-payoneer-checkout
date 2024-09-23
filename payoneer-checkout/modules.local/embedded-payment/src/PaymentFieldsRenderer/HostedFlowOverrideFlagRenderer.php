<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer;

use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentFieldsRenderer\PaymentFieldsRendererInterface;

class HostedFlowOverrideFlagRenderer implements PaymentFieldsRendererInterface
{
    /**
     * @var string
     */
    protected $inputName;

    public function __construct(
        string $inputName
    ) {

        $this->inputName = $inputName;
    }

    public function renderFields(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="true">',
            esc_attr($this->inputName)
        );
    }
}
