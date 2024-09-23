<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PageDetector\Tests\Integration;


use Inpsyde\PayoneerForWoocommerce\PageDetector\UriPageDetector;
use PHPUnit\Framework\TestCase;

class UriPageDetectorTest extends TestCase
{
    /**
     * @dataProvider defaultTestData
     *
     * @param string $currentUrl
     * @param array $basePath
     * @param string $testUrl
     *
     * @return void
     */
    public function testIsPage(
        string $currentUrl,
        array $basePath,
        array $criteria,
        bool $expectedResult
    ) {
        $sut    = new UriPageDetector($currentUrl, $basePath);
        $result = $sut->isPage($criteria);
        $this->assertSame($expectedResult, $result, 'Criteria did not match configured page');
    }

    public function defaultTestData()
    {
        yield 'main site' => [
            'https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&section=payoneer-checkout',
            [],
            [
                'path'  => 'wp-admin/admin.php',
                'query' =>
                    [
                        'page'    => 'wc-settings',
                        'tab'     => 'checkout',
                        'section' => 'payoneer-checkout',
                    ],
            ],
            true
        ];

        yield 'sub site' => [
            'https://example.com/foo/wp-admin/admin.php?page=wc-settings&tab=checkout&section=payoneer-checkout',
            ['foo'],
            [
                'path'  => 'wp-admin/admin.php',
                'query' =>
                    [
                        'page'    => 'wc-settings',
                        'tab'     => 'checkout',
                        'section' => 'payoneer-checkout',
                    ],
            ],
            true
        ];

        yield 'sub site omitting path' => [
            'https://example.com/foo/wp-admin/admin.php?page=wc-settings&tab=checkout&section=payoneer-checkout',
            [],
            [
                'path'  => 'wp-admin/admin.php',
                'query' =>
                    [
                        'page'    => 'wc-settings',
                        'tab'     => 'checkout',
                        'section' => 'payoneer-checkout',
                    ],
            ],
            false
        ];
    }
}
