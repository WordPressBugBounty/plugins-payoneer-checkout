<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession;

use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
class ListSessionManagerProxy implements ListSessionProvider, ListSessionPersistor
{
    /**
     * @var callable():ListSessionManager
     */
    private $factory;
    /**
     * @param callable():ListSessionManager $factory
     */
    public function __construct(callable $factory)
    {
        $this->factory = $factory;
    }
    private function ensureManager(): ListSessionManager
    {
        static $manager;
        if (!$manager) {
            $manager = ($this->factory)();
        }
        assert($manager instanceof ListSessionManager);
        return $manager;
    }
    public function persist(?ListInterface $list, ContextInterface $context): bool
    {
        return $this->ensureManager()->persist($list, $context);
    }
    public function provide(ContextInterface $context): ListInterface
    {
        return $this->ensureManager()->provide($context);
    }
}
