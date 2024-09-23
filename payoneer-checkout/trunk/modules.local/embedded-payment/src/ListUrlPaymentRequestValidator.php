<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\EmbeddedPayment;

use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\ListSessionProvider;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Exception\PaymentGatewayException;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentGateway;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\PaymentRequestValidatorInterface;

class ListUrlPaymentRequestValidator implements PaymentRequestValidatorInterface
{
    /**
     * @var string
     */
    protected $inputName;
    /**
     * @var ?PaymentRequestValidatorInterface
     */
    protected $validator = null;
    /**
     * @var ListSessionProvider
     */
    protected $listSessionProvider;

    public function __construct(
        string $inputName,
        ListSessionProvider $listSessionProvider,
        PaymentRequestValidatorInterface $validator = null
    ) {

        $this->inputName = $inputName;
        $this->listSessionProvider = $listSessionProvider;
        $this->validator = $validator;
    }

    public function assertIsValid(\WC_Order $wcOrder, PaymentGateway $gateway): void
    {
        $postedListUrl = filter_input(
            INPUT_POST,
            $this->inputName,
            FILTER_SANITIZE_URL
        );
        $currentListUrl = $this->listSessionProvider->provide()->getLinks()['self'];
        if ($postedListUrl !== $currentListUrl) {
            throw new PaymentGatewayException(
                __(
                    'List URL mismatch. This could mean your payment session has expired. Please try again',
                    'payoneer-checkout'
                )
            );
        }

        $this->validator && $this->validator->assertIsValid($wcOrder, $gateway);
    }
}
