<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Checkout\PaymentFieldsRenderer;

use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentFieldsRenderer\PaymentFieldsRendererInterface;

class CompoundPaymentFieldsRenderer implements PaymentFieldsRendererInterface
{
    /**
     * @var PaymentFieldsRendererInterface[]
     */
    protected $renderers;

    public function __construct(PaymentFieldsRendererInterface ...$renderers)
    {
        $this->renderers = $renderers;
    }

    public function renderFields(): string
    {
        $result = '';
        foreach ($this->renderers as $renderer) {
            $result .= $renderer->renderFields();
        }

        return $result;
    }
}
