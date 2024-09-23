<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Checkout\CheckoutListSession\Controller;

use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\ListSessionPersistor;
use Inpsyde\PayoneerForWoocommerce\Checkout\ListSession\ListSessionProvider;
use Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use WC_Cart;
use WC_Customer;

class CheckoutListSessionController implements CheckoutListSessionControllerInterface
{
    /**
     * @var ListSessionProvider
     */
    protected $listSessionProvider;
    /**
     * @var ListSessionPersistor
     */
    protected $listSessionPersistor;

    public function __construct(
        ListSessionProvider $listSessionProvider,
        ListSessionPersistor $listSessionPersistor
    ) {

        $this->listSessionProvider = $listSessionProvider;
        $this->listSessionPersistor = $listSessionPersistor;
    }

    /**
     * @inheritDoc
     */
    public function updateListSessionFromCheckoutData(
        WC_Customer $customer,
        WC_Cart $cart
    ): ListInterface {

        $listSession = $this->listSessionProvider->provide();
        $this->listSessionPersistor->persist($listSession);
        return $listSession;
    }
}
