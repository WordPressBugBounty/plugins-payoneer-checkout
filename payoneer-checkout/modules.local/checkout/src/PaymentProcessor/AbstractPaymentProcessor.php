<?php

namespace Inpsyde\PayoneerForWoocommerce\Checkout\PaymentProcessor;

use Inpsyde\PayoneerForWoocommerce\Checkout\MisconfigurationDetector\MisconfigurationDetectorInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\PaymentProcessor\PaymentProcessorInterface;
use Inpsyde\PayoneerSdk\Api\Command\ResponseValidator\InteractionCodeFailureInterface;
use WC_Order;

abstract class AbstractPaymentProcessor implements PaymentProcessorInterface
{
    /**
     * @var MisconfigurationDetectorInterface
     */
    protected $misconfigurationDetector;

    /**
     * @param MisconfigurationDetectorInterface $misconfigurationDetector
     */
    public function __construct(MisconfigurationDetectorInterface $misconfigurationDetector)
    {
        $this->misconfigurationDetector = $misconfigurationDetector;
    }

    abstract public function processPayment(WC_Order $order): array;
    /**
     * @param \Throwable $exception
     * @param string $fallback
     *
     * @return string
     * phpcs:disable WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
     * phpcs:disable Inpsyde.CodeQuality.NestingLevel.High
     */
    protected function produceErrorMessageFromException(
        \Throwable $exception,
        string $fallback
    ): string {

        if ($this->misconfigurationDetector->isCausedByMisconfiguration($exception)) {
            return __(
                'Failed to initialize Payoneer session. Payoneer Checkout is not configured properly.',
                'payoneer-checkout'
            );
        }

        $previous = $exception;
        do {
            if ($previous instanceof InteractionCodeFailureInterface) {
                $response = $previous->getSubject();
                $body = $response->getBody();
                $body->rewind();
                $json = json_decode((string)$body, true);
                if (! $json || ! isset($json['resultInfo'])) {
                    return $fallback;
                }

                return (string)$json['resultInfo'];
            }
        } while ($previous = $previous->getPrevious());

        return $fallback;
    }
}
