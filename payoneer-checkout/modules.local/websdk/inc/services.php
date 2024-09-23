<?php

declare(strict_types=1);

use Dhii\Services\Factories\StringService;
use Dhii\Services\Factory;

return static function (): array {
    return [
        'websdk.assets.css.websdk.url' => new Factory([
            'websdk.main_plugin_file',
            'websdk.assets.path.css',
            'websdk.assets.css.suffix',
        ], static function (
            string $mainPluginFile,
            string $cssPath,
            string $cssSuffix
        ): string {

            $url = plugins_url(
                $cssPath . 'op-payment-widget-v3' . $cssSuffix,
                $mainPluginFile
            );

            return $url;
        }),

        'websdk.assets.js.websdk.url' => new Factory(
            [
            'websdk.main_plugin_file',
            'websdk.assets.path.js',
            'websdk.assets.js.suffix',
            ],
            static function (
                string $mainPluginFile,
                string $jsPath,
                string $jsSuffix
            ): string {
                $url = plugins_url(
                    $jsPath . 'op-payment-widget-v3' . $jsSuffix,
                    $mainPluginFile
                );

                return $url;
            }
        ),

        'websdk.assets.css.widget.url' => new Factory([
            'websdk.main_plugin_file',
            'websdk.assets.path.css',
            'websdk.assets.css.suffix',
        ], static function (
            string $pluginMainFile,
            string $cssPath,
            string $cssSuffix
        ): string {

            return plugins_url(
                $cssPath . 'widget' . $cssSuffix,
                $pluginMainFile
            );
        }),

        'websdk.path.assets' => new Factory(
            [
                'websdk.local_modules_directory_name',
            ],
            static function (
                string $modulesDirectoryRelativePath
            ): string {
                $moduleRelativePath = sprintf(
                    '%1$s/%2$s',
                    $modulesDirectoryRelativePath,
                    'websdk'
                );

                return sprintf('%1$s/assets', $moduleRelativePath);
            }
        ),

        'websdk.assets.path.css' => new StringService(
            '{0}/css/',
            ['websdk.path.assets']
        ),

        'websdk.assets.path.js' => new StringService(
            '{0}/js/',
            ['websdk.path.assets']
        ),

        'websdk.assets.js.suffix' => new Factory([
            'wp.is_debug',
        ], static function (
            bool $isDebug
        ): string {
            return $isDebug ? '.js' : '.min.js';
        }),

        'websdk.assets.css.suffix' => new Factory([
            'wp.is_debug',
        ], static function (
            bool $isDebug
        ): string {
            return $isDebug ? '.css' : '.min.css';
        }),
    ];
};
