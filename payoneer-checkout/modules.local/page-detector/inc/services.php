<?php

declare(strict_types=1);

use Dhii\Services\Factories\Constructor;
use Inpsyde\PayoneerForWoocommerce\PageDetector\UrlPageDetectorFactory;

return
    /**
     * @return array<string, callable>
     * @psalm-return array<string, callable>
     */
    static function (): array {
        return [
            'http.page_detector.factory' =>
                new Constructor(UrlPageDetectorFactory::class, []),
        ];
    };
