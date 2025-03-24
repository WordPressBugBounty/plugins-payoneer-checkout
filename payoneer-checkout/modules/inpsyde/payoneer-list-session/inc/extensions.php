<?php

declare (strict_types=1);
namespace Syde\Vendor;

use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\Factory\RedirectInjectingListFactory;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\ListSession\ListFactoryInterface;
use Syde\Vendor\Psr\Container\ContainerInterface;
return static function (): array {
    return ['payoneer_sdk.list_factory' => static function (ListFactoryInterface $previous): ListFactoryInterface {
        return new RedirectInjectingListFactory($previous);
    }, 'list_session.middlewares' => static function (array $middlewares, ContainerInterface $container): array {
        /**
         * If it is safe to boot a LIST,
         * we can add additional middleware relevant to the frontend UX
         */
        if (!$container->get('list_session.can_try_create_list')) {
            return $middlewares;
        }
        /**
         * The order is important here!
         */
        \array_unshift($middlewares, $container->get('list_session.middlewares.wc-session'));
        \array_unshift($middlewares, $container->get('list_session.middlewares.wc-session-update'));
        /**
         * Prepend the validating middleware as the last step.
         * We do not want anything to execute before it.
         */
        \array_unshift($middlewares, $container->get('list_session.middlewares.validating'));
        return $middlewares;
    }];
};
