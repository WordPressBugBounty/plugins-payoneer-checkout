<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\AssetCustomizer;

use Inpsyde\PayoneerForWoocommerce\Cache\FileCacheInterface;
use Inpsyde\PayoneerForWoocommerce\Filesystem\PathResolverInterface;
use Inpsyde\PayoneerForWoocommerce\Filesystem\UrlResolverInterface;

/**
 * Something that can create an asset processor simply by specifying
 * dependencies relevant to paths.
 */
interface CachingFileTemplateAssetProcessorFactoryInterface
{
    /**
     * Creates a new asset processor.
     *
     * @param PathResolverInterface $pathResolver Resolves asset paths to file paths.
     * @param UrlResolverInterface $urlResolver Resolves file paths to asset URLs.
     * @param FileCacheInterface $fileCache Caches data to files.
     *
     * @return AssetProcessorInterface The new asset processor.
     */
    public function createFileAssetProcessor(
        PathResolverInterface $pathResolver,
        UrlResolverInterface $urlResolver,
        FileCacheInterface $fileCache
    ): AssetProcessorInterface;
}
