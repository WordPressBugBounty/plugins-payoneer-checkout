<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\AssetCustomizer;

use Inpsyde\PayoneerForWoocommerce\Cache\FileCacheInterface;
use Inpsyde\PayoneerForWoocommerce\Filesystem\FileStreamFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\Filesystem\HasherInterface;
use Inpsyde\PayoneerForWoocommerce\Filesystem\PathResolverInterface;
use Inpsyde\PayoneerForWoocommerce\Filesystem\UrlResolverInterface;
use Inpsyde\PayoneerForWoocommerce\Template\PathTemplateFactoryInterface;

/**
 * Creates a file-caching, file template-based asset processor.
 */
class CachingFileTemplateAssetProcessorFactory implements CachingFileTemplateAssetProcessorFactoryInterface
{
    /**
     * @var HasherInterface
     */
    protected $hasher;
    /**
     * @var FileStreamFactoryInterface
     */
    protected $fileStreamFactory;
    /**
     * @var PathTemplateFactoryInterface
     */
    protected $pathTemplateFactory;

    public function __construct(
        HasherInterface $hasher,
        FileStreamFactoryInterface $fileStreamFactory,
        PathTemplateFactoryInterface $pathTemplateFactory
    ) {

        $this->hasher = $hasher;
        $this->fileStreamFactory = $fileStreamFactory;
        $this->pathTemplateFactory = $pathTemplateFactory;
    }

    /**
     * @inheritDoc
     */
    public function createFileAssetProcessor(
        PathResolverInterface $pathResolver,
        UrlResolverInterface $urlResolver,
        FileCacheInterface $fileCache
    ): AssetProcessorInterface {

        return new FileTemplateAssetProcessor(
            $pathResolver,
            $urlResolver,
            $fileCache,
            $this->hasher,
            $this->fileStreamFactory,
            $this->pathTemplateFactory
        );
    }
}
