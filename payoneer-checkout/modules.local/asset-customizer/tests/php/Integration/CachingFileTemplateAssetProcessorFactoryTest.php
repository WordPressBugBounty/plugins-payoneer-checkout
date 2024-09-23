<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\AssetCustomizer\Tests\Integration;

use Inpsyde\PayoneerForWoocommerce\AssetCustomizer\AssetCustomizerModule;
use Inpsyde\PayoneerForWoocommerce\AssetCustomizer\AssetProcessorInterface;
use Inpsyde\PayoneerForWoocommerce\AssetCustomizer\CachingFileTemplateAssetProcessorFactory as Subject;
use Inpsyde\PayoneerForWoocommerce\AssetCustomizer\CachingFileTemplateAssetProcessorFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\Cache\BaseDirFilePathResolverFactory;
use Inpsyde\PayoneerForWoocommerce\Cache\BaseDirFilePathResolverFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\Cache\BaseDirHashingCacheFilePathResolver;
use Inpsyde\PayoneerForWoocommerce\Cache\CacheFilePathResolverInterface;
use Inpsyde\PayoneerForWoocommerce\Cache\CacheModule;
use Inpsyde\PayoneerForWoocommerce\Cache\FileExistsFileValidator;
use Inpsyde\PayoneerForWoocommerce\Cache\CacheFileValidatorInterface;
use Inpsyde\PayoneerForWoocommerce\Cache\ExtensionPreservingCacheFilePathResolver;
use Inpsyde\PayoneerForWoocommerce\Cache\BaseDirFileCacheFactory;
use Inpsyde\PayoneerForWoocommerce\Cache\FileCacheFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\Cache\KeyHashPrependingCacheFilePathResolver;
use Inpsyde\PayoneerForWoocommerce\Core\CoreModule;
use Inpsyde\PayoneerForWoocommerce\Filesystem\FilesystemModule;
use Inpsyde\PayoneerForWoocommerce\Filesystem\HashContextFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\Filesystem\NativeHashContextFactory;
use Inpsyde\PayoneerForWoocommerce\Filesystem\PathResolverWrappingUrlResolver;
use Inpsyde\PayoneerForWoocommerce\Filesystem\StreamingFileSaver;
use Inpsyde\PayoneerForWoocommerce\Filesystem\StringStreamFactory;
use Inpsyde\PayoneerForWoocommerce\Filesystem\FileSaverInterface;
use Inpsyde\PayoneerForWoocommerce\Filesystem\NativeHasher;
use Inpsyde\PayoneerForWoocommerce\Filesystem\PathResolverInterface;
use Inpsyde\PayoneerForWoocommerce\Filesystem\UrlResolverInterface;
use Inpsyde\PayoneerForWoocommerce\Filesystem\FileStreamFactory;
use Inpsyde\PayoneerForWoocommerce\Filesystem\FileStreamFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\Filesystem\HasherInterface;
use Inpsyde\PayoneerForWoocommerce\Filesystem\PrefixMatchingPathResolver;
use Inpsyde\PayoneerForWoocommerce\Filesystem\StringStreamFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\Filesystem\UriFactory;
use Inpsyde\PayoneerForWoocommerce\Template\PathTemplateFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\Template\PathTokenTemplateFactory;
use Inpsyde\PayoneerForWoocommerce\Template\StreamingPlaceholderTemplateFactory;
use Inpsyde\PayoneerForWoocommerce\Template\StreamTemplateFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\Template\TemplateModule;
use Inpsyde\PayoneerForWoocommerce\Tests\Integration\AbstractApplicationTestCase;
use Inpsyde\PayoneerForWoocommerce\Tests\Integration\AbstractIntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use org\bovigo\vfs\vfsStream;
use Psr\Http\Message\UriFactoryInterface;
use RuntimeException;

