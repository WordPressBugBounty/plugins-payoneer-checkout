<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\Factory\Callback;

use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Gateway\Factory\SecurityHeader\SecurityHeaderFactoryInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Callback\CallbackFactoryInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Callback\CallbackInterface;
use Psr\Http\Message\UriInterface;
use WC_Order;

class WcOrderBasedCallbackFactory implements WcOrderBasedCallbackFactoryInterface
{
    /**
     * @var CallbackFactoryInterface
     */
    protected $callbackFactory;
    /**
     * @var UriInterface
     */
    protected $notificationUrl;
    /**
     * @var SecurityHeaderFactoryInterface
     */
    protected $securityHeaderFactory;
    /**
     * @var string
     */
    protected $listSecurityToken;

    /**
     * @param CallbackFactoryInterface $callbackFactory
     * @param UriInterface $notificationUrl
     * @param SecurityHeaderFactoryInterface $securityHeaderFactory
     * @param string $listSecurityToken
     */
    public function __construct(
        CallbackFactoryInterface $callbackFactory,
        UriInterface $notificationUrl,
        SecurityHeaderFactoryInterface $securityHeaderFactory,
        string $listSecurityToken
    ) {

        $this->callbackFactory = $callbackFactory;
        $this->notificationUrl = $notificationUrl;
        $this->securityHeaderFactory = $securityHeaderFactory;
        $this->listSecurityToken = $listSecurityToken;
    }

    /**
     * @inheritDoc
     */
    public function createCallback(WC_Order $order): CallbackInterface
    {
        $header = $this->securityHeaderFactory
            ->createSecurityHeader($this->listSecurityToken);
        $callback = $this->callbackFactory->createCallback(
            $order->get_checkout_order_received_url(),
            $order->get_view_order_url(),
            $order->get_checkout_payment_url(),
            (string)$this->notificationUrl,
            [$header]
        );

        return $callback;
    }
}
