<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession;

use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListInterface;
use RuntimeException;
class Runner implements ListSessionProvider, ListSessionPersistor
{
    /**
     * @var array<ListSessionMiddleware|ListSessionProvider|ListSessionPersistor>
     */
    private $middlewares;
    /**
     * @param array<ListSessionMiddleware|ListSessionProvider|ListSessionPersistor> $middlewares
     */
    public function __construct(array $middlewares)
    {
        $this->middlewares = $middlewares;
    }
    public function persist(?ListInterface $list, ContextInterface $context): bool
    {
        /** @var ListSessionMiddleware|ListSessionProvider|ListSessionPersistor $middleware */
        $middleware = current($this->middlewares);
        next($this->middlewares);
        /**
         * We only process persistor middlewares. So if we have a wrong one,
         * skip it by calling the handler again, moving the cursor forward
         */
        if ($middleware instanceof ListSessionMiddleware && !$middleware instanceof ListSessionPersistorMiddleware) {
            return $this->persist($list, $context);
        }
        /**
         * Skip providers as well
         */
        if ($middleware instanceof ListSessionProvider) {
            return $this->persist($list, $context);
        }
        if ($middleware instanceof ListSessionPersistorMiddleware) {
            return $middleware->persist($list, $context, $this);
        }
        if ($middleware instanceof ListSessionPersistor) {
            return $middleware->persist($list, $context);
        }
        throw new RuntimeException(sprintf('Invalid middleware queue entry: %s. Middleware must either be callable or implement %s.', get_class($middleware), ListSessionPersistorMiddleware::class));
    }
    public function provide(ContextInterface $context): ListInterface
    {
        $middleware = current($this->middlewares);
        if ($middleware === \false) {
            throw new RuntimeException('Failed to provide List session, no suitable provider found.');
        }
        next($this->middlewares);
        /**
         * We only process provider middlewares. So if we have a wrong one,
         * skip it by calling the handler again, moving the cursor forward
         */
        if ($middleware instanceof ListSessionMiddleware && !$middleware instanceof ListSessionProviderMiddleware) {
            return $this->provide($context);
        }
        /**
         * Skip persistors as well
         */
        if ($middleware instanceof ListSessionPersistor) {
            return $this->provide($context);
        }
        if ($middleware instanceof ListSessionProviderMiddleware) {
            return $middleware->provide($context, $this);
        }
        if ($middleware instanceof ListSessionProvider) {
            return $middleware->provide($context);
        }
        throw new RuntimeException(sprintf('Invalid middleware queue entry: %s. Middleware must either be callable or implement %s.', get_class($middleware), ListSessionProviderMiddleware::class));
    }
}
