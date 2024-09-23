<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Api;

use Inpsyde\PayoneerSdk\Api\Command\ChargeCommandInterface;
use Inpsyde\PayoneerSdk\Api\Command\CreateListCommandInterface;
use Inpsyde\PayoneerSdk\Api\Command\PayoutCommandInterface;
use Inpsyde\PayoneerSdk\Api\Command\UpdateListCommandInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Callback\CallbackSerializerInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Customer\CustomerSerializerInterface;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListDeserializerInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Payment\PaymentSerializerInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Product\ProductSerializerInterface;
use Inpsyde\PayoneerSdk\Api\Entities\Style\StyleSerializerInterface;
use Inpsyde\PayoneerSdk\Api\Entities\System\SystemSerializerInterface;
use Inpsyde\PayoneerSdk\Api\Payoneer;
use Inpsyde\PayoneerSdk\Api\PayoneerInterface;
use Inpsyde\PayoneerSdk\Client\ApiClientInterface;

/**
 * Can create an object representing the Payoneer API.
 */
class PayoneerFactory implements PayoneerFactoryInterface
{
    /** @var ListDeserializerInterface */
    protected $listDeserializer;
    /** @var CustomerSerializerInterface */
    protected $customerSerializer;
    /** @var PaymentSerializerInterface */
    protected $paymentSerializer;
    /** @var CallbackSerializerInterface */
    protected $callbackSerializer;
    /** @var array */
    protected $headers;
    /** @var UpdateListCommandInterface */
    protected $updateListCommand;
    /** @var ChargeCommandInterface */
    protected $chargeCommand;
    /** @var PayoutCommandInterface */
    protected $payoutCommand;
    /** @var string */
    protected $integration;
    /** @var StyleSerializerInterface $styleSerializer */
    protected $styleSerializer;
    /** @var ProductSerializerInterface */
    protected $productSerializer;
    /**
     * @var SystemSerializerInterface
     */
    protected $systemSerializer;
    /**
     * @var CreateListCommandInterface
     */
    protected $createListCommand;

    public function __construct(
        ListDeserializerInterface $listDeserializer,
        CustomerSerializerInterface $customerSerializer,
        PaymentSerializerInterface $paymentSerializer,
        CallbackSerializerInterface $callbackSerializer,
        StyleSerializerInterface $styleSerializer,
        ProductSerializerInterface $productSerializer,
        SystemSerializerInterface $systemSerializer,
        array $headers,
        CreateListCommandInterface $createListCommand,
        UpdateListCommandInterface $updateListCommand,
        ChargeCommandInterface $chargeCommand,
        PayoutCommandInterface $payoutCommand,
        string $integration
    ) {

        $this->listDeserializer = $listDeserializer;
        $this->customerSerializer = $customerSerializer;
        $this->paymentSerializer = $paymentSerializer;
        $this->callbackSerializer = $callbackSerializer;
        $this->styleSerializer = $styleSerializer;
        $this->productSerializer = $productSerializer;
        $this->systemSerializer = $systemSerializer;
        $this->headers = $headers;
        $this->createListCommand = $createListCommand;
        $this->updateListCommand = $updateListCommand;
        $this->chargeCommand = $chargeCommand;
        $this->payoutCommand = $payoutCommand;
        $this->integration = $integration;
    }

    /**
     * @inheritDoc
     */
    public function createPayoneerForApi(ApiClientInterface $apiClient): PayoneerInterface
    {
        $product = new Payoneer(
            $apiClient,
            $this->listDeserializer,
            $this->styleSerializer,
            $this->headers,
            $this->createListCommand,
            $this->updateListCommand,
            $this->chargeCommand,
            $this->payoutCommand,
            $this->customerSerializer,
            $this->paymentSerializer,
            $this->callbackSerializer,
            $this->productSerializer,
            $this->systemSerializer,
            $this->integration
        );

        return $product;
    }
}