class CachingFileTemplateAssetProcessorFactoryTest extends AbstractApplicationTestCase
{
    public function testAssetProcessorService(): void
    {
        $root = vfsStream::setup('app', '0744', [
            'assets' => [
                'generated' => [
                ],
            ]
        ]);

        $rootPath = $root->url();
        $generatedRelativePath = 'assets/generated';
        $generatedPath = "{$rootPath}/{$generatedRelativePath}";
        $generatedUrl = PAYONEER_PLUGIN_DIR_URL . "/{$generatedRelativePath}";
        $this->injectService(
            'core.assets.generated.path',
            static function () use ($generatedPath): string {
                return $generatedPath;
            }
        );
        $this->injectExtension(
            'core.url_resolver.mappings',
            static function (array $prev) use ($generatedPath, $generatedUrl): array {
                $prev[$generatedPath] = $generatedUrl;

                return $prev;
            }
        );
        $package = $this->createPackage();

        $assetProcessor = $package->container()->get('core.css_file_processor');
        $this->assertInstanceOf(AssetProcessorInterface::class, $assetProcessor);

        $assetPath = 'core/assets/templates/checkout-widget.css';
        $processedStamp = uniqid('processed');
        $assetOptions = [
            'background-color' => 'blue',
            // WARNING: DO NOT DO THIS IN PRODUCTION!!
            // Dynamic values in processing options will cause the hash key
            // to change, and the asset will be processed _ON EVERY RUN_.
            'processed-stamp' => "/*{$processedStamp}*/",
        ];
        $assetUrl = $assetProcessor->process($assetPath, $assetOptions);
        $delim = '!';
        $urlExpr = $delim . preg_quote(PAYONEER_PLUGIN_DIR_URL, $delim) . '/assets/generated/([\w\d]+\.[\w\d]+.css)' . $delim;
        $assetUrlString = (string)$assetUrl;
        $this->assertRegExp($urlExpr, $assetUrlString, 'The resulting URL looks wrong');

        $assetCacheFileName = (
            /**
             * Retrieves the RegEx match of the group at the specified $matchIndex,
             * of the first match of $expr in $source
             *
             * @throws RuntimeException If problem retrieving.
             */
            function (string $expr, string $source, int $matchIndex): string {
                if (!preg_match($expr, $source, $matches)) {
                    throw new RuntimeException(sprintf('Could not retrieve first match from "%1$s"', $source));
                }

                if (!isset($matches[$matchIndex])) {
                    throw new RuntimeException(sprintf('Could not retrieve match "%1$d"', $matchIndex));
                }

                return $matches[$matchIndex];
            })($urlExpr, $assetUrlString, 1);

        $assetCacheFilePath = "{$generatedPath}/{$assetCacheFileName}";
        $assetCacheContent = file_get_contents($assetCacheFilePath);
        $this->assertRegExp('!' . preg_quote($processedStamp, '!') . '!', $assetCacheContent);
    }

