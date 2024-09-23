<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\EmbeddedPayment;

use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Exception\PaymentGatewayException;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentGateway;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentRequestValidatorInterface;

/**
 * Checks if the interaction code is present in POST data
 * Optionally decorates an existing validator
 */
class InteractionCodePaymentRequestValidator implements PaymentRequestValidatorInterface
{
    /**
     * @var string
     */
    protected $interactionCodeInputName;
    /**
     * @var ?PaymentRequestValidatorInterface
     */
    protected $validator = null;

    public function __construct(
        string $interactionCodeInputName,
        PaymentRequestValidatorInterface $validator = null
    ) {

        $this->interactionCodeInputName = $interactionCodeInputName;
        $this->validator = $validator;
    }

    public function assertIsValid(\WC_Order $wcOrder, PaymentGateway $gateway): void
    {
        $interactionCode = filter_input(
            INPUT_POST,
            $this->interactionCodeInputName,
            FILTER_SANITIZE_STRING
        );
        if ($interactionCode !== 'PROCEED') {
            throw new PaymentGatewayException(
                __(
                    'Unexpected interaction code received from the payment processing service',
                    'payoneer-checkout'
                )
            );
        }

        $this->validator && $this->validator->assertIsValid($wcOrder, $gateway);
    }
}
