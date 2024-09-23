<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer;

use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentFieldsRenderer\PaymentFieldsRendererInterface;

/**
 * Decorates another PaymentFieldsRendererInterface and additionally outputs
 * form fields for transporting data from the JS checkout widget
 */
class InteractionCodeFieldRenderer implements PaymentFieldsRendererInterface
{
    /**
     * @var string
     */
    protected $interactionCodeInputName;
    /**
     * @var string
     */
    private $interactionReasonInputName;

    public function __construct(
        string $interactionCodeInputName,
        string $interactionReasonInputName
    ) {

        $this->interactionCodeInputName = $interactionCodeInputName;
        $this->interactionReasonInputName = $interactionReasonInputName;
    }

    public function renderFields(): string
    {
        return '<input type="hidden" name="' . $this->interactionCodeInputName . '" value="">'
               . '<input type="hidden" name="' . $this->interactionReasonInputName . '" value="">';
    }
}