    /**
     * This thin adaptor layer avoids any but the simplest refactoring of the former data provider.
     */
    public function testProcess(): void
    {
        $dataSet = $this->getCustomizationParametersDataSet();
        call_user_func_array([$this, 'doTestProcess'], $dataSet);
    }
    public function doTestProcess(
        string $assetPath,
        array $options,
        string $assetsUrl,
        string $url,
        string $moduleName,
        string $assetName,
        string $cacheDirName,
        string $cacheDir,
        string $cacheSegmentSeparator,
        string $templateDirName,
        string $webRoot,
        string $modulesRoot,
        string $assetsDirName,
        string $templateTokenStart,
        string $templateTokenEnd,
        ?string $templateContextDefaultValue,
        string $templateTokenValue,
        string $templateFormat
    ): void {

        {
            $sourceTemplateDirPath = "$moduleName/$templateDirName";
            $destinationDir = "$modulesRoot/$moduleName/$assetsDirName/$templateDirName";
            $hashAlgo = 'sha1';
            $hashMaxBufferSize = 1 * 1000; // 1MB
            $fileSaverMaxBufferSize = 1 * 1000; // 1MB
            $defaultUrlProtocol = 'file';

            $pathResolver = $this->createPathResolver([
                "$sourceTemplateDirPath/" => "$destinationDir/",
            ], $webRoot);
            $uriFactory = $this->createUriFactory();
            $urlPathResolver = $this->createPathResolver([
                "$cacheDir/" => 'cache/',
            ], $assetsUrl);
            $urlResolver = $this->createUrlResolver($urlPathResolver, $uriFactory, $defaultUrlProtocol);
            $hashContextFactory = $this->createHashContextFactory($hashAlgo);
            $fileHasher = $this->createHasher($hashContextFactory, $hashMaxBufferSize);
            $fileStreamFactory = $this->createFileStreamFactory();
            $stringStreamFactory = $this->createStringStreamFactory();
            $cacheFilePathResolverFactory = $this->createCacheFilePathResolverFactory(
                $fileHasher,
                $cacheSegmentSeparator,
                $stringStreamFactory
            );
            $cacheFileValidator = $this->createCacheFileValidator();
            $fileSaver = $this->createFileSaver($fileStreamFactory, $fileSaverMaxBufferSize);
            $fileCacheFactory = $this->createFileCacheFactory(
                $cacheFilePathResolverFactory,
                $cacheFileValidator,
                $fileSaver
            );
            $fileCache = $fileCacheFactory->createFileCacheFromBaseDir($cacheDir);
            $streamTemplateFactory = $this->createStreamTemplateFactory(
                $templateTokenStart,
                $templateTokenEnd,
                $templateContextDefaultValue,
                $stringStreamFactory
            );
            $templateFactory = $this->createPathTemplateFactory(
                $fileStreamFactory,
                $streamTemplateFactory
            );

            $subject = $this->createSubject(
                $fileHasher,
                $fileStreamFactory,
                $templateFactory
            );
            $processor = $subject->createFileAssetProcessor(
                $pathResolver,
                $urlResolver,
                $fileCache
            );
        }

        {
            $result = $processor->process($assetPath, $options);
        }

        {
            $resultString = (string) $result;
            $this->assertRegExp($url, $resultString, 'Customized CSS URL is wrong');

            $matchResult = preg_match($url, $resultString, $matches);
            $this->assertNotFalse($matchResult, 'Could not extract URL');
            $resultFileName = $matches[1];
            $resultFilePath = "{$cacheDir}/{$resultFileName}";
            $resultFileContents = file_get_contents($resultFilePath);
            $this->assertEquals(
                sprintf($templateFormat, $templateTokenValue),
                $resultFileContents,
                'Customized CSS contents are wrong'
            );
        }
    }

    public function getCustomizationParametersDataSet(): array
    {
        $appRootName = 'app';
        $modulesRootName = 'modules.local';
        $modulesRoot = $modulesRootName;
        $moduleName = 'moduleA';
        $assetsDirName = 'assets';
        $assetExt = 'css';
        $assetName = sprintf('%1$s.%2$s', 'style', $assetExt);
        $templateTokenStart = '{{';
        $templateTokenEnd = '}}';
        $templateDefaultContextValue = null;
        $templateTokenName = 'username';
        $templateTokenValue = uniqid('token-value');
        $templateFormat = 'Hello, %1$s!';
        $templateContent = sprintf($templateFormat, "{$templateTokenStart}{$templateTokenName}{$templateTokenEnd}");
        $templateDirName = 'templates';
        $vfsRootDir = vfsStream::setup($appRootName, null, [
            $modulesRootName => [
                $moduleName => [
                    $assetsDirName => [
                        $templateDirName => [
                            $assetName => $templateContent,
                        ],
                    ],
                ],
            ],
        ]);
        $webRoot = $vfsRootDir->url();
        $cacheDirName = 'cache';
        $cacheDir = "{$webRoot}/{$cacheDirName}";
        $cacheSegmentSeparator = '.';
        $rootDomain = 'example.com';
        $rootUrl = "http://$rootDomain";
        $assetsUrl = "{$rootUrl}/{$assetsDirName}";

        $delim = '!';
        return [
            // Asset path
            sprintf('%1$s/%2$s/%3$s', $moduleName, $templateDirName, $assetName),
            // Processing options
            [
                $templateTokenName => $templateTokenValue,
            ],
            // Assets URL
            $assetsUrl,
            // Expected URL RegEx
            '!' .
                '^' .
                preg_quote("$assetsUrl/$cacheDirName/", $delim) .
                '(' .
                    '[\w\d]+' .
                    preg_quote($cacheSegmentSeparator, $delim) .
                    '[\w\d]+' .
                    preg_quote(".{$assetExt}", $delim) .
                ')' .
            '!',
            // Module name
            $moduleName,
            // Asset name
            $assetName,
            // Cache dir name
            $cacheDirName,
            // Cache root dir
            $cacheDir,
            // Cache segment separator
            $cacheSegmentSeparator,
            // Template dir name
            $templateDirName,
            // Web root
            $webRoot,
            // Modules root
            $modulesRoot,
            // Name of assets directory in every module
            $assetsDirName,
            // Token start
            $templateTokenStart,
            // Token end
            $templateTokenEnd,
            // Default template context value
            $templateDefaultContextValue,
            // The value of the token replace into the template
            $templateTokenValue,
            // The sprintf() format of the template contents
            $templateFormat,
        ];
    }

