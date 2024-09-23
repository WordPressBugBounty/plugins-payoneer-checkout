<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Api;

use Inpsyde\PayoneerSdk\Api\PayoneerInterface;
use Inpsyde\PayoneerSdk\Client\ApiClientInterface;
use RuntimeException;

/**
 * Something that can create an object representing the Payoneer API.
 */
interface PayoneerFactoryInterface
{
    /**
     * Creates a Payoneer instance for an API.
     *
     * @param ApiClientInterface $apiClient The client used to perform requests to the API.
     *
     * @return PayoneerInterface The new Payoneer instance.
     *
     * @throws RuntimeException If problem creating.
     */
    public function createPayoneerForApi(ApiClientInterface $apiClient): PayoneerInterface;
}
