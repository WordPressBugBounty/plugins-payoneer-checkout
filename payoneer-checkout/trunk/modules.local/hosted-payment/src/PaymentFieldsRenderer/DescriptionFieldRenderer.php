<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\HostedPayment\PaymentFieldsRenderer;

use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentFieldsRenderer\PaymentFieldsRendererInterface;

class DescriptionFieldRenderer implements PaymentFieldsRendererInterface
{
    /**
     * @var string
     */
    protected $description;

    public function __construct(
        string $description
    ) {

        $this->description = $description;
    }

    public function renderFields(): string
    {
        return  $this->description;
    }
}
