<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command;

use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\Error\InteractionErrorInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\Exception\CommandException;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\Exception\CommandExceptionInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListDeserializerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Payment\PaymentInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Payment\PaymentSerializerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Product\ProductSerializerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Client\ApiClientInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Client\DecodeJsonResponseBodyTrait;
use Syde\Vendor\Inpsyde\PayoneerSdk\Client\JsonCodecTrait;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\ResponseValidator\ResponseValidatorInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\PayoneerSdkExceptionInterface;
use RuntimeException;
/**
 * Command making CHARGE request for defined session.
 *
 * phpcs:disable Inpsyde.CodeQuality.ElementNameMinimalLength
 */
class ChargeCommand extends AbstractPaymentCommand implements ChargeCommandInterface
{
    use DecodeJsonResponseBodyTrait;
    use JsonCodecTrait;
    use PrepareRequestUrlPathTrait;
    /** @var PaymentSerializerInterface */
    protected $paymentSerializer;
    /**
     * @param array<string, InteractionErrorInterface> $errors
     * @param ApiClientInterface $apiClient
     * @param string $pathTemplate
     * @param ListDeserializerInterface $listDeserializer
     * @param PaymentSerializerInterface $paymentSerializer
     * @param ProductSerializerInterface $productSerializer
     * @param ResponseValidatorInterface $responseValidator
     */
    public function __construct(array $errors, ApiClientInterface $apiClient, string $pathTemplate, ListDeserializerInterface $listDeserializer, PaymentSerializerInterface $paymentSerializer, ProductSerializerInterface $productSerializer, ResponseValidatorInterface $responseValidator)
    {
        $this->paymentSerializer = $paymentSerializer;
        parent::__construct($productSerializer, $apiClient, $listDeserializer, $pathTemplate, $responseValidator, $errors);
    }
    /**
     * @inheritDoc
     */
    public function execute(): ListInterface
    {
        $requestPath = $this->prepareRequestUrlPath();
        $bodyParams = $this->prepareBodyParams();
        try {
            $response = $this->apiClient->post($requestPath, [], [], $bodyParams);
            $this->onResponse($response);
            $parsedBody = $this->decodeJsonResponseBody($response);
            return $this->listDeserializer->deserializeList($parsedBody);
        } catch (PayoneerSdkExceptionInterface|RuntimeException $exception) {
            if (!$exception instanceof CommandExceptionInterface) {
                $exception = new CommandException($this, sprintf('Failed to make charge for the list session, exception caught: %1$s.', (string) $exception), 0, $exception);
            }
            throw $exception;
        }
    }
    /**
     * @return array
     */
    protected function prepareBodyParams(): array
    {
        /**
         * @var PaymentInterface $this->payment
         */
        $serializedPayment = $this->paymentSerializer->serializePayment($this->payment);
        return ['payment' => $serializedPayment, 'products' => $this->prepareProducts()];
    }
    /**
     * @inheritDoc
     *
     * @return ListAwareCommandInterface&static
     */
    public function withLongId(string $longId): ListAwareCommandInterface
    {
        $newThis = clone $this;
        $newThis->longId = $longId;
        return $newThis;
    }
}
