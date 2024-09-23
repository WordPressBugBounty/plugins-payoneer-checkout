<?php

declare(strict_types=1);

use Dhii\Services\Factories\Alias;
use Dhii\Services\Factories\StringService;
use Dhii\Services\Factories\Value;
use Dhii\Services\Factory;
use Inpsyde\Assets\Asset;
use Inpsyde\Assets\Style;
use Inpsyde\PayoneerForWoocommerce\AdminBanner\AdminBannerRenderer;
use Inpsyde\PayoneerForWoocommerce\AdminBanner\AdminBannerRendererInterface;
use Psr\Http\Message\UriInterface;

return
    /**
     * @return array<string, callable>
     * @psalm-return array<string, callable>
     */
    static function (): array {
        return [
            'admin_banner.assets.css.banner' =>
                new Factory([
                    'admin_banner.assets.css.banner.handle',
                    'admin_banner.assets.css.banner.url',
                    'admin_banner.assets.can_enqueue',
                ], static function (
                    string $handle,
                    string $url,
                    callable $canEnqueue
                ): Asset {
                    /** @psalm-var callable():bool $canEnqueue */
                    $style = new Style($handle, $url, Asset::BACKEND);
                    $style->canEnqueue($canEnqueue);

                    return $style;
                }),
            'admin_banner.assets.css.banner.handle' =>
                new Value('payoneer_admin_banner'),

            'admin_banner.assets.css.banner.url' =>
                new Factory([
                    'admin_banner.assets.css.banner.path',
                    'admin_banner.main_plugin_file',
                ], static function (
                    string $cssPath,
                    string $mainPluginFile
                ): string {
                    return plugins_url(
                        sprintf('%1$s/admin_banner.css', $cssPath),
                        $mainPluginFile
                    );
                }),

            'admin_banner.assets.css.banner.path' =>
                new StringService(
                    '{0}/css',
                    [
                        'admin_banner.assets.path',
                    ]
                ),

            'admin_banner.assets.img.phone_mock.url' =>
                new Factory([
                    'admin_banner.assets.img.path',
                    'admin_banner.main_plugin_file',
                ], static function (
                    string $cssPath,
                    string $mainPluginFile
                ): string {
                    return plugins_url(
                        sprintf('%1$s/phone_mock.png', $cssPath),
                        $mainPluginFile
                    );
                }),

            'admin_banner.assets.img.path' =>
                new StringService(
                    '{0}/img',
                    [
                        'admin_banner.assets.path',
                    ]
                ),

            'admin_banner.assets.path' =>
                new StringService('{0}/admin-banner/assets', [
                    'admin_banner.local_modules_directory_name',
                ]),

            'admin_banner.should_display' =>
                new Factory([
                    'admin_banner.settings_option_key',
                ], static function (string $settingsOptionName): bool {
                    return ! get_option($settingsOptionName) && is_admin();
                }),

            'admin_banner.assets.can_enqueue' =>
                new Factory(['admin_banner.should_display'], static function (
                    bool $shouldDisplay
                ): callable {
                    return static function () use ($shouldDisplay): bool {
                        return $shouldDisplay;
                    };
                }),

            'admin_banner.banner_id' =>
                new Value('payoneer_admin_banner'),

            'admin_banner.banner_renderer' =>
                new Factory([
                    'admin_banner.banner_id',
                    'admin_banner.assets.img.phone_mock.url',
                    'admin_banner.register_url',
                    'admin_banner.configure_url',
                ], static function (
                    string $bannerId,
                    string $phoneMockUrl,
                    string $registerUrl,
                    UriInterface $configureUrl
                ): AdminBannerRendererInterface {
                    return new AdminBannerRenderer(
                        $bannerId,
                        $phoneMockUrl,
                        $registerUrl,
                        (string) $configureUrl
                    );
                }),

            'admin_banner.settings_option_key' =>
                new Alias('core.settings_option_key'),

            'admin_banner.register_url' =>
                new Value('https://www.payoneer.com/solutions/checkout/woocommerce-integration/?utm_source=Woo+plugin&utm_medium=referral&utm_campaign=WooCommerce+top+banner#form-modal-trigger'),
        ];
    };
