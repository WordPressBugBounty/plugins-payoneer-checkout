<?php

declare(strict_types=1);

use Dhii\Services\Factories\Alias;
use Dhii\Services\Factories\Constructor;
use Inpsyde\PayoneerForWoocommerce\AssetCustomizer\CachingFileTemplateAssetProcessorFactory;

return
    /**
     * @return array<string, callable>
     * @psalm-return array<string, callable>
     */
    static function (): array {
        return [
            'assets.caching_file_template.processor.factory.hasher' =>
                new Alias('assets.hasher'),

            'assets.caching_file_template.processor.factory.file_stream_factory' =>
                new Alias('assets.file_stream.factory'),

            'assets.caching_file_template.processor.factory.path_template_factory' =>
                new Alias('assets.path_template.factory'),

            'assets.caching_file_template.processor.factory' =>
                new Constructor(CachingFileTemplateAssetProcessorFactory::class, [
                    'assets.caching_file_template.processor.factory.hasher',
                    'assets.caching_file_template.processor.factory.file_stream_factory',
                    'assets.caching_file_template.processor.factory.path_template_factory',
                ]),
        ];
    };
