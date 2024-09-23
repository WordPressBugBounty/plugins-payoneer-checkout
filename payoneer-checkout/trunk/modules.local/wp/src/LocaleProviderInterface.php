<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Wp;

/**
 * Service able to provide system locale.
 */
interface LocaleProviderInterface
{
    /**
     * Return locale string.
     *
     * @return string
     */
    public function provideLocale(): string;
}
