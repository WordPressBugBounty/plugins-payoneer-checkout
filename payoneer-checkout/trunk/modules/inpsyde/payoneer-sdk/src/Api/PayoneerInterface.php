<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerSdk\Api;

use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\ChargeCommandInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\CreateListCommandInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\PayoutCommandInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\UpdateListCommandInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Callback\CallbackInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Customer\CustomerInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Payment\PaymentInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Style\StyleInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\Product\ProductInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\System\SystemInterface;
/**
 * Represents the Payoneer API.
 */
interface PayoneerInterface
{
    /**
     * Initiate a new payment session.
     *
     * @param string $transactionId Identifier of the transaction given by merchant.
     * @param string $country Code of the country where payment originates.
     * @param CallbackInterface $callback Payment-related links in the merchant's store.
     * @param CustomerInterface $customer Customer's data.
     * @param PaymentInterface $payment Payment details.
     * @param StyleInterface $style Payment widget appearance settings object.
     * @param array<int, string> $views Payment form views should be returned from the API (HTML, JSON or both).
     * @param string $operationType Type of operation LIST session is initialized for.
     * @param ProductInterface[] $products Products to be purchased.
     * @param SystemInterface $system System information.
     * @param string|null $division Division name of this transaction
     * @param bool $allowDelete
     *
     * @return ListInterface
     *
     * @throws ApiExceptionInterface
     */
    public function createList(string $transactionId, string $country, CallbackInterface $callback, CustomerInterface $customer, PaymentInterface $payment, StyleInterface $style, array $views, string $operationType, array $products, SystemInterface $system, ?string $division = null, bool $allowDelete = \false): ListInterface;
    /**
     * @return CreateListCommandInterface
     */
    public function getListCommand(): CreateListCommandInterface;
    /**
     * @return ChargeCommandInterface
     */
    public function getChargeCommand(): ChargeCommandInterface;
    /**
     * @return UpdateListCommandInterface
     */
    public function getUpdateCommand(): UpdateListCommandInterface;
    /**
     * @return PayoutCommandInterface
     */
    public function getPayoutCommand(): PayoutCommandInterface;
}
