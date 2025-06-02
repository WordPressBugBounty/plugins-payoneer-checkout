<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession;

use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\ListSession\OrderBasedListSessionFactory;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\ListSession\WcBasedListSessionFactoryInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\PayoneerIntegrationTypes;
class ApiListSessionProvider implements ListSessionProvider
{
    /**
     * @var WcBasedListSessionFactoryInterface
     */
    private $checkoutFactory;
    /**
     * @var OrderBasedListSessionFactory
     */
    private $listFactory;
    /**
     * @var PayoneerIntegrationTypes::* $integrationType
     */
    private $integrationType;
    /**
     * @var bool
     */
    private bool $canCreateList;
    /**
     * @var string|null
     */
    private $hostedVersion;
    /**
     * @param WcBasedListSessionFactoryInterface $checkoutFactory
     * @param OrderBasedListSessionFactory $listFactory
     * @param string $integrationType $integrationType
     * @param bool $canCreateList
     * @param string|null $hostedVersion
     *
     * @psalm-param PayoneerIntegrationTypes::* $integrationType
     */
    public function __construct(WcBasedListSessionFactoryInterface $checkoutFactory, OrderBasedListSessionFactory $listFactory, string $integrationType, bool $canCreateList, string $hostedVersion = null)
    {
        $this->checkoutFactory = $checkoutFactory;
        $this->listFactory = $listFactory;
        $this->integrationType = $integrationType;
        $this->hostedVersion = $hostedVersion;
        $this->canCreateList = $canCreateList;
    }
    public function provide(ContextInterface $context): ListInterface
    {
        if (!$this->canCreateList) {
            throw new \RuntimeException('Cannot create List session.');
        }
        if ($context instanceof CheckoutContext) {
            $totals = $context->getCart()->get_total('edit');
            if (!$totals) {
                throw new \RuntimeException(sprintf('Invalid totals amount in %s', __CLASS__));
            }
            $list = $this->checkoutFactory->createList($context->getCustomer(), $context->getCart(), $this->integrationType, $this->hostedVersion);
            $context->offsetSet('list_just_created', \true);
            return $list;
        }
        if ($context instanceof PaymentContext) {
            $list = $this->listFactory->createList($context->getOrder(), $this->integrationType, $this->hostedVersion);
            $context->offsetSet('list_just_created', \true);
            return $list;
        }
        throw new \RuntimeException(sprintf('Unknown Context passed to %s', __CLASS__));
    }
}