    /**
     * @return CachingFileTemplateAssetProcessorFactoryInterface&MockObject
     */
    protected function createSubject(
        HasherInterface $fileHasher,
        FileStreamFactoryInterface $fileStreamFactory,
        PathTemplateFactoryInterface $pathTemplateFactory
    ): CachingFileTemplateAssetProcessorFactoryInterface {
        $mock = $this->getMockBuilder(Subject::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([
                $fileHasher,
                $fileStreamFactory,
                $pathTemplateFactory
            ])
            ->getMock();

        return $mock;
    }

    /**
     * @return PathResolverInterface&MockObject
     */
    protected function createPathResolver(array $prefixMap, string $path): PathResolverInterface
    {
        $mock = $this->getMockBuilder(PrefixMatchingPathResolver::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([$prefixMap, $path])
            ->getMock();

        return $mock;
    }

    /**
     * @retyrn UrlResolverInterface&MockObject
     */
    protected function createUrlResolver(
        PathResolverInterface $pathResolver,
        UriFactoryInterface $uriFactory,
        string $defaultProtocol
    ): UrlResolverInterface {

        $mock = $this->getMockBuilder(PathResolverWrappingUrlResolver::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([$pathResolver, $uriFactory, $defaultProtocol])
            ->getMock();

        return $mock;
    }

    /**
     * @return HasherInterface&MockObject
     */
    protected function createHasher(HashContextFactoryInterface $hashContextFactory, int $maxBufferSize): HasherInterface
    {
        $mock = $this->getMockBuilder(NativeHasher::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([$hashContextFactory, $maxBufferSize])
            ->getMock();

        return $mock;
    }

    /**
     * @return FileStreamFactoryInterface&MockObject
     */
    protected function createFileStreamFactory(): FileStreamFactoryInterface
    {
        $mock = $this->getMockBuilder(FileStreamFactory::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([])
            ->getMock();

        return $mock;
    }

    /**
     * @return CacheFilePathResolverInterface&MockObject
     */
    protected function createCacheFilePathResolver(
        string $cacheDir,
        HasherInterface $hasher,
        string $segmentSeparator,
        StringStreamFactoryInterface $stringStreamFactory
    ): CacheFilePathResolverInterface {
        $pathResolver = $this->getMockBuilder(BaseDirHashingCacheFilePathResolver::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([$cacheDir, $hasher, $segmentSeparator, $stringStreamFactory])
            ->getMock();
        $keyHashPrepender = $this->getMockBuilder(KeyHashPrependingCacheFilePathResolver::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([$pathResolver, $hasher, $segmentSeparator, $stringStreamFactory])
            ->getMock();
        $extensionPreserver = $this->getMockBuilder(ExtensionPreservingCacheFilePathResolver::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([$keyHashPrepender])
            ->getMock();

        $mock = $extensionPreserver;

        return $mock;
    }

    /**
     * @return CacheFileValidatorInterface&MockObject
     */
    protected function createCacheFileValidator(): CacheFileValidatorInterface
    {
        $mock = $this->getMockBuilder(FileExistsFileValidator::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([])
            ->getMock();

        return $mock;
    }

    /**
     * @return FileSaverInterface&MockObject
     */
    protected function createFileSaver(
        FileStreamFactoryInterface $fileStreamFactory,
        int $maxBufferSize
    ): FileSaverInterface {

        $mock = $this->getMockBuilder(StreamingFileSaver::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([$fileStreamFactory, $maxBufferSize])
            ->getMock();

        return $mock;
    }

    /**
     * @return StringStreamFactoryInterface&MockObject
     */
    protected function createStringStreamFactory(): StringStreamFactoryInterface
    {
        $mock = $this->getMockBuilder(StringStreamFactory::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([])
            ->getMock();

        return $mock;
    }

    /**
     * @return PathTemplateFactoryInterface&MockObject
     */
    protected function createPathTemplateFactory(
        FileStreamFactoryInterface $fileStreamFactory,
        StreamTemplateFactoryInterface $streamTemplateFactory
    ): PathTemplateFactoryInterface {
        $mock = $this->getMockBuilder(PathTokenTemplateFactory::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([
                $fileStreamFactory,
                $streamTemplateFactory,
            ])
            ->getMock();

        return $mock;
    }

    /**
     * @return StreamTemplateFactoryInterface&MockObject
     */
    protected function createStreamTemplateFactory(
        string $templateTokenStart,
        string $templateTokenEnd,
        ?string $templateContextDefaultValue,
        StringStreamFactoryInterface $stringStreamFactory
    ): StreamTemplateFactoryInterface {
        $mock = $this->getMockBuilder(StreamingPlaceholderTemplateFactory::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([
                $templateTokenStart,
                $templateTokenEnd,
                $templateContextDefaultValue,
                $stringStreamFactory,
            ])
            ->getMock();

        return $mock;
    }

    /**
     * @return UriFactoryInterface&MockObject
     */
    protected function createUriFactory(): UriFactoryInterface
    {
        $mock = $this->getMockBuilder(UriFactory::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([])
            ->getMock();

        return $mock;
    }

    /**
     * @return HashContextFactoryInterface&MockObject
     */
    protected function createHashContextFactory(string $algo): HashContextFactoryInterface
    {
        $mock = $this->getMockBuilder(NativeHashContextFactory::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([$algo])
            ->getMock();

        return $mock;
    }

    /**
     * @return BaseDirFilePathResolverFactoryInterface&MockObject
     */
    protected function createCacheFilePathResolverFactory(
        HasherInterface $hasher,
        string $segmentSeparator,
        StringStreamFactoryInterface $stringStreamFactory
    ): BaseDirFilePathResolverFactoryInterface {

        $mock = $this->getMockBuilder(BaseDirFilePathResolverFactory::class)
                     ->enableProxyingToOriginalMethods()
                     ->setConstructorArgs([$hasher, $segmentSeparator, $stringStreamFactory])
                     ->getMock();

        return $mock;
    }

    /**
     * @return FileCacheFactoryInterface&MockObject
     */
    protected function createFileCacheFactory(
        BaseDirFilePathResolverFactoryInterface $filePathResolverFactory,
        CacheFileValidatorInterface $fileValidator,
        FileSaverInterface $fileSaver
    ): FileCacheFactoryInterface {

        $mock = $this->getMockBuilder(BaseDirFileCacheFactory::class)
                     ->enableProxyingToOriginalMethods()
                     ->setConstructorArgs([$filePathResolverFactory, $fileValidator, $fileSaver])
                     ->getMock();

        return $mock;
    }
}
