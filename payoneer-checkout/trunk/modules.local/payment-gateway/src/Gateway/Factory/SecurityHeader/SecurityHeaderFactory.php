<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\Factory\SecurityHeader;

use Inpsyde\PayoneerSdk\Api\Entities\Header\HeaderFactoryInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Header\HeaderInterface;

class SecurityHeaderFactory implements SecurityHeaderFactoryInterface
{
    /**
     * @var HeaderFactoryInterface
     */
    protected $headerFactory;

    /**
     * @var string
     */
    protected $securityHeaderName;

    public function __construct(HeaderFactoryInterface $headerFactory, string $securityHeaderName)
    {
        $this->headerFactory = $headerFactory;
        $this->securityHeaderName = $securityHeaderName;
    }

    /**
     * @inheritDoc
     */
    public function createSecurityHeader(string $headerValue): HeaderInterface
    {
        return $this->headerFactory
            ->createHeader($this->securityHeaderName, $headerValue);
    }
}
