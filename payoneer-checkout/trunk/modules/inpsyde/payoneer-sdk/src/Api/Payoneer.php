<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerSdk\Api;

use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\ChargeCommandInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\CreateListCommandInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\PayoutCommandInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\UpdateListCommandInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Callback\CallbackInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Callback\CallbackSerializerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Customer\CustomerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Customer\CustomerSerializerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListDeserializerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Payment\PaymentInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Payment\PaymentSerializerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Style\StyleInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Style\StyleSerializerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Product\ProductInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Product\ProductSerializerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\System\SystemInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\System\SystemSerializerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Client\ApiClientExceptionInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Client\ApiClientInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Client\DecodeJsonResponseBodyTrait;
use RuntimeException;
class Payoneer implements PayoneerInterface
{
    use DecodeJsonResponseBodyTrait;
    /**
     * @var ListDeserializerInterface Service able to create a List instance from array.
     */
    protected $listDeserializer;
    /**
     * @var ApiClientInterface Service able to send API requests.
     */
    protected $apiClient;
    /**
     * @var CreateListCommandInterface
     */
    protected $listCommand;
    /**
     * @var UpdateListCommandInterface A command updating a session.
     */
    protected $updateCommand;
    /**
     * @var ChargeCommandInterface A command making charge for a session.
     */
    protected $chargeCommand;
    /**
     * @var PayoutCommandInterface
     */
    protected $payoutCommand;
    /**
     * @var string
     */
    protected $integration;
    /**
     * @var CustomerSerializerInterface
     */
    protected $customerSerializer;
    /**
     * @var PaymentSerializerInterface
     */
    protected $paymentSerializer;
    /**
     * @var CallbackSerializerInterface
     */
    protected $callbackSerializer;
    /**
     * @var StyleSerializerInterface
     */
    protected $styleSerializer;
    /**
     * @var ProductSerializerInterface
     */
    protected $productSerializer;
    /**
     * @var array
     */
    protected $headers;
    /**
     * @var SystemSerializerInterface
     */
    protected $systemSerializer;
    /**
     * @param ApiClientInterface $apiClient
     * @param ListDeserializerInterface $listDeserializer
     * @param StyleSerializerInterface $styleSerializer
     * @param array $headers
     * @param CreateListCommandInterface $listCommand
     * @param UpdateListCommandInterface $updateCommand
     * @param ChargeCommandInterface $chargeCommand
     * @param PayoutCommandInterface $payoutCommand
     * @param CustomerSerializerInterface $customerSerializer
     * @param PaymentSerializerInterface $paymentSerializer
     * @param CallbackSerializerInterface $callbackSerializer
     * @param ProductSerializerInterface $productSerializer
     * @param SystemSerializerInterface $systemSerializer
     * @param string $integration
     */
    public function __construct(ApiClientInterface $apiClient, ListDeserializerInterface $listDeserializer, StyleSerializerInterface $styleSerializer, array $headers, CreateListCommandInterface $listCommand, UpdateListCommandInterface $updateCommand, ChargeCommandInterface $chargeCommand, PayoutCommandInterface $payoutCommand, CustomerSerializerInterface $customerSerializer, PaymentSerializerInterface $paymentSerializer, CallbackSerializerInterface $callbackSerializer, ProductSerializerInterface $productSerializer, SystemSerializerInterface $systemSerializer, string $integration)
    {
        $this->apiClient = $apiClient;
        $this->listDeserializer = $listDeserializer;
        $this->listCommand = $listCommand;
        $this->updateCommand = $updateCommand;
        $this->chargeCommand = $chargeCommand;
        $this->payoutCommand = $payoutCommand;
        $this->customerSerializer = $customerSerializer;
        $this->paymentSerializer = $paymentSerializer;
        $this->callbackSerializer = $callbackSerializer;
        $this->integration = $integration;
        $this->styleSerializer = $styleSerializer;
        $this->productSerializer = $productSerializer;
        $this->headers = $headers;
        $this->systemSerializer = $systemSerializer;
    }
    /**
     * @inheritDoc
     */
    public function createList(string $transactionId, string $country, CallbackInterface $callback, CustomerInterface $customer, PaymentInterface $payment, StyleInterface $style, array $views, string $operationType, array $products, SystemInterface $system, string $division = null, bool $allowDelete = \false): ListInterface
    {
        $requestBody = $this->prepareRequestBody($transactionId, $country, $callback, $customer, $payment, $style, $operationType, $products, $system, $division, $allowDelete);
        $queryParams = $views ? ['view' => $views] : [];
        try {
            $response = $this->apiClient->post('lists', $this->headers, $queryParams, $requestBody);
            $responseBodyParsed = $this->decodeJsonResponseBody($response);
        } catch (ApiClientExceptionInterface|RuntimeException $exception) {
            $messageBase = 'Failed to initiate a new list session from payload';
            throw new OperationFailedException($messageBase, ['resultInfo' => $exception->getMessage()], $requestBody);
        }
        $this->validateSessionStatus($responseBodyParsed, $requestBody);
        return $this->listDeserializer->deserializeList($responseBodyParsed);
    }
    /**
     * Quick&dirty solution to leaking sensitive data into logs via exceptions
     * Works for now, might have to be re-assessed later.
     * @psalm-suppress MixedArrayAccess
     * @psalm-suppress MixedArrayAssignment
     * @param array $payload
     *
     * @return array
     */
    protected function redactSensitiveData(array $payload): array
    {
        $redacted = '*****';
        $payload['customer']['email'] && $payload['customer']['email'] = $redacted;
        $payload['customer']['phones'] && $payload['customer']['phones'] = [$redacted => []];
        isset($payload['customer']['registration']['id']) && $payload['customer']['registration']['id'] = $redacted;
        isset($payload['customer']['addresses']['billing']['street']) && $payload['customer']['addresses']['billing']['street'] = $redacted;
        isset($payload['customer']['addresses']['billing']['name']['firstName']) && $payload['customer']['addresses']['billing']['name']['firstName'] = $redacted;
        isset($payload['customer']['addresses']['billing']['name']['lastName']) && $payload['customer']['addresses']['billing']['name']['lastName'] = $redacted;
        isset($payload['customer']['addresses']['shipping']['street']) && $payload['customer']['addresses']['shipping']['street'] = $redacted;
        isset($payload['customer']['addresses']['shipping']['name']['firstName']) && $payload['customer']['addresses']['shipping']['name']['firstName'] = $redacted;
        isset($payload['customer']['addresses']['shipping']['name']['lastName']) && $payload['customer']['addresses']['shipping']['name']['lastName'] = $redacted;
        isset($payload['customer']['name']['firstName']) && $payload['customer']['name']['firstName'] = $redacted;
        isset($payload['customer']['name']['lastName']) && $payload['customer']['name']['lastName'] = $redacted;
        return $payload;
    }
    /**
     * @param string $transactionId
     * @param string $country
     * @param CallbackInterface $callback
     * @param CustomerInterface $customer
     * @param PaymentInterface $payment
     * @param StyleInterface $style
     * @param string $operationType
     * @param ProductInterface[] $products
     * @param SystemInterface $system
     * @param string|null $division
     * @param bool $allowDelete
     *
     * @return array
     */
    protected function prepareRequestBody(string $transactionId, string $country, CallbackInterface $callback, CustomerInterface $customer, PaymentInterface $payment, StyleInterface $style, string $operationType, array $products, SystemInterface $system, string $division = null, bool $allowDelete = \false): array
    {
        $productsData = array_map([$this->productSerializer, 'serializeProduct'], $products);
        $body = ['integration' => $this->integration, 'transactionId' => $transactionId, 'country' => $country, 'customer' => $this->customerSerializer->serializeCustomer($customer), 'payment' => $this->paymentSerializer->serializePayment($payment), 'callback' => $this->callbackSerializer->serializeCallback($callback), 'operationType' => $operationType, 'products' => $productsData, 'system' => $this->systemSerializer->serializeSystem($system), 'allowDelete' => $allowDelete];
        if ($division !== null) {
            $body['division'] = $division;
        }
        $serializedStyle = $this->styleSerializer->serializeStyle($style);
        if ($serializedStyle) {
            $body['style'] = $serializedStyle;
        }
        return $body;
    }
    /**
     * @inheritDoc
     */
    public function getListCommand(): CreateListCommandInterface
    {
        return $this->listCommand;
    }
    /**
     * @inheritDoc
     */
    public function getUpdateCommand(): UpdateListCommandInterface
    {
        return $this->updateCommand;
    }
    /**
     * @inheritDoc
     */
    public function getChargeCommand(): ChargeCommandInterface
    {
        return $this->chargeCommand;
    }
    /**
     * @inheritDoc
     */
    public function getPayoutCommand(): PayoutCommandInterface
    {
        return $this->payoutCommand;
    }
    /**
     * Throws if response data indicates session creating was failed.
     *
     * @param array $responseBodyParsed
     * @param array $requestBody
     *
     * @throws ApiException
     */
    protected function validateSessionStatus(array $responseBodyParsed, array $requestBody): void
    {
        $statusCode = $responseBodyParsed['status']['code'] ?? '';
        if ($statusCode !== 'listed') {
            $messageBase = 'Failed to initiate a new LIST session.';
            throw new OperationFailedException($messageBase, $responseBodyParsed, $this->redactSensitiveData($requestBody));
        }
    }
}
