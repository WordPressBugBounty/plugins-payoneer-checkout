<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Client;

use Inpsyde\PayoneerSdk\Client\ApiClient;
use Inpsyde\PayoneerSdk\Client\ApiClientInterface;
use Inpsyde\PayoneerSdk\Client\TokenAwareInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * Can create a client for an API.
 */
class ClientFactory implements ClientFactoryInterface
{
    /** @var ClientInterface */
    protected $httpClient;
    /** @var RequestFactoryInterface */
    protected $requestFactory;
    /** @var StreamFactoryInterface */
    protected $streamFactory;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory
    ) {

        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    /**
     * @inheritDoc
     */
    public function createClientForApi(UriInterface $baseUrl, TokenAwareInterface $tokenProvider): ApiClientInterface
    {
        $product = new ApiClient(
            $this->httpClient,
            $this->requestFactory,
            $baseUrl,
            $this->streamFactory,
            $tokenProvider
        );

        return $product;
    }
}
